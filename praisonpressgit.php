<?php
/**
 * Plugin Name: PraisonAI Git Posts
 * Description: Load WordPress content from files (Markdown, JSON, YAML) without database writes, with Git-based version control
 * Version: 1.0.6
 * Author: MervinPraison
 * Author URI: https://mer.vin
 * License: GPL v2 or later
 * Text Domain: praison-file-content-git
 */

defined('ABSPATH') or die('Direct access not allowed');

// Define constants
define('PRAISON_VERSION', '1.0.6');
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
    // Create content directory at root level (independent of WordPress)
    $directories = [
        PRAISON_CONTENT_DIR,
        PRAISON_CONTENT_DIR . '/posts',
        PRAISON_CONTENT_DIR . '/pages',
        PRAISON_CONTENT_DIR . '/lyrics',
        PRAISON_CONTENT_DIR . '/recipes',
        PRAISON_CONTENT_DIR . '/config',
    ];
    
    foreach ($directories as $dir) {
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '/.gitkeep', '');
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
