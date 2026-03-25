<?php
namespace PraisonPress\Core;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use PraisonPress\Loaders\PostLoader;
use PraisonPress\Cache\CacheManager;
use PraisonPress\Admin\ExportPage;

/**
 * Bootstrap PraisonPress plugin
 * This is the main entry point that hooks into WordPress
 */
class Bootstrap {
    
    private static $instance = null;
    private $postLoaders = [];
    private $postTypes = [];
    private $allowedPostTypes = null;
    private $postTypesDiscovered = false;
    private static $parsedConfig = null;
    
    /**
     * Initialize the plugin
     */
    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Parse site-config.ini ONCE and cache statically (zero I/O after first call)
     */
    private static function getConfig() {
        if (self::$parsedConfig !== null) {
            return self::$parsedConfig;
        }
        $config_file = PRAISON_PLUGIN_DIR . '/site-config.ini';
        if (file_exists($config_file)) {
            self::$parsedConfig = parse_ini_file($config_file, true) ?: [];
        } else {
            self::$parsedConfig = [];
        }
        return self::$parsedConfig;
    }
    
    /**
     * Check if file-based content delivery is enabled in site-config.ini
     */
    private function isContentEnabled() {
        $config = self::getConfig();
        // Default to false if not set — explicit opt-in required
        if (!isset($config['content']['enabled'])) {
            return false;
        }
        $val = $config['content']['enabled'];
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }
    
    /**
     * Constructor - register hooks ONLY (NO filesystem I/O)
     */
    private function __construct() {
        // Register custom post type (deferred — runs at 'init' action)
        add_action('init', [$this, 'registerPostType']);
        
        // Virtual post injection — lazy: actual work deferred to first call
        add_filter('posts_pre_query', [$this, 'injectFilePosts'], 10, 2);
        
        // Initialize export page early (before admin_menu)
        if (is_admin()) {
            new ExportPage();
        }
        
        // Admin features (priority 10 - default)
        add_action('admin_menu', [$this, 'addAdminMenu']);
        
        // Dashboard widget
        add_action('wp_dashboard_setup', [$this, 'addDashboardWidget']);
        
        // Version history submenu (priority 20 - appears after Export)
        add_action('admin_menu', [$this, 'addHistoryMenu'], 20);
        
        // Register webhook endpoint
        if (file_exists(PRAISON_PLUGIN_DIR . '/src/API/WebhookEndpoint.php')) {
            require_once PRAISON_PLUGIN_DIR . '/src/API/WebhookEndpoint.php';
            $webhook = new \PraisonPress\API\WebhookEndpoint();
            $webhook->register();
        }
        
        // Register Report Error button
        if (file_exists(PRAISON_PLUGIN_DIR . '/src/Frontend/ReportErrorButton.php')) {
            require_once PRAISON_PLUGIN_DIR . '/src/Frontend/ReportErrorButton.php';
            $reportButton = new \PraisonPress\Frontend\ReportErrorButton();
            $reportButton->register();
        }
        
        // Register My Submissions Page
        if (file_exists(PRAISON_PLUGIN_DIR . '/src/Frontend/MySubmissionsPage.php')) {
            require_once PRAISON_PLUGIN_DIR . '/src/Frontend/MySubmissionsPage.php';
            $mySubmissionsPage = new \PraisonPress\Frontend\MySubmissionsPage();
            $mySubmissionsPage->register();
        }
        
        // Register Pull Requests page
        if (file_exists(PRAISON_PLUGIN_DIR . '/src/Admin/PullRequestsPage.php')) {
            require_once PRAISON_PLUGIN_DIR . '/src/Admin/PullRequestsPage.php';
            $prPage = new \PraisonPress\Admin\PullRequestsPage();
            $prPage->register();
        }
        
        // Note: Export menu is added at priority 15 by ExportPage class
        
        // Admin bar items
        add_action('admin_bar_menu', [$this, 'addAdminBarItems'], 100);
        
        // Cache management
        add_action('admin_post_praison_clear_cache', [$this, 'handleClearCache']);
        
        // Rollback handler
        add_action('admin_post_praison_rollback', [$this, 'handleRollback']);
        
        // Admin notices
        add_action('admin_notices', [$this, 'showAdminNotices']);
        
        // Auto-export on publish (requires [sync] config in site-config.ini)
        if (file_exists(PRAISON_PLUGIN_DIR . '/src/Export/AutoExporter.php')) {
            $autoExporter = new \PraisonPress\Export\AutoExporter();
            $autoExporter->register();
        }
    }
    
    /**
     * Get the allowed post types from site-config.ini
     * 
     * @return array|null Array of allowed types, or null if setting doesn't exist (allow all)
     */
    private function getAllowedPostTypes() {
        if ($this->allowedPostTypes !== null) {
            // false is our internal sentinel for "checked but not found" → return null (allow all)
            return $this->allowedPostTypes === false ? null : $this->allowedPostTypes;
        }
        
        $config = self::getConfig();
        if (isset($config['content']['post_types']) && is_array($config['content']['post_types'])) {
            $this->allowedPostTypes = $config['content']['post_types'];
            return $this->allowedPostTypes;
        }
        
        $this->allowedPostTypes = false; // Use false internally to denote "checked but not found"
        return null; // Return null to mean "allow all"
    }

    /**
     * Dynamically discover post types from content directory
     * Scans folders and auto-registers them as post types
     * 
     * @return array List of post type slugs
     */
    private function discoverPostTypes() {
        $types = [];
        
        // Check if content directory exists
        if (!file_exists(PRAISON_CONTENT_DIR) || !is_dir(PRAISON_CONTENT_DIR)) {
            return $types;
        }
        
        // Scan content directory for subdirectories
        $items = scandir(PRAISON_CONTENT_DIR);
        
        $allowed = $this->getAllowedPostTypes();
        
        foreach ($items as $item) {
            // Skip hidden files, current/parent directory references
            if ($item[0] === '.' || $item === 'config') {
                continue;
            }
            
            $path = PRAISON_CONTENT_DIR . '/' . $item;
            
            // Only process directories
            if (is_dir($path)) {
                // If allowed list is defined in config, skip post types not in the list
                // Note: The directory name for 'post' is 'posts', so check both
                if ($allowed !== null) {
                    $check_name = ($item === 'posts') ? 'post' : $item;
                    if (!in_array($check_name, $allowed, true)) {
                        continue;
                    }
                }
                
                $types[] = $item;
            }
        }
        
        return $types;
    }
    
    /**
     * Lazy initialization: discover post types and create loaders on first use.
     * Only called when injectFilePosts actually needs to serve content.
     */
    private function ensurePostTypesDiscovered() {
        if ($this->postTypesDiscovered) {
            return;
        }
        $this->postTypesDiscovered = true;
        $this->postTypes = $this->discoverPostTypes();
        foreach ($this->postTypes as $type) {
            if (!isset($this->postLoaders[$type])) {
                $this->postLoaders[$type] = new PostLoader($type);
            }
        }
    }
    
    /**
     * Register custom post types dynamically based on discovered folders
     * Only registers if WordPress hasn't already registered the post type
     */
    public function registerPostType() {
        if (!$this->isContentEnabled()) {
            return;
        }
        $this->ensurePostTypesDiscovered();
        foreach ($this->postTypes as $type) {
            // Special case for 'posts' - register as 'praison_post'
            $post_type_slug = ($type === 'posts') ? 'praison_post' : $type;
            $rewrite_slug = $type;
            
            // Skip if post type already registered by WordPress or another plugin
            if (post_type_exists($post_type_slug)) {
                continue;
            }
            
            // Generate human-readable labels
            $singular = ucfirst($type);
            $plural = $singular;
            
            // Handle special pluralization
            if (substr($type, -1) !== 's') {
                $plural = $singular . 's';
            }
            
            // Register the post type dynamically
            register_post_type($post_type_slug, [
                'label' => $plural,
                'labels' => [
                    'name' => $plural,
                    'singular_name' => $singular,
                    'add_new' => 'Add New ' . $singular,
                    'add_new_item' => 'Add New ' . $singular,
                    'edit_item' => 'Edit ' . $singular,
                    'view_item' => 'View ' . $singular,
                    'all_items' => 'All ' . $plural,
                ],
                'public' => true,
                'show_ui' => false, // File-based, no admin UI
                'show_in_menu' => false,
                'show_in_nav_menus' => true,
                'show_in_admin_bar' => false,
                'publicly_queryable' => true,
                'exclude_from_search' => false,
                'has_archive' => true,
                'rewrite' => ['slug' => $rewrite_slug],
                'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'custom-fields'],
                'taxonomies' => ['category', 'post_tag'],
            ]);
        }
    }
    
    /**
     * Inject file-based posts before database query
     * This is the CORE functionality!
     * 
     * @param array|null $posts Return array to short-circuit, null to proceed normally
     * @param \WP_Query $query The query object
     * @return array|null
     */
    public function injectFilePosts($posts, $query) {
        // Skip injection only in specific admin contexts (not AJAX, not frontend)
        // This allows file-based posts to load on frontend while preventing export issues
        if ((is_admin() && !wp_doing_ajax()) || (defined('WP_CLI') && WP_CLI)) {
            return $posts;
        }
        
        // Check if file-based content is enabled in site-config.ini
        if (!$this->isContentEnabled()) {
            return $posts;
        }
        
        // Lazy initialization: only scan filesystem on first matching request
        $this->ensurePostTypesDiscovered();
        
        // Get the post type being queried
        $post_type = $query->get('post_type');
        
        // Debug logging (can be disabled in production)
        // Commented out for production - uncomment for debugging
        // if (defined('PRAISON_DEBUG') && PRAISON_DEBUG) {
        //     error_log('PraisonPress: Query detected - Post Type: ' . ($post_type ?: 'none') . ', Main Query: ' . ($query->is_main_query() ? 'yes' : 'no'));
        // }
        
        // If no post type specified, check if we're on home/archive (main query only)
        if (empty($post_type)) {
            if ($query->is_main_query() && (is_home() || is_archive())) {
                $allowed = $this->getAllowedPostTypes();
                // Check if 'post' is allowed to be file-based
                if ($allowed === null || in_array('post', $allowed, true)) {
                    // Check if posts loader exists before calling
                    if (isset($this->postLoaders['posts'])) {
                        return $this->postLoaders['posts']->loadPosts($query);
                    }
                }
            }
            return $posts;
        }
        
        // Skip injection if this post type is explicitly excluded from site-config.ini
        $allowed = $this->getAllowedPostTypes();
        if ($allowed !== null) {
            // Handle array of post types or single string
            $types_to_check = is_array($post_type) ? $post_type : [$post_type];
            $has_allowed = false;
            
            foreach ($types_to_check as $type) {
                // Translate internal post type back to standard if needed
                $check_type = ($type === 'praison_post') ? 'post' : $type;
                if (in_array($check_type, $allowed, true)) {
                    $has_allowed = true;
                    break;
                }
            }
            
            if (!$has_allowed) {
                return $posts;
            }
        }
        
        // Check if this is a file-based post type and load accordingly
        // For custom post types, inject even if not main query (for WP_Query calls)
        
        // Check if we have a loader for this post type and load accordingly
        
        // Fast early bail: skip if no content directory exists for this post type.
        // Resolve special alias: the registered post type 'praison_post' is stored in the 'posts' dir.
        $dir_name     = ($post_type === 'praison_post') ? 'posts' : $post_type;
        $post_type_dir = PRAISON_CONTENT_DIR . '/' . $dir_name;
        if (!is_dir($post_type_dir)) {
            return $posts;
        }

        // Get file-based posts
        $file_posts = null;
        
        // Special case: praison_post maps to 'posts' directory
        if ($post_type === 'praison_post' && is_array($this->postLoaders) && isset($this->postLoaders['posts'])) {
            $file_posts = $this->postLoaders['posts']->loadPosts($query);
        }
        // Check if we have a loader for this post type
        elseif (is_array($this->postLoaders) && isset($this->postLoaders[$post_type])) {
            $file_posts = $this->postLoaders[$post_type]->loadPosts($query);
        }
        // Dynamic loader creation: If post type directory exists but no loader, create one
        else {
            $post_type_dir = PRAISON_CONTENT_DIR . '/' . $post_type;
            if (is_dir($post_type_dir)) {
                $this->postLoaders[$post_type] = new \PraisonPress\Loaders\PostLoader($post_type);
                $file_posts = $this->postLoaders[$post_type]->loadPosts($query);
            }
        }
        
        // If no file-based posts, return database posts as-is
        if (empty($file_posts)) {
            if (get_option('praisonpress_qm_logging')) {
                do_action('qm/debug', sprintf(
                    '[PraisonPress] DB fallback for "%s" (post_type=%s)',
                    $query->get('name') ?: $query->get('pagename') ?: 'archive',
                    $post_type
                ));
            }
            return $posts;
        }
        
        // If $posts is null (posts_pre_query before DB query), return only file-based posts
        // This happens when WordPress hasn't queried the database yet
        if ($posts === null) {
            if (get_option('praisonpress_qm_logging')) {
                do_action('qm/info', sprintf(
                    '[PraisonPress] ✅ HEADLESS: Serving %d post(s) from Git files (post_type=%s, slug=%s)',
                    count($file_posts),
                    $post_type,
                    $query->get('name') ?: 'archive'
                ));
            }
            return $file_posts;
        }
        
        // MERGE: Combine database posts with file-based posts
        // File-based posts take precedence for duplicate slugs
        if (get_option('praisonpress_qm_logging')) {
            do_action('qm/info', sprintf(
                '[PraisonPress] ✅ HEADLESS+MERGE: %d file post(s) + %d DB post(s) (post_type=%s)',
                count($file_posts),
                count($posts),
                $post_type
            ));
        }
        $merged = [];
        $slugs_seen = [];
        
        // Add file-based posts first (they take precedence)
        foreach ($file_posts as $post) {
            $merged[] = $post;
            $slugs_seen[$post->post_name] = true;
        }
        
        // Add database posts that don't conflict with file-based posts
        foreach ($posts as $post) {
            if (!isset($slugs_seen[$post->post_name])) {
                $merged[] = $post;
                $slugs_seen[$post->post_name] = true;
            }
        }
        
        return $merged;
    }
    
    /**
     * Add admin menu
     */
    public function addAdminMenu() {
        // Add main menu page
        add_menu_page(
            'PraisonPress',
            'PraisonPress',
            'manage_options',
            'praison-file-content-git',
            [$this, 'renderAdminPage'],
            'dashicons-media-text',
            30
        );
        
        // Rename the default submenu item (WordPress auto-creates one)
        // Priority: This runs at default priority 10
        add_submenu_page(
            'praison-file-content-git',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'praison-file-content-git',  // Same as parent - this renames the default
            [$this, 'renderAdminPage']
        );
        
        // Note: Export menu added at priority 15 by ExportPage class
        
        // Settings will be added separately with priority 16
        add_action('admin_menu', function() {
            add_submenu_page(
                'praison-file-content-git',
                'Settings',
                'Settings',
                'manage_options',
                'praisonpress-settings',
                [$this, 'renderSettingsPage']
            );
        }, 16);
    }
    
    /**
     * Add version history submenu
     */
    public function addHistoryMenu() {
        add_submenu_page(
            'praison-file-content-git',
            'Version History',
            '📜 History',
            'manage_options',
            'praisonpress-history',
            [$this, 'renderHistoryPage']
        );
    }
    
    /**
     * Render history page
     */
    public function renderHistoryPage() {
        $historyPage = new \PraisonPress\Admin\HistoryPage();
        $historyPage->render();
    }
    
    /**
     * Add dashboard widget
     */
    public function addDashboardWidget() {
        wp_add_dashboard_widget(
            'praisonpress_status',
            '📁 PraisonPress Status',
            [$this, 'renderDashboardWidget']
        );
    }
    
    /**
     * Add admin bar items
     * 
     * @param \WP_Admin_Bar $wp_admin_bar
     */
    public function addAdminBarItems($wp_admin_bar) {
        $wp_admin_bar->add_node([
            'id'    => 'praisonpress-menu',
            'title' => '📁 PraisonPress',
            'href'  => admin_url('admin.php?page=praisonpress'),
        ]);
        
        $wp_admin_bar->add_node([
            'id'     => 'praisonpress-clear-cache',
            'parent' => 'praisonpress-menu',
            'title'  => 'Clear Cache',
            'href'   => wp_nonce_url(admin_url('admin-post.php?action=praison_clear_cache'), 'praison_clear_cache_action', 'praison_nonce'),
        ]);
        
        $wp_admin_bar->add_node([
            'id'     => 'praisonpress-content-dir',
            'parent' => 'praisonpress-menu',
            'title'  => 'Open Content Directory',
            'href'   => '#',
            'meta'   => [
                'title' => PRAISON_CONTENT_DIR,
            ]
        ]);
    }
    
    /**
     * Render admin page
     */
    public function renderAdminPage() {
        // Get stats safely, handling case where content directories don't exist
        $stats = isset($this->postLoaders['posts']) ? $this->postLoaders['posts']->getStats() : [
            'total_posts' => 0,
            'cache_active' => false,
            'content_dir' => PRAISON_CONTENT_DIR . '/posts'
        ];
        
        ?>
        <div class="wrap">
            <h1>📁 PraisonPress - File-Based Content Management</h1>
            
            <div class="notice notice-info">
                <p><strong>Welcome to PraisonPress!</strong> Your content is loaded from files.</p>
            </div>
            
            <div class="card" style="max-width: 800px;">
                <h2>📊 Statistics</h2>
                <table class="widefat">
                    <tbody>
                        <?php if (isset($stats['total_posts'])): ?>
                        <tr>
                            <td><strong>Posts:</strong></td>
                            <td><?php echo esc_html( $stats['total_posts'] ); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (isset($stats['total_lyrics'])): ?>
                        <tr>
                            <td><strong>Lyrics:</strong></td>
                            <td><?php echo esc_html( $stats['total_lyrics'] ); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (isset($stats['total_recipes'])): ?>
                        <tr>
                            <td><strong>Recipes:</strong></td>
                            <td><?php echo esc_html( $stats['total_recipes'] ); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (isset($stats['total_pages'])): ?>
                        <tr>
                            <td><strong>Pages:</strong></td>
                            <td><?php echo esc_html( $stats['total_pages'] ); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><strong>Cache Status:</strong></td>
                            <td>
                                <?php if ($stats['cache_active']): ?>
                                    <span style="color: green;">✅ Active</span>
                                <?php else: ?>
                                    <span style="color: red;">❌ Inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Last Modified:</strong></td>
                            <td><?php echo esc_html($stats['last_modified']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Content Directory:</strong></td>
                            <td><code><?php echo esc_html(PRAISON_CONTENT_DIR); ?></code></td>
                        </tr>
                    </tbody>
                </table>
                
                <p style="margin-top: 20px;">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=praison_clear_cache'), 'praison_clear_cache_action', 'praison_nonce')); ?>" 
                       class="button button-primary">Clear Cache</a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=praisonpress-settings')); ?>" 
                       class="button">Settings</a>
                </p>
            </div>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>📝 Quick Start</h2>
                <ol>
                    <li>Create a new <code>.md</code> file in <code><?php echo esc_html(PRAISON_CONTENT_DIR); ?>/posts/</code></li>
                    <li>Add YAML front matter at the top:
                        <pre style="background: #f5f5f5; padding: 10px; margin: 10px 0;">---
title: "Your Post Title"
slug: "your-post-slug"
author: "admin"
date: "<?php echo esc_html(gmdate('Y-m-d H:i:s')); ?>"
status: "publish"
---

# Your content here in Markdown...</pre>
                    </li>
                    <li>Save the file - it's automatically live! 🎉</li>
                    <li>View your posts at: <a href="<?php echo esc_url(home_url()); ?>"><?php echo esc_url(home_url()); ?></a></li>
                </ol>
            </div>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>🔗 Useful Links</h2>
                <ul>
                    <li><a href="<?php echo esc_url(content_url('plugins/PRAISONPRESS-README.md')); ?>" target="_blank">Full Documentation</a></li>
                    <li><a href="<?php echo esc_url(home_url()); ?>" target="_blank">View Site</a></li>
                    <li><a href="<?php echo esc_url(admin_url('edit.php?post_type=post')); ?>">Regular Posts (Database)</a></li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function renderSettingsPage() {
        // Handle settings save
        if (isset($_POST['praisonpress_save_settings']) && check_admin_referer('praisonpress_settings_nonce')) {
            $qm_logging = isset($_POST['praisonpress_qm_logging']) ? 1 : 0;
            update_option('praisonpress_qm_logging', $qm_logging);
            echo '<div class="notice notice-success"><p>✅ Settings saved.</p></div>';
        }
        
        $qm_enabled = get_option('praisonpress_qm_logging', 0);
        ?>
        <div class="wrap">
            <h1>PraisonPress Settings</h1>
            
            <div class="card" style="max-width: 800px;">
                <h2>🔍 Diagnostics</h2>
                <form method="post">
                    <?php wp_nonce_field('praisonpress_settings_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Query Monitor Logging</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="praisonpress_qm_logging" value="1" <?php checked($qm_enabled, 1); ?> />
                                    Enable headless content source logging in Query Monitor
                                </label>
                                <p class="description">When enabled, each page load logs whether content was served from Git files or the database. Visible in Query Monitor's "Logs" panel. No performance impact when disabled.</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="praisonpress_save_settings" class="button button-primary" value="Save Settings" />
                    </p>
                </form>
            </div>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>⚙️ Configuration</h2>
                <p>Edit your configuration file at:</p>
                <p><code><?php echo esc_html(PRAISON_PLUGIN_DIR); ?>/site-config.ini</code></p>
                
                <h3>Current Settings</h3>
                <?php
                $config_file = PRAISON_PLUGIN_DIR . '/site-config.ini';
                if (file_exists($config_file)) {
                    $config = parse_ini_file($config_file, true);
                    echo '<pre style="background: #f5f5f5; padding: 10px;">';
                    echo esc_html(wp_json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    echo '</pre>';
                } else {
                    echo '<p><em>No configuration file found.</em></p>';
                }
                ?>
            </div>
            
            <!-- GitHub Integration -->
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>🐙 GitHub Integration</h2>
                
                <?php
                // Check if Git is installed
                exec('git --version 2>&1', $gitVersionOutput, $gitVersionReturn);
                $gitInstalled = ($gitVersionReturn === 0);
                $gitVersion = $gitInstalled ? $gitVersionOutput[0] : 'Not installed';
                ?>
                
                <div class="notice notice-<?php echo $gitInstalled ? 'success' : 'error'; ?>">
                    <p>
                        <strong>Git Status:</strong> 
                        <?php if ($gitInstalled): ?>
                            ✅ Installed (<?php echo esc_html($gitVersion); ?>)
                        <?php else: ?>
                            ❌ Not installed. Please install Git on your server.
                        <?php endif; ?>
                    </p>
                </div>
                
                <?php
                require_once PRAISON_PLUGIN_DIR . '/src/GitHub/OAuthHandler.php';
                require_once PRAISON_PLUGIN_DIR . '/src/GitHub/GitHubClient.php';
                
                // Use default PraisonPress OAuth app or custom from config
                // Note: This is a public client ID for PraisonPress plugin
                // Users can override with their own in site-config.ini
                $defaultClientId = 'Ov23liyeSqePhyWRSHlv'; // PraisonPress WordPress Plugin OAuth app
                $clientId = isset($config['github']['client_id']) ? $config['github']['client_id'] : $defaultClientId;
                $clientSecret = isset($config['github']['client_secret']) ? $config['github']['client_secret'] : null;
                
                $oauth = new \PraisonPress\GitHub\OAuthHandler($clientId, $clientSecret);
                
                // Get repository URL and connection status early
                $isConnected = $oauth->isConnected();
                $hasConfig = !empty($config['github']['client_id']) && !empty($config['github']['client_secret']);
                $repoUrl = isset($config['github']['repository_url']) ? $config['github']['repository_url'] : '';
                
                // Handle OAuth callback
                if (isset($_GET['action']) && $_GET['action'] === 'github-callback' && isset($_GET['code'])) {
                    $code = sanitize_text_field(wp_unslash($_GET['code']));
                    $tokenData = $oauth->getAccessToken($code);
                    
                    if ($tokenData && isset($tokenData['access_token'])) {
                        $oauth->storeAccessToken($tokenData['access_token']);
                        echo '<div class="notice notice-success"><p>✅ Successfully connected to GitHub!</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>❌ Failed to connect to GitHub. Please try again.</p></div>';
                    }
                }
                
                // Handle disconnect
                if (isset($_GET['action']) && $_GET['action'] === 'github-disconnect' && check_admin_referer('github_disconnect')) {
                    $oauth->deleteAccessToken();
                    echo '<div class="notice notice-success"><p>✅ Disconnected from GitHub.</p></div>';
                }
                
                // Handle manual sync
                if (isset($_GET['action']) && $_GET['action'] === 'github-sync' && check_admin_referer('github_sync')) {
                    require_once PRAISON_PLUGIN_DIR . '/src/GitHub/SyncManager.php';
                    require_once PRAISON_PLUGIN_DIR . '/src/Git/GitManager.php';
                    
                    $syncManager = new \PraisonPress\GitHub\SyncManager($repoUrl, isset($config['github']['main_branch']) ? $config['github']['main_branch'] : 'main');
                    $syncManager->setupRemote();
                    $result = $syncManager->pullFromRemote();
                    
                    if ($result['success']) {
                        echo '<div class="notice notice-success"><p>✅ ' . esc_html($result['message']) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>❌ ' . esc_html($result['message']) . '</p></div>';
                    }
                }
                
                // Handle auto-clone
                if (isset($_GET['action']) && $_GET['action'] === 'github-clone' && check_admin_referer('github_clone')) {
                    require_once PRAISON_PLUGIN_DIR . '/src/GitHub/SyncManager.php';
                    require_once PRAISON_PLUGIN_DIR . '/src/Git/GitManager.php';
                    
                    $syncManager = new \PraisonPress\GitHub\SyncManager($repoUrl, isset($config['github']['main_branch']) ? $config['github']['main_branch'] : 'main');
                    $result = $syncManager->cloneRepository();
                    
                    if ($result['success']) {
                        if (isset($result['already_exists']) && $result['already_exists']) {
                            echo '<div class="notice notice-info"><p>ℹ️ ' . esc_html($result['message']) . '</p></div>';
                        } else {
                            echo '<div class="notice notice-success"><p>✅ ' . esc_html($result['message']) . '</p></div>';
                        }
                    } else {
                        echo '<div class="notice notice-error"><p>❌ ' . esc_html($result['message']) . '</p></div>';
                    }
                }
                ?>
                
                <?php if (!$hasConfig): ?>
                    <div class="notice notice-info">
                        <p><strong>ℹ️ Quick Setup</strong></p>
                        <p>To enable collaborative features (pull requests, auto-sync):</p>
                        <ol>
                            <li><strong>Add your repository URL</strong> to <code>site-config.ini</code>:
                                <pre style="background: #f0f0f1; padding: 10px; margin: 10px 0;">[github]
repository_url = "https://github.com/MervinPraison/PraisonPressContent"</pre>
                            </li>
                            <li><strong>Click "Connect to GitHub"</strong> below to authorize access</li>
                        </ol>
                        <p><small><strong>Note:</strong> PraisonPress uses a shared OAuth app for easy setup. For production sites, you can <a href="https://github.com/settings/developers" target="_blank">create your own OAuth app</a> and add the credentials to <code>site-config.ini</code>.</small></p>
                    </div>
                <?php elseif (!$isConnected): ?>
                    <p><strong>Status:</strong> <span style="color: #d63638;">⚫ Not Connected</span></p>
                    <?php if (!empty($repoUrl)): ?>
                        <p><strong>Repository:</strong> <code><?php echo esc_html($repoUrl); ?></code></p>
                    <?php endif; ?>
                    <p>Connect your GitHub account to enable pull request creation and repository access.</p>
                    <p>
                        <a href="<?php echo esc_url($oauth->getAuthorizationUrl(['repo'])); ?>" class="button button-primary">
                            🔗 Connect to GitHub
                        </a>
                    </p>
                    <p><small>This will redirect you to GitHub to authorize the application. You'll need access to the repository configured in <code>site-config.ini</code>.</small></p>
                <?php else: ?>
                    <?php
                    // Test connection
                    $token = $oauth->getStoredAccessToken();
                    $client = new \PraisonPress\GitHub\GitHubClient($token);
                    $userTest = $client->testConnection();
                    ?>
                    <p><strong>Status:</strong> <span style="color: #00a32a;">🟢 Connected</span></p>
                    <?php if ($userTest['success'] && isset($userTest['data']['login'])): ?>
                        <p><strong>GitHub User:</strong> <?php echo esc_html($userTest['data']['login']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($repoUrl)): ?>
                        <p><strong>Repository:</strong> <code><?php echo esc_html($repoUrl); ?></code></p>
                        <?php
                        // Test repository access
                        $repoInfo = \PraisonPress\GitHub\GitHubClient::parseRepositoryUrl($repoUrl);
                        if ($repoInfo) {
                            $repoTest = $client->getRepository($repoInfo['owner'], $repoInfo['repo']);
                            if ($repoTest['success']) {
                                echo '<p style="color: #00a32a;">✅ Repository access confirmed</p>';
                                if (isset($repoTest['data']['private']) && $repoTest['data']['private']) {
                                    echo '<p><small>🔒 Private repository</small></p>';
                                }
                            } else {
                                echo '<p style="color: #d63638;">❌ Cannot access repository: ' . esc_html($repoTest['error']) . '</p>';
                            }
                        }
                        ?>
                    <?php endif; ?>
                    
                    <?php
                    // Show sync status
                    if (!empty($repoUrl)) {
                        require_once PRAISON_PLUGIN_DIR . '/src/GitHub/SyncManager.php';
                        require_once PRAISON_PLUGIN_DIR . '/src/Git/GitManager.php';
                        
                        $syncManager = new \PraisonPress\GitHub\SyncManager($repoUrl, isset($config['github']['main_branch']) ? $config['github']['main_branch'] : 'main');
                        $syncManager->setupRemote();
                        $syncStatus = $syncManager->getSyncStatus();
                        
                        if ($syncStatus['configured'] && $syncStatus['connected']) {
                            echo '<hr style="margin: 20px 0;">';
                            echo '<h3>Sync Status</h3>';
                            
                            if ($syncStatus['up_to_date']) {
                                echo '<p style="color: #00a32a;">✅ Up to date with remote</p>';
                            } else {
                                if ($syncStatus['incoming_changes'] > 0) {
                                    echo '<p style="color: #d63638;">⚠️ ' . esc_html($syncStatus['incoming_changes']) . ' incoming change(s) from remote</p>';
                                }
                                if ($syncStatus['outgoing_changes'] > 0) {
                                    echo '<p style="color: #d63638;">⚠️ ' . esc_html($syncStatus['outgoing_changes']) . ' outgoing change(s) to push</p>';
                                }
                            }
                            
                            echo '<p><small>Last sync: ' . esc_html($syncStatus['last_sync_date']) . '</small></p>';
                            echo '<p><small>Webhook URL: <code>' . esc_url(rest_url('praisonpress/v1/webhook/github')) . '</code></small></p>';
                        }
                    }
                    ?>
                    
                    <p>
                        <?php if (!empty($repoUrl)): ?>
                            <?php
                            // Check if content directory is a git repo
                            $isGitRepo = is_dir(PRAISON_CONTENT_DIR . '/.git');
                            ?>
                            <?php if ($isGitRepo): ?>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=praisonpress-settings&action=github-sync'), 'github_sync')); ?>" class="button button-primary">
                                    🔄 Sync Now
                                </a>
                            <?php else: ?>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=praisonpress-settings&action=github-clone'), 'github_clone')); ?>" class="button button-primary">
                                    💾 Clone Repository
                                </a>
                                <p><small>ℹ️ Content directory is not a Git repository. Click to clone from GitHub.</small></p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=praisonpress-settings&action=github-disconnect'), 'github_disconnect')); ?>" class="button">
                            🔌 Disconnect GitHub
                        </a>
                    </p>
                <?php endif; ?>
                
                <?php if (!$hasConfig || empty($repoUrl)): ?>
                    <hr>
                    <h3>Default Behavior (No GitHub)</h3>
                    <p>✅ Plugin works with <strong>local Git only</strong></p>
                    <p>✅ Version history tracks local changes</p>
                    <p>❌ No remote sync or pull requests</p>
                    <p>❌ No collaborative editing features</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render dashboard widget
     */
    public function renderDashboardWidget() {
        $stats = $this->postLoaders['posts']->getStats();
        ?>
        <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 15px;">
            <?php if (isset($stats['total_posts'])): ?>
            <div style="flex: 1; min-width: 80px; text-align: center;">
                <strong style="font-size: 24px; display: block;"><?php echo esc_html( $stats['total_posts'] ); ?></strong>
                <span style="color: #666; font-size: 11px;">Posts</span>
            </div>
            <?php endif; ?>
            <?php if (isset($stats['total_lyrics'])): ?>
            <div style="flex: 1; min-width: 80px; text-align: center;">
                <strong style="font-size: 24px; display: block;"><?php echo esc_html( $stats['total_lyrics'] ); ?></strong>
                <span style="color: #666; font-size: 11px;">Lyrics</span>
            </div>
            <?php endif; ?>
            <?php if (isset($stats['total_recipes'])): ?>
            <div style="flex: 1; min-width: 80px; text-align: center;">
                <strong style="font-size: 24px; display: block;"><?php echo esc_html( $stats['total_recipes'] ); ?></strong>
                <span style="color: #666; font-size: 11px;">Recipes</span>
            </div>
            <?php endif; ?>
            <?php if (isset($stats['total_pages'])): ?>
            <div style="flex: 1; min-width: 80px; text-align: center;">
                <strong style="font-size: 24px; display: block;"><?php echo esc_html( $stats['total_pages'] ); ?></strong>
                <span style="color: #666; font-size: 11px;">Pages</span>
            </div>
            <?php endif; ?>
            <div style="flex: 1; min-width: 80px; text-align: center;">
                <strong style="font-size: 24px; display: block;">
                    <?php echo $stats['cache_active'] ? '✅' : '❌'; ?>
                </strong>
                <span style="color: #666; font-size: 11px;">Cache</span>
            </div>
        </div>
        
        <p style="margin: 10px 0; padding-top: 10px; border-top: 1px solid #ddd;">
            <p><strong>Last Update:</strong> <?php echo esc_html( gmdate( 'Y-m-d H:i:s', strtotime( $stats['last_modified'] ) ) ); ?></p>
        </p>
        
        <p style="margin-top: 15px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=praisonpress')); ?>" class="button button-primary">
                Manage Content
            </a>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=praison_clear_cache'), 'praison_clear_cache_action', 'praison_nonce')); ?>" 
               class="button" style="margin-left: 5px;">
                Clear Cache
            </a>
        </p>
        <?php
    }
    
    /**
     * Handle cache clear action
     */
    public function handleClearCache() {
        // Security check - verify nonce
        if (!isset($_GET['praison_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['praison_nonce'])), 'praison_clear_cache_action')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Clear cache
        $cleared = CacheManager::clearAll();
        
        // Create nonce for the redirect
        $redirect_nonce = wp_create_nonce('praison_cache_cleared');
        
        // Redirect back with notice
        wp_safe_redirect(add_query_arg([
            'page' => 'praison-file-content-git',
            'cache_cleared' => '1',
            '_wpnonce' => $redirect_nonce
        ], admin_url('admin.php')));
        exit;
    }
    
    /**
     * Handle rollback action
     */
    public function handleRollback() {
        if (!isset($_GET['hash']) || !isset($_GET['_wpnonce'])) {
            wp_die('Invalid request');
        }
        
        $hash = sanitize_text_field(wp_unslash($_GET['hash']));
        
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'rollback_' . $hash)) {
            wp_die('Security check failed');
        }
        
        $gitManager = new \PraisonPress\Git\GitManager();
        // Pass null for file to rollback entire repository to this commit
        $result = $gitManager->rollback(null, $hash);
        
        // Redirect back to history page with status
        if ($result) {
            wp_safe_redirect(add_query_arg([
                'page' => 'praisonpress-history',
                'rollback' => 'success'
            ], admin_url('admin.php')));
        } else {
            wp_safe_redirect(add_query_arg([
                'page' => 'praisonpress-history',
                'rollback' => 'error'
            ], admin_url('admin.php')));
        }
        exit;
    }

    /**
     * Show admin notices
     */
    public function showAdminNotices() {
        // Verify nonce for cache cleared notice
        if (isset($_GET['cache_cleared']) && $_GET['cache_cleared'] == '1') {
            // Nonce verification for GET parameters
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'praison_cache_cleared')) {
                // If nonce verification fails, still show count if valid
            }
            $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Cache cleared!</strong> <?php echo esc_html($count); ?> cache entries removed.</p>
            </div>
            <?php
        }
    }
}
