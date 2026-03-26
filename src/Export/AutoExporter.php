<?php
namespace PraisonPress\Export;

if ( ! defined( 'ABSPATH' ) ) exit;

use PraisonPress\Index\IndexManager;

/**
 * Auto-Export on Publish
 *
 * Listens for post status transitions and automatically exports
 * the post to a Markdown file, then commits and pushes to GitHub.
 *
 * Enable in site-config.ini:
 *   [sync]
 *   auto_export_on_publish = true
 *   auto_push = true
 *
 * Or via wp-config.php:
 *   define('PRAISON_AUTO_EXPORT', true);
 *   define('PRAISON_AUTO_PUSH', true);
 */
class AutoExporter {

    private $config;
    private $exportConfig;

    public function __construct() {
        $this->config = $this->loadSyncConfig();
        $this->exportConfig = new ExportConfig();
    }

    /**
     * Register WordPress hooks
     */
    public function register() {
        if ( ! $this->isEnabled() ) {
            return;
        }

        // Fire on post status transitions (pending→publish, draft→publish, etc.)
        add_action( 'transition_post_status', [ $this, 'onStatusTransition' ], 20, 3 );

        // Deletion hooks — remove .md file and index entry
        add_action( 'wp_trash_post', [ $this, 'onTrashPost' ], 20 );
        add_action( 'before_delete_post', [ $this, 'onTrashPost' ], 20 ); // permanent delete
        add_action( 'untrash_post', [ $this, 'onUntrashPost' ], 20 );

        // Process the scheduled export
        add_action( 'praison_auto_export_post', [ $this, 'exportAndSync' ] );
    }

    /**
     * Check if auto-export is enabled
     */
    private function isEnabled(): bool {
        // Check wp-config.php constant first
        if ( defined( 'PRAISON_AUTO_EXPORT' ) ) {
            return (bool) PRAISON_AUTO_EXPORT;
        }

        // Check site-config.ini
        return ! empty( $this->config['auto_export_on_publish'] );
    }

    /**
     * Check if auto-push to remote is enabled
     */
    private function isPushEnabled(): bool {
        if ( defined( 'PRAISON_AUTO_PUSH' ) ) {
            return (bool) PRAISON_AUTO_PUSH;
        }

        return ! empty( $this->config['auto_push'] );
    }

    /**
     * Load sync configuration from site-config.ini
     */
    private function loadSyncConfig(): array {
        $config_file = PRAISON_PLUGIN_DIR . '/site-config.ini';
        if ( ! file_exists( $config_file ) ) {
            return [];
        }

        $parsed = parse_ini_file( $config_file, true );
        return $parsed['sync'] ?? [];
    }

    /**
     * Handle post status transition
     */
    public function onStatusTransition( string $new_status, string $old_status, \WP_Post $post ): void {
        // Only act on transitions TO publish
        if ( $new_status !== 'publish' ) {
            return;
        }

        // Skip if already published (prevents re-export on simple edits unless configured)
        if ( $old_status === 'publish' && empty( $this->config['export_on_update'] ) ) {
            return;
        }

        // Check if this post type has an export directory configured
        $exportDir = $this->exportConfig->getExportDirectory( $post->post_type );
        if ( empty( $exportDir ) ) {
            return;
        }

        // Schedule the export via cron to avoid blocking the admin request
        wp_schedule_single_event( time(), 'praison_auto_export_post', [ $post->ID ] );
    }

    /**
     * Export a single post to markdown and optionally push to GitHub
     */
    public function exportAndSync( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return;
        }

        // Load the export functions
        $export_script = PRAISON_PLUGIN_DIR . '/scripts/export-to-markdown.php';
        if ( ! file_exists( $export_script ) ) {
            return;
        }
        require_once $export_script;

        // Determine the output directory
        $output_dir = PRAISON_CONTENT_DIR . '/' . $post->post_type;
        if ( ! is_dir( $output_dir ) ) {
            wp_mkdir_p( $output_dir );
        }

        // Export the single post
        if ( ! function_exists( 'export_post_to_markdown' ) ) {
            return;
        }

        $result = export_post_to_markdown( $post, $output_dir );

        if ( ! $result ) {
            return;
        }

        // Incremental index update (~10ms instead of full rebuild).
        $date_prefix = gmdate( 'Y-m-d', strtotime( $post->post_date ) );
        $md_file     = $output_dir . '/' . $date_prefix . '-' . $post->post_name . '.md';
        IndexManager::addOrUpdate( $post->post_type, $post->post_name, $md_file );

        // Commit to Git if content directory is a git repo
        if ( ! $this->isPushEnabled() ) {
            return;
        }

        $this->commitAndPush( $post );
    }

    /**
     * Handle post trash / permanent delete: remove .md file + index entry.
     */
    public function onTrashPost( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        // Check if this post type has an export directory.
        $exportDir = $this->exportConfig->getExportDirectory( $post->post_type );
        if ( empty( $exportDir ) ) {
            return;
        }

        $output_dir  = PRAISON_CONTENT_DIR . '/' . $post->post_type;
        $date_prefix = gmdate( 'Y-m-d', strtotime( $post->post_date ) );
        $md_file     = $output_dir . '/' . $date_prefix . '-' . $post->post_name . '.md';

        // Delete the .md file.
        if ( file_exists( $md_file ) ) {
            unlink( $md_file );
        }
        // Also try without date prefix (older export format).
        $md_file_alt = $output_dir . '/' . $post->post_name . '.md';
        if ( file_exists( $md_file_alt ) ) {
            unlink( $md_file_alt );
        }

        // Remove from index.
        IndexManager::remove( $post->post_type, $post->post_name );

        // Push deletion to Git.
        if ( $this->isPushEnabled() ) {
            $this->commitAndPush( $post, 'Deleted' );
        }
    }

    /**
     * Handle untrash: re-export the post and add back to index.
     */
    public function onUntrashPost( int $post_id ): void {
        // Schedule re-export (reuses existing export logic).
        wp_schedule_single_event( time(), 'praison_auto_export_post', [ $post_id ] );
    }

    /**
     * Commit the exported file and push to remote
     */
    private function commitAndPush( \WP_Post $post, string $action = 'Auto-export' ): void {
        if ( ! is_dir( PRAISON_CONTENT_DIR . '/.git' ) ) {
            return;
        }

        $oldDir = getcwd();
        chdir( PRAISON_CONTENT_DIR );

        // Stage all changes in the post type directory
        exec( 'git add -A ' . escapeshellarg( $post->post_type ) . '/ 2>&1', $addOutput, $addReturn );

        // Check if there are staged changes
        exec( 'git diff --cached --quiet 2>&1', $diffOutput, $diffReturn );
        if ( $diffReturn === 0 ) {
            // No changes to commit
            chdir( $oldDir );
            return;
        }

        // Commit
        $message = sprintf(
            '%s: %s "%s" (%s)',
            $action,
            $post->post_type,
            $post->post_title,
            $post->post_name
        );
        exec( 'git commit -m ' . escapeshellarg( $message ) . ' 2>&1', $commitOutput, $commitReturn );

        if ( $commitReturn !== 0 ) {
            chdir( $oldDir );
            return;
        }

        // Push to remote (main branch from site-config.ini)
        $config_file = PRAISON_PLUGIN_DIR . '/site-config.ini';
        $mainBranch = 'main';
        if ( file_exists( $config_file ) ) {
            $config = parse_ini_file( $config_file, true );
            $mainBranch = $config['github']['main_branch'] ?? 'main';
        }

        exec( 'git push origin ' . escapeshellarg( $mainBranch ) . ' 2>&1', $pushOutput, $pushReturn );

        chdir( $oldDir );
    }
}
