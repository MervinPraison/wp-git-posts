<?php
namespace PraisonPress\Index;

if ( ! defined( 'ABSPATH' ) ) exit;

use PraisonPress\Parsers\FrontMatterParser;

/**
 * IndexManager — Incremental _index.json operations.
 *
 * Provides O(1)-per-post index updates instead of scanning all files.
 *   IndexManager::addOrUpdate('lyrics', 'my-song', '/path/to/my-song.md');
 *   IndexManager::remove('lyrics', 'my-song');
 *   IndexManager::fullRebuild('lyrics');
 */
class IndexManager {

    /** Reserved frontmatter keys (mirrored from IndexCommand). */
    private static $reserved = [ 'title', 'slug', 'status', 'author', 'date', 'modified', 'excerpt', 'content' ];

    /**
     * Add or update a single entry in _index.json for the given post type.
     *
     * @param string $type   Post type directory name (e.g. 'lyrics').
     * @param string $slug   Post slug.
     * @param string $mdPath Absolute path to the .md file.
     * @return bool
     */
    public static function addOrUpdate( string $type, string $slug, string $mdPath ): bool {
        if ( ! file_exists( $mdPath ) ) {
            return false;
        }

        $dir        = PRAISON_CONTENT_DIR . '/' . $type;
        $indexFile  = $dir . '/_index.json';

        // Parse the single .md file.
        $raw    = file_get_contents( $mdPath );
        $parser = new FrontMatterParser();
        $parsed = $parser->parse( $raw );
        $meta   = $parsed['metadata'] ?? [];

        if ( empty( $meta['title'] ) || empty( $meta['slug'] ) ) {
            return false;
        }

        // Build entry (same logic as IndexCommand lines 120-137).
        $relative = str_replace( $dir . '/', '', $mdPath );
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

        // Include all non-reserved keys (custom_fields, categories, tags, etc).
        foreach ( $meta as $k => $v ) {
            if ( ! in_array( $k, self::$reserved, true ) ) {
                $entry[ $k ] = $v;
            }
        }

        // Read existing index (or start fresh).
        $index = self::readIndex( $indexFile );

        // Upsert: find by slug and replace, or append.
        $found = false;
        foreach ( $index as $i => $existing ) {
            if ( ( $existing['slug'] ?? '' ) === $slug ) {
                $index[ $i ] = $entry;
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            $index[] = $entry;
        }

        return self::atomicWrite( $indexFile, $index, $dir );
    }

    /**
     * Remove a single entry from _index.json by slug.
     *
     * @param string $type Post type directory name.
     * @param string $slug Post slug to remove.
     * @return bool
     */
    public static function remove( string $type, string $slug ): bool {
        $dir       = PRAISON_CONTENT_DIR . '/' . $type;
        $indexFile = $dir . '/_index.json';

        $index = self::readIndex( $indexFile );
        $original_count = count( $index );

        $index = array_values( array_filter( $index, function ( $entry ) use ( $slug ) {
            return ( $entry['slug'] ?? '' ) !== $slug;
        } ) );

        // Nothing changed.
        if ( count( $index ) === $original_count ) {
            return true;
        }

        return self::atomicWrite( $indexFile, $index, $dir );
    }

    /**
     * Full rebuild — scans all .md files. Use sparingly (initial setup / repair).
     *
     * @param string $type Post type directory name.
     * @return int Number of entries indexed.
     */
    public static function fullRebuild( string $type ): int {
        $dir = PRAISON_CONTENT_DIR . '/' . $type;
        if ( ! is_dir( $dir ) ) {
            return 0;
        }

        $files = glob( $dir . '/*.md' );
        $subFiles = glob( $dir . '/*/*.md' );
        if ( ! empty( $subFiles ) ) {
            $files = array_merge( $files, $subFiles );
        }

        $index  = [];
        $parser = new FrontMatterParser();

        foreach ( $files as $file ) {
            $raw    = file_get_contents( $file );
            $parsed = $parser->parse( $raw );
            $meta   = $parsed['metadata'] ?? [];

            if ( empty( $meta['title'] ) || empty( $meta['slug'] ) ) {
                continue;
            }

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

            foreach ( $meta as $k => $v ) {
                if ( ! in_array( $k, self::$reserved, true ) ) {
                    $entry[ $k ] = $v;
                }
            }

            $index[] = $entry;
        }

        $indexFile = $dir . '/_index.json';
        self::atomicWrite( $indexFile, $index, $dir );

        return count( $index );
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------*/

    private static function readIndex( string $indexFile ): array {
        if ( ! file_exists( $indexFile ) ) {
            return [];
        }
        $data = json_decode( file_get_contents( $indexFile ), true );
        return is_array( $data ) ? $data : [];
    }

    /**
     * Atomic write with flock to prevent concurrent corruption.
     */
    private static function atomicWrite( string $indexFile, array $index, string $dir ): bool {
        $lockFile = $indexFile . '.lock';
        $lock     = fopen( $lockFile, 'w' );

        if ( ! $lock || ! flock( $lock, LOCK_EX ) ) {
            if ( $lock ) fclose( $lock );
            return false;
        }

        try {
            $tmpFile = $indexFile . '.tmp.' . getmypid();
            $json    = wp_json_encode( $index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            file_put_contents( $tmpFile, $json );
            rename( $tmpFile, $indexFile );
            touch( $dir ); // Signal CacheManager.

            // QM logging.
            if ( get_option( 'praisonpress_qm_logging' ) ) {
                do_action( 'qm/info', sprintf(
                    '[PraisonPress] Index updated: %d entries in %s',
                    count( $index ),
                    basename( $dir )
                ) );
            }

            return true;
        } finally {
            flock( $lock, LOCK_UN );
            fclose( $lock );
        }
    }
}
