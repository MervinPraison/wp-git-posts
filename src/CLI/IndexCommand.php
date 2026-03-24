<?php
namespace PraisonPress\CLI;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WP-CLI command: wp praison index
 *
 * Generates (or refreshes) the _index.json manifest for a content directory.
 * The index enables O(1) single-post slug lookups, bypassing a full glob() scan.
 *
 * Usage:
 *   wp praison index                      # index all discovered post types
 *   wp praison index --type=posts         # index one specific type
 *   wp praison index --type=posts --verbose
 */
class IndexCommand {

    /**
     * Build (or rebuild) the _index.json manifest for one or all post types.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Post type directory to index (e.g. posts, pages). Defaults to all.
     *
     * [--verbose]
     * : Print each file as it is indexed.
     *
     * ## EXAMPLES
     *
     *   wp praison index
     *   wp praison index --type=posts
     *   wp praison index --type=posts --verbose
     *
     * @when after_wp_load
     */
    public function __invoke( $args, $assoc_args ) {
        $base_dir = PRAISON_CONTENT_DIR;

        if ( ! is_dir( $base_dir ) ) {
            \WP_CLI::error( "Content directory does not exist: {$base_dir}" );
            return;
        }

        $types   = [];
        $verbose = \WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose', false );

        if ( ! empty( $assoc_args['type'] ) ) {
            $types[] = $assoc_args['type'];
        } else {
            // Discover all subdirectories (exclude hidden dirs and config/)
            foreach ( scandir( $base_dir ) as $item ) {
                if ( $item[0] === '.' || $item === 'config' ) {
                    continue;
                }
                if ( is_dir( $base_dir . '/' . $item ) ) {
                    $types[] = $item;
                }
            }
        }

        if ( empty( $types ) ) {
            \WP_CLI::warning( 'No post type directories found.' );
            return;
        }

        foreach ( $types as $type ) {
            $this->indexType( $type, $base_dir, $verbose );
        }
    }

    /**
     * Index all .md files in one post-type directory.
     *
     * @param string $type     Post type slug (= directory name).
     * @param string $base_dir Root content directory.
     * @param bool   $verbose  Print per-file output.
     */
    private function indexType( $type, $base_dir, $verbose ) {
        $dir = $base_dir . '/' . $type;

        if ( ! is_dir( $dir ) ) {
            \WP_CLI::warning( "Directory not found, skipping: {$dir}" );
            return;
        }

        $files = glob( $dir . '/*.md' );

        // Include one level of subdirectories for hierarchical organisation.
        $sub_files = glob( $dir . '/*/*.md' );
        if ( ! empty( $sub_files ) ) {
            $files = array_merge( $files, $sub_files );
        }

        if ( empty( $files ) ) {
            \WP_CLI::warning( "No .md files found in {$dir}" );
            return;
        }

        $index   = [];
        $parser  = new \PraisonPress\Parsers\FrontMatterParser();

        foreach ( $files as $file ) {
            $raw    = file_get_contents( $file );
            $parsed = $parser->parse( $raw );
            $meta   = $parsed['metadata'] ?? [];

            // Skip files without required fields.
            if ( empty( $meta['title'] ) || empty( $meta['slug'] ) ) {
                if ( $verbose ) {
                    \WP_CLI::warning( "Skipping (missing title/slug): " . basename( $file ) );
                }
                continue;
            }

            // Store a relative path so the index is portable.
            $relative = str_replace( $dir . '/', '', $file );

            $entry = [
                'file'     => $relative,
                'title'    => $meta['title'],
                'slug'     => $meta['slug'],
                'status'   => $meta['status']   ?? 'publish',
                'author'   => $meta['author']   ?? 'admin',
                'date'     => $meta['date']      ?? '',
                'modified' => $meta['modified'] ?? '',
                'excerpt'  => $meta['excerpt']  ?? '',
            ];

            // Preserve any extra front-matter fields.
            $reserved = [ 'title', 'slug', 'status', 'author', 'date', 'modified', 'excerpt', 'content' ];
            foreach ( $meta as $k => $v ) {
                if ( ! in_array( $k, $reserved, true ) ) {
                    $entry[ $k ] = $v;
                }
            }

            $index[] = $entry;

            if ( $verbose ) {
                \WP_CLI::log( "  indexed: {$relative}" );
            }
        }

        $index_file = $dir . '/_index.json';
        file_put_contents( $index_file, wp_json_encode( $index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

        // Touch the directory so CacheManager picks up the change.
        touch( $dir );

        \WP_CLI::success( sprintf(
            '[%s] Indexed %d file(s) → %s',
            $type,
            count( $index ),
            $index_file
        ) );
    }
}
