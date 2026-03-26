<?php
/**
 * Plugin Name: PraisonAI Git Posts
 * Description: Load WordPress content from files (Markdown, JSON, YAML) without database writes, with Git-based version control
 * Version: 1.8.0
 * Author: MervinPraison
 * Author URI: https://mer.vin
 * License: GPL v2 or later
 * Text Domain: praison-file-content-git
 */

defined('ABSPATH') or die('Direct access not allowed');

// Define constants
define('PRAISON_VERSION', '1.7.0');
define('PRAISON_PLUGIN_DIR', __DIR__);
define('PRAISON_PLUGIN_URL', trailingslashit(plugins_url('', __FILE__)));

// Content directory - Hybrid approach for maximum flexibility:
// 1. Can be overridden in wp-config.php: define('PRAISON_CONTENT_DIR', '/custom/path');
// 2. Can be filtered: add_filter('praison_content_dir', function($dir) { return '/custom/path'; });
// 3. Defaults to: wp-content/uploads/praison-content (WordPress standard location)
if (!defined('PRAISON_CONTENT_DIR')) {
    $praison_upload_dir = wp_upload_dir();
    $praison_default_content_dir = $praison_upload_dir['basedir'] . '/praison-content';
    define('PRAISON_CONTENT_DIR', apply_filters('praison_content_dir', $praison_default_content_dir));
}

define('PRAISON_CACHE_GROUP', 'praisonpress');

// Simple autoloader
spl_autoload_register(function ($class) {
    if (strpos($class, 'PraisonPress\\') !== 0) {
        return;
    }
    
    // Convert namespace to file path
    $file = PRAISON_PLUGIN_DIR . '/src/' . str_replace(['PraisonPress\\', '\\'], ['', '/'], $class) . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    }
});

// Bootstrap the plugin
add_action('plugins_loaded', function() {
    if (class_exists('PraisonPress\\Core\\Bootstrap')) {
        PraisonPress\Core\Bootstrap::init();
    }
}, 1);

// Register WP-CLI command: wp praison index [--type=<type>] [--verbose]
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('praison index', 'PraisonPress\\CLI\\IndexCommand');
}

/**
 * Plugin activation hook
 * Auto-create the Submissions page and database table
 */
function praisonpress_activate() {
    // Create submissions tracking table
    require_once PRAISON_PLUGIN_DIR . '/src/Database/SubmissionsTable.php';
    $submissionsTable = new \PraisonPress\Database\SubmissionsTable();
    $submissionsTable->createTable();
    
    // Check if Submissions page already exists
    $existing_page = get_page_by_path('submissions');
    
    if (!$existing_page) {
        // Create the Submissions page
        $page_data = array(
            'post_title'    => 'Submissions',
            'post_content'  => '[praisonpress_my_submissions]',
            'post_status'   => 'publish',
            'post_type'     => 'page',
            'post_author'   => 1,
            'post_name'     => 'submissions'
        );
        
        wp_insert_post($page_data);
    }
}
register_activation_hook(__FILE__, 'praisonpress_activate');

// Installation - create directories
register_activation_hook(__FILE__, 'praison_install');

function praison_install() {
    // Create content directory structure
    $directories = [
        PRAISON_CONTENT_DIR,
        PRAISON_CONTENT_DIR . '/posts',
        PRAISON_CONTENT_DIR . '/pages',
        PRAISON_CONTENT_DIR . '/config',
    ];
    
    foreach ($directories as $dir) {
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
    }
    
    // Create a sample post so users can see it working immediately
    $sample_file = PRAISON_CONTENT_DIR . '/posts/hello-from-praisonpress.md';
    if (!file_exists($sample_file)) {
        $sample_content = "---\n"
            . "title: \"Hello from PraisonPress!\"\n"
            . "slug: \"hello-from-praisonpress\"\n"
            . "date: \"" . current_time('Y-m-d H:i:s') . "\"\n"
            . "status: \"publish\"\n"
            . "categories:\n"
            . "  - \"Getting Started\"\n"
            . "tags:\n"
            . "  - \"sample\"\n"
            . "  - \"praisonpress\"\n"
            . "excerpt: \"This is a sample post created by PraisonPress. Edit or delete this file, then rebuild the index.\"\n"
            . "---\n\n"
            . "# Welcome to PraisonPress! 🎉\n\n"
            . "This post is served from a **Markdown file** on the filesystem — no database required!\n\n"
            . "## How it works\n\n"
            . "1. Add `.md` files to subdirectories in your content folder\n"
            . "2. Each subdirectory becomes a custom post type\n"
            . "3. YAML front matter (between the `---` markers) defines the post metadata\n"
            . "4. Everything below the front matter is the post content in Markdown\n\n"
            . "## Next steps\n\n"
            . "- Go to **Settings → PraisonPress** to enable content delivery\n"
            . "- Add more Markdown files to the `posts/` directory\n"
            . "- Create new directories (e.g., `recipes/`, `tutorials/`) for custom post types\n"
            . "- Click **Rebuild Index** after adding new content\n\n"
            . "Feel free to edit or delete this sample file!\n";
        
        file_put_contents($sample_file, $sample_content);
    }
    
    // Generate _index.json synchronously for any existing content
    $content_dir = PRAISON_CONTENT_DIR;
    if (is_dir($content_dir)) {
        $dirs = @scandir($content_dir);
        if ($dirs) {
            foreach ($dirs as $d) {
                if ($d[0] === '.' || $d === 'config' || !is_dir("$content_dir/$d")) continue;
                $md_files = glob("$content_dir/$d/*.md");
                if (empty($md_files)) continue;
                
                $index = [];
                foreach ($md_files as $file) {
                    $raw = file_get_contents($file);
                    if ($raw === false) continue;
                    
                    // Quick frontmatter parse
                    $meta = [];
                    if (strpos($raw, '---') === 0) {
                        $parts = preg_split('/^---\s*$/m', $raw, 3);
                        if (count($parts) >= 3) {
                            $current_array = null;
                            foreach (explode("\n", trim($parts[1])) as $line) {
                                $line = rtrim($line);
                                if (empty(trim($line))) continue;
                                if (preg_match('/^\s+-\s+(.+)$/', $line, $m) && $current_array) {
                                    $meta[$current_array][] = trim($m[1], "\" '\t");
                                    continue;
                                }
                                $current_array = null;
                                if (preg_match('/^([a-zA-Z_-]+):\s*$/', $line, $m)) {
                                    $current_array = $m[1];
                                    $meta[$current_array] = [];
                                } elseif (preg_match('/^([a-zA-Z_-]+):\s*(.+)$/', $line, $m)) {
                                    $meta[trim($m[1])] = trim($m[2], "\" '\t");
                                }
                            }
                        }
                    }
                    
                    $slug = $meta['slug'] ?? pathinfo($file, PATHINFO_FILENAME);
                    $index[] = [
                        'file'       => basename($file),
                        'slug'       => $slug,
                        'title'      => $meta['title'] ?? ucwords(str_replace('-', ' ', $slug)),
                        'date'       => $meta['date'] ?? date('Y-m-d H:i:s', filemtime($file)),
                        'modified'   => date('Y-m-d H:i:s', filemtime($file)),
                        'status'     => $meta['status'] ?? 'publish',
                        'excerpt'    => $meta['excerpt'] ?? '',
                        'categories' => $meta['categories'] ?? [],
                        'tags'       => $meta['tags'] ?? [],
                    ];
                }
                
                file_put_contents("$content_dir/$d/_index.json", json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Helper functions for easy access
function praison_get_posts($args = []) {
    if (class_exists('PraisonPress\\Loaders\\PostLoader')) {
        $loader = new PraisonPress\Loaders\PostLoader();
        return $loader->getPosts($args);
    }
    return [];
}

function praison_clear_cache() {
    if (class_exists('PraisonPress\\Cache\\CacheManager')) {
        PraisonPress\Cache\CacheManager::clearAll();
    }
}

function praison_get_stats() {
    if (class_exists('PraisonPress\\Loaders\\PostLoader')) {
        $loader = new PraisonPress\Loaders\PostLoader();
        return $loader->getStats();
    }
    return [];
}
