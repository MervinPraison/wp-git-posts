<?php
namespace PraisonPress\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Settings Page — WordPress-native configuration for PraisonPress
 * 
 * Designed for any WordPress user — no CLI, terminal, or server access needed.
 * All settings are stored in wp_options.
 */
class SettingsPage {
    
    const OPTION_GROUP = 'praisonpress_settings';
    const OPTION_NAME  = 'praisonpress_options';
    
    /**
     * Register hooks
     */
    public function register() {
        add_action('admin_menu', [$this, 'addMenuPage'], 5);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('update_option_' . self::OPTION_NAME, [$this, 'onSettingsSaved'], 10, 2);
    }
    
    /**
     * Add settings page as the first submenu item
     */
    public function addMenuPage() {
        add_submenu_page(
            'praison-file-content-git',
            'Settings',
            'Settings',
            'manage_options',
            'praison-settings',
            [$this, 'renderPage']
        );
    }
    
    /**
     * Register settings using WordPress Settings API
     */
    public function registerSettings() {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            'sanitize_callback' => [$this, 'sanitizeOptions'],
            'default' => self::getDefaults(),
        ]);
        
        // ── Section: Getting Started ──
        add_settings_section(
            'praisonpress_quickstart',
            'Getting Started',
            [$this, 'renderQuickStart'],
            'praison-settings'
        );
        
        // ── Section: Content Delivery ──
        add_settings_section(
            'praisonpress_content',
            'Content Delivery',
            function() {
                echo '<p>When enabled, the plugin serves content from Markdown files instead of the WordPress database. '
                   . 'This lets you manage content as files — perfect for Git workflows, static site generation, or headless WordPress.</p>';
            },
            'praison-settings'
        );
        
        add_settings_field(
            'content_enabled',
            'Enable Headless Content',
            [$this, 'renderToggleField'],
            'praison-settings',
            'praisonpress_content',
            ['field' => 'content_enabled', 'description' => 'Serve content from Markdown files on the filesystem']
        );
        
        add_settings_field(
            'post_types',
            'Post Types',
            [$this, 'renderPostTypesField'],
            'praison-settings',
            'praisonpress_content',
            ['field' => 'post_types']
        );
        
        // ── Section: Performance ──
        add_settings_section(
            'praisonpress_performance',
            'Performance',
            function() {
                echo '<p>Optimize how the plugin loads and caches content.</p>';
            },
            'praison-settings'
        );
        
        add_settings_field(
            'cache_enabled',
            'Enable Cache',
            [$this, 'renderToggleField'],
            'praison-settings',
            'praisonpress_performance',
            ['field' => 'cache_enabled', 'description' => 'Cache content for faster page loads (recommended)']
        );
        
        add_settings_field(
            'cache_ttl',
            'Cache Duration',
            [$this, 'renderCacheTTLField'],
            'praison-settings',
            'praisonpress_performance'
        );
        
        // ── Section: Content Index ──
        add_settings_section(
            'praisonpress_index',
            'Content Index',
            function() {
                echo '<p>The content index speeds up page loading by pre-scanning all files. '
                   . '<strong>Rebuild the index after adding, editing, or removing content files.</strong></p>';
            },
            'praison-settings'
        );
        
        add_settings_field(
            'index_status',
            'Index Status',
            [$this, 'renderIndexStatus'],
            'praison-settings',
            'praisonpress_index'
        );
    }
    
    /**
     * Default options — generic for any WordPress user
     */
    public static function getDefaults() {
        return [
            'content_enabled' => false,
            'post_types'      => ['post', 'page'],
            'cache_enabled'   => true,
            'cache_ttl'       => 3600,
        ];
    }
    
    /**
     * Get current options
     */
    public static function getOptions() {
        $defaults = self::getDefaults();
        $options  = get_option(self::OPTION_NAME, []);
        return wp_parse_args($options, $defaults);
    }
    
    /**
     * Sanitize options on save
     */
    public function sanitizeOptions($input) {
        $sanitized = [];
        $sanitized['content_enabled'] = !empty($input['content_enabled']);
        $sanitized['cache_enabled']   = !empty($input['cache_enabled']);
        $sanitized['cache_ttl']       = absint($input['cache_ttl'] ?? 3600);
        
        // Clamp TTL
        if ($sanitized['cache_ttl'] < 60) $sanitized['cache_ttl'] = 60;
        if ($sanitized['cache_ttl'] > 86400) $sanitized['cache_ttl'] = 86400;
        
        // Post types: array of sanitized slugs
        $sanitized['post_types'] = [];
        if (!empty($input['post_types']) && is_array($input['post_types'])) {
            $sanitized['post_types'] = array_map('sanitize_key', $input['post_types']);
        }
        
        return $sanitized;
    }
    
    /**
     * When settings are saved, rebuild the index synchronously (reliable, no cron needed)
     */
    public function onSettingsSaved($old_value, $new_value) {
        // Rebuild index synchronously when content is enabled
        if (!empty($new_value['content_enabled'])) {
            $bootstrap = \PraisonPress\Core\Bootstrap::init();
            $bootstrap->doBackgroundIndexRebuild();
        }
        
        // Clear all caches when settings change
        if (class_exists('PraisonPress\\Cache\\CacheManager')) {
            \PraisonPress\Cache\CacheManager::clearAll();
        }
    }
    
    // ─── Field Renderers ─────────────────────────────────────────────────────
    
    /**
     * Render the Getting Started guide
     */
    public function renderQuickStart() {
        $content_dir = defined('PRAISON_CONTENT_DIR') ? PRAISON_CONTENT_DIR : '(not set)';
        $has_content = is_dir($content_dir) && count(glob($content_dir . '/*/*.md')) > 0;
        $sample_file = $content_dir . '/posts/hello-from-praisonpress.md';
        $has_sample  = file_exists($sample_file);
        ?>
        <div style="background:#f0f6fc;border:1px solid #c8d6e5;border-radius:6px;padding:16px 20px;margin-bottom:8px;">
            <h3 style="margin-top:0;">📁 Your Content Directory</h3>
            <p><code style="background:#fff;padding:4px 8px;border-radius:3px;font-size:13px;"><?php echo esc_html($content_dir); ?></code></p>
            
            <h3>⚡ Quick Setup (3 steps)</h3>
            <ol style="line-height:2;">
                <li>
                    <strong>Add Markdown files</strong> to subdirectories: <code>posts/</code>, <code>pages/</code>, or create any custom type folder.
                    <?php if ($has_sample): ?>
                        <br><span style="color:green;">✅ Sample content detected!</span>
                    <?php elseif (!$has_content): ?>
                        <br><span style="color:#666;">No content files found yet. A sample file was created for you at <code><?php echo esc_html(basename(dirname($sample_file)) . '/' . basename($sample_file)); ?></code> during activation.</span>
                    <?php else: ?>
                        <br><span style="color:green;">✅ <?php echo number_format(count(glob($content_dir . '/*/*.md'))); ?> content files detected!</span>
                    <?php endif; ?>
                </li>
                <li><strong>Enable content delivery</strong> below and select your post types.</li>
                <li><strong>Click "Save Settings"</strong> — the index rebuilds automatically. That's it!</li>
            </ol>
            
            <details style="margin-top:12px;">
                <summary style="cursor:pointer;font-weight:600;color:#2271b1;">📝 Example Markdown File Format</summary>
                <pre style="background:#fff;padding:12px;border-radius:4px;border:1px solid #ddd;margin-top:8px;font-size:12px;line-height:1.6;overflow-x:auto;">---
title: "My First Post"
slug: "my-first-post"
date: "<?php echo current_time('Y-m-d H:i:s'); ?>"
status: "publish"
categories:
  - "General"
tags:
  - "example"
excerpt: "A brief description of the post"
---

# Hello World

Write your content in **Markdown** format.
Supports headings, lists, links, images, and more.</pre>
            </details>
            
            <details style="margin-top:8px;">
                <summary style="cursor:pointer;font-weight:600;color:#2271b1;">📂 Directory Structure</summary>
                <pre style="background:#fff;padding:12px;border-radius:4px;border:1px solid #ddd;margin-top:8px;font-size:12px;line-height:1.6;">content/
├── posts/           → WordPress "post" type
│   ├── my-post.md
│   └── _index.json  (auto-generated)
├── pages/           → WordPress "page" type
│   └── about.md
├── recipes/         → Custom "recipes" post type (auto-registered!)
│   └── pasta.md
└── config/          → Plugin config (ignored)</pre>
            </details>
        </div>
        <?php
    }
    
    public function renderToggleField($args) {
        $options = self::getOptions();
        $field   = $args['field'];
        $checked = !empty($options[$field]);
        ?>
        <label>
            <input type="checkbox" name="<?php echo self::OPTION_NAME; ?>[<?php echo esc_attr($field); ?>]" value="1" <?php checked($checked); ?>>
            <?php echo esc_html($args['description'] ?? ''); ?>
        </label>
        <?php
    }
    
    public function renderCacheTTLField() {
        $options = self::getOptions();
        $value   = $options['cache_ttl'] ?? 3600;
        $presets = [
            300   => '5 minutes',
            900   => '15 minutes',
            3600  => '1 hour (recommended)',
            7200  => '2 hours',
            21600 => '6 hours',
            43200 => '12 hours',
            86400 => '24 hours',
        ];
        ?>
        <select name="<?php echo self::OPTION_NAME; ?>[cache_ttl]">
            <?php foreach ($presets as $seconds => $label): ?>
                <option value="<?php echo $seconds; ?>" <?php selected($value, $seconds); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">How long to keep cached content before refreshing from files.</p>
        <?php
    }
    
    public function renderPostTypesField($args) {
        $options   = self::getOptions();
        $selected  = $options['post_types'] ?? [];
        
        // Start with common WordPress types
        $available = ['post', 'page'];
        
        // Auto-detect from content directory
        if (defined('PRAISON_CONTENT_DIR') && is_dir(PRAISON_CONTENT_DIR)) {
            $dirs = @scandir(PRAISON_CONTENT_DIR);
            if ($dirs) {
                foreach ($dirs as $d) {
                    if ($d[0] !== '.' && $d !== 'config' && is_dir(PRAISON_CONTENT_DIR . '/' . $d)) {
                        // Map 'posts' dir → 'post', 'pages' dir → 'page'
                        $type = $d;
                        if ($d === 'posts') $type = 'post';
                        if ($d === 'pages') $type = 'page';
                        if (!in_array($type, $available)) {
                            $available[] = $type;
                        }
                    }
                }
            }
        }
        
        // Also include any types already selected (in case directory was removed)
        foreach ($selected as $s) {
            if (!in_array($s, $available)) {
                $available[] = $s;
            }
        }
        
        echo '<fieldset>';
        foreach ($available as $type) {
            $checked = in_array($type, $selected);
            $label = ucfirst($type);
            // Show directory name in parentheses if it differs
            $dir_name = ($type === 'post') ? 'posts' : (($type === 'page') ? 'pages' : $type);
            $has_dir = defined('PRAISON_CONTENT_DIR') && is_dir(PRAISON_CONTENT_DIR . '/' . $dir_name);
            $dir_info = $has_dir ? '' : ' <span style="color:#999;">(no directory yet)</span>';
            if ($has_dir) {
                $count = count(glob(PRAISON_CONTENT_DIR . '/' . $dir_name . '/*.md'));
                $dir_info = $count > 0 ? " <span style=\"color:green;\">({$count} files)</span>" : ' <span style="color:#999;">(empty)</span>';
            }
            
            printf(
                '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="%s[post_types][]" value="%s" %s> <strong>%s</strong> <code style="font-size:11px;color:#666;">%s/</code>%s</label>',
                self::OPTION_NAME,
                esc_attr($type),
                checked($checked, true, false),
                esc_html($label),
                esc_html($dir_name),
                $dir_info
            );
        }
        echo '</fieldset>';
        echo '<p class="description">Select which content types to serve from Markdown files. New types are auto-detected from subdirectories in your content folder.</p>';
    }
    
    public function renderIndexStatus() {
        $content_dir = defined('PRAISON_CONTENT_DIR') ? PRAISON_CONTENT_DIR : '';
        $types       = [];
        $total_files = 0;
        $total_indexed = 0;
        
        if ($content_dir && is_dir($content_dir)) {
            $dirs = @scandir($content_dir);
            if ($dirs) {
                foreach ($dirs as $d) {
                    if ($d[0] !== '.' && $d !== 'config' && is_dir($content_dir . '/' . $d)) {
                        $index_file = $content_dir . '/' . $d . '/_index.json';
                        $md_count   = count(glob($content_dir . '/' . $d . '/*.md'));
                        $total_files += $md_count;
                        
                        $index_count = 0;
                        if (file_exists($index_file)) {
                            $data = json_decode(file_get_contents($index_file), true);
                            $index_count = is_array($data) ? count($data) : 0;
                            $total_indexed += $index_count;
                        }
                        
                        $types[$d] = [
                            'has_index'   => file_exists($index_file),
                            'index_date'  => file_exists($index_file) ? date('Y-m-d H:i:s', filemtime($index_file)) : null,
                            'index_size'  => file_exists($index_file) ? size_format(filesize($index_file)) : null,
                            'index_count' => $index_count,
                            'file_count'  => $md_count,
                            'in_sync'     => file_exists($index_file) && ($index_count === $md_count),
                        ];
                    }
                }
            }
        }
        
        if (empty($types)) {
            echo '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:12px;max-width:600px;">';
            echo '<strong>📂 No content found.</strong> ';
            echo 'Add Markdown (.md) files to subdirectories in <code>' . esc_html($content_dir) . '</code> to get started.';
            echo '</div>';
            return;
        }
        
        // Summary bar
        $all_synced = array_reduce($types, function($carry, $item) {
            return $carry && $item['in_sync'];
        }, true);
        
        if ($all_synced && $total_indexed > 0) {
            echo '<div style="background:#d4edda;border:1px solid #28a745;border-radius:4px;padding:8px 12px;max-width:600px;margin-bottom:10px;">';
            echo '✅ <strong>' . number_format($total_indexed) . ' entries indexed</strong> across ' . count($types) . ' content type(s). Everything is up to date.';
            echo '</div>';
        } elseif ($total_files > 0) {
            echo '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:8px 12px;max-width:600px;margin-bottom:10px;">';
            echo '⚠️ <strong>Index needs rebuild.</strong> ' . number_format($total_files) . ' files found, ' . number_format($total_indexed) . ' indexed.';
            echo '</div>';
        }
        
        echo '<table class="widefat striped" style="max-width:600px">';
        echo '<thead><tr><th>Type</th><th>Files</th><th>Index</th><th>Status</th><th>Last Built</th></tr></thead>';
        echo '<tbody>';
        foreach ($types as $type => $info) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($type) . '</strong></td>';
            echo '<td>' . number_format($info['file_count']) . '</td>';
            if ($info['has_index']) {
                $status_icon = $info['in_sync'] ? '✅' : '🔄';
                $status_text = $info['in_sync'] ? 'Up to date' : 'Needs rebuild';
                $status_color = $info['in_sync'] ? 'green' : 'orange';
                echo '<td>' . number_format($info['index_count']) . ' entries (' . esc_html($info['index_size']) . ')</td>';
                echo '<td><span style="color:' . $status_color . '">' . $status_icon . ' ' . $status_text . '</span></td>';
                echo '<td>' . esc_html($info['index_date']) . '</td>';
            } else {
                echo '<td>—</td>';
                echo '<td><span style="color:red">❌ Not built</span></td>';
                echo '<td>—</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        
        // Rebuild button
        $rebuild_url = wp_nonce_url(admin_url('admin-post.php?action=praison_rebuild_index'), 'praison_rebuild_index');
        echo '<p style="margin-top:12px">';
        echo '<a href="' . esc_url($rebuild_url) . '" class="button button-secondary">🔄 Rebuild Index Now</a>';
        echo ' <span class="description">Scans all .md files and generates a fast-lookup index for each content type.</span>';
        echo '</p>';
    }
    
    // ─── Page Renderer ───────────────────────────────────────────────────────
    
    public function renderPage() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>
                <span style="vertical-align:middle;">📄</span> PraisonPress Settings
                <span style="font-size:12px;color:#666;vertical-align:middle;margin-left:8px;">v<?php echo esc_html(PRAISON_VERSION); ?></span>
            </h1>
            
            <?php settings_errors(); ?>
            
            <?php
            // Show index rebuild result notice
            if (isset($_GET['index_rebuilt'])) {
                $success = sanitize_text_field($_GET['index_rebuilt']) === '1';
                $class = $success ? 'notice-success' : 'notice-error';
                $msg   = $success
                    ? '✅ Content index rebuilt successfully! Your content is ready to serve.'
                    : '❌ Index rebuild failed — check that the content directory exists and is writable.';
                echo '<div class="notice ' . $class . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
            }
            ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections('praison-settings');
                submit_button('Save Settings');
                ?>
            </form>
            
            <hr>
            <p class="description" style="margin-top:16px;">
                <strong>Need help?</strong>
                <a href="https://github.com/MervinPraison/wp-git-posts" target="_blank">Documentation & Source Code</a> |
                <a href="https://github.com/MervinPraison/wp-git-posts/issues" target="_blank">Report an Issue</a>
            </p>
        </div>
        <?php
    }
}
