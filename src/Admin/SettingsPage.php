<?php
namespace PraisonPress\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Settings Page — WordPress-native configuration for PraisonPress
 * 
 * All settings are stored in wp_options (no Kubernetes secrets or ini files needed).
 * The ini file is used as a fallback only — wp_options always takes precedence.
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
        
        // ── Section: Content Delivery ──
        add_settings_section(
            'praisonpress_content',
            'Content Delivery',
            function() {
                echo '<p>Enable file-based content delivery. When enabled, the plugin serves content from Markdown files instead of the WordPress database.</p>';
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
            ['field' => 'cache_enabled', 'description' => 'Cache content in Redis/object cache for faster page loads']
        );
        
        add_settings_field(
            'cache_ttl',
            'Cache TTL (seconds)',
            [$this, 'renderNumberField'],
            'praison-settings',
            'praisonpress_performance',
            ['field' => 'cache_ttl', 'description' => 'How long to cache content (default: 3600 = 1 hour)', 'min' => 60, 'max' => 86400]
        );
        
        // ── Section: Index ──
        add_settings_section(
            'praisonpress_index',
            'Content Index',
            function() {
                echo '<p>The content index speeds up page loading by pre-scanning all files. Rebuild after adding or removing content.</p>';
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
     * Get default option values
     */
    public static function getDefaults() {
        return [
            'content_enabled' => false,
            'post_types'      => ['lyrics', 'chords'],
            'cache_enabled'   => true,
            'cache_ttl'       => 3600,
        ];
    }
    
    /**
     * Get current options (wp_options first, ini fallback)
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
        
        // Post types: array of sanitized slugs
        $sanitized['post_types'] = [];
        if (!empty($input['post_types']) && is_array($input['post_types'])) {
            $sanitized['post_types'] = array_map('sanitize_key', $input['post_types']);
        }
        
        return $sanitized;
    }
    
    /**
     * When settings are saved, auto-rebuild the index if content is enabled
     */
    public function onSettingsSaved($old_value, $new_value) {
        if (!empty($new_value['content_enabled'])) {
            // Schedule index rebuild in the background
            if (!wp_next_scheduled('praisonpress_rebuild_index')) {
                wp_schedule_single_event(time(), 'praisonpress_rebuild_index');
            }
        }
        
        // Clear all caches when settings change
        if (class_exists('PraisonPress\\Cache\\CacheManager')) {
            \PraisonPress\Cache\CacheManager::clearAll();
        }
    }
    
    // ─── Field Renderers ─────────────────────────────────────────────────────
    
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
    
    public function renderTextField($args) {
        $options = self::getOptions();
        $field   = $args['field'];
        $value   = $options[$field] ?? '';
        ?>
        <input type="text" name="<?php echo self::OPTION_NAME; ?>[<?php echo esc_attr($field); ?>]" 
               value="<?php echo esc_attr($value); ?>" 
               placeholder="<?php echo esc_attr($args['placeholder'] ?? ''); ?>"
               class="regular-text">
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo $args['description']; ?></p>
        <?php endif; ?>
        <?php
    }
    
    public function renderNumberField($args) {
        $options = self::getOptions();
        $field   = $args['field'];
        $value   = $options[$field] ?? '';
        ?>
        <input type="number" name="<?php echo self::OPTION_NAME; ?>[<?php echo esc_attr($field); ?>]" 
               value="<?php echo esc_attr($value); ?>"
               min="<?php echo esc_attr($args['min'] ?? 0); ?>"
               max="<?php echo esc_attr($args['max'] ?? ''); ?>"
               class="small-text">
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif; ?>
        <?php
    }
    
    public function renderPostTypesField($args) {
        $options   = self::getOptions();
        $selected  = $options['post_types'] ?? [];
        $available = ['post', 'page', 'lyrics', 'chords', 'bible', 'articles', 'notes', 'collections'];
        
        // Also detect custom directories
        if (defined('PRAISON_CONTENT_DIR') && is_dir(PRAISON_CONTENT_DIR)) {
            $dirs = @scandir(PRAISON_CONTENT_DIR);
            if ($dirs) {
                foreach ($dirs as $d) {
                    if ($d[0] !== '.' && $d !== 'config' && is_dir(PRAISON_CONTENT_DIR . '/' . $d)) {
                        if (!in_array($d, $available)) {
                            $available[] = $d;
                        }
                    }
                }
            }
        }
        
        echo '<fieldset>';
        foreach ($available as $type) {
            $checked = in_array($type, $selected);
            printf(
                '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%s[post_types][]" value="%s" %s> %s</label>',
                self::OPTION_NAME,
                esc_attr($type),
                checked($checked, true, false),
                esc_html(ucfirst($type))
            );
        }
        echo '</fieldset>';
        echo '<p class="description">Select which post types to serve from Markdown files.</p>';
    }
    
    public function renderIndexStatus() {
        $content_dir = PRAISON_CONTENT_DIR;
        $types       = [];
        
        if (is_dir($content_dir)) {
            $dirs = @scandir($content_dir);
            if ($dirs) {
                foreach ($dirs as $d) {
                    if ($d[0] !== '.' && $d !== 'config' && is_dir($content_dir . '/' . $d)) {
                        $index_file = $content_dir . '/' . $d . '/_index.json';
                        $md_count   = count(glob($content_dir . '/' . $d . '/*.md'));
                        $types[$d]  = [
                            'has_index'  => file_exists($index_file),
                            'index_date' => file_exists($index_file) ? date('Y-m-d H:i:s', filemtime($index_file)) : null,
                            'index_size' => file_exists($index_file) ? size_format(filesize($index_file)) : null,
                            'file_count' => $md_count,
                        ];
                    }
                }
            }
        }
        
        if (empty($types)) {
            echo '<p>No content directories found at <code>' . esc_html($content_dir) . '</code></p>';
            return;
        }
        
        echo '<table class="widefat striped" style="max-width:600px">';
        echo '<thead><tr><th>Type</th><th>Files</th><th>Index</th><th>Last Built</th></tr></thead>';
        echo '<tbody>';
        foreach ($types as $type => $info) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($type) . '</strong></td>';
            echo '<td>' . number_format($info['file_count']) . ' .md files</td>';
            if ($info['has_index']) {
                echo '<td><span style="color:green">✅ Built</span> (' . esc_html($info['index_size']) . ')</td>';
                echo '<td>' . esc_html($info['index_date']) . '</td>';
            } else {
                echo '<td><span style="color:orange">⚠️ Missing</span></td>';
                echo '<td>—</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        
        // Rebuild button
        $rebuild_url = wp_nonce_url(admin_url('admin-post.php?action=praison_rebuild_index'), 'praison_rebuild_index');
        echo '<p style="margin-top:10px">';
        echo '<a href="' . esc_url($rebuild_url) . '" class="button button-secondary">🔄 Rebuild Index Now</a>';
        echo ' <span class="description">Scans all .md files and generates _index.json for each content type.</span>';
        echo '</p>';
    }
    
    // ─── Page Renderer ───────────────────────────────────────────────────────
    
    public function renderPage() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>PraisonPress Settings</h1>
            
            <?php settings_errors(); ?>
            
            <?php
            // Show index rebuild result notice
            if (isset($_GET['index_rebuilt'])) {
                $success = $_GET['index_rebuilt'] === '1';
                $class = $success ? 'notice-success' : 'notice-error';
                $msg   = $success ? 'Content index rebuilt successfully.' : 'Index rebuild failed — check file permissions.';
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
        </div>
        <?php
    }
}
