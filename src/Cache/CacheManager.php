<?php
namespace PraisonPress\Cache;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Simple cache manager using WordPress transients
 */
class CacheManager {
    
    private static $group = PRAISON_CACHE_GROUP;
    private static $default_ttl = 3600; // 1 hour
    
    /**
     * Get cached value
     * 
     * @param string $key Cache key
     * @return mixed|false Cached value or false if not found
     */
    public static function get($key) {
        $cache_key = self::buildKey($key);
        return get_transient($cache_key);
    }
    
    /**
     * Set cached value
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     * @return bool Success
     */
    public static function set($key, $value, $ttl = null) {
        if ($ttl === null) {
            $ttl = self::$default_ttl;
        }
        
        $cache_key = self::buildKey($key);
        return set_transient($cache_key, $value, $ttl);
    }
    
    /**
     * Delete cached value
     * 
     * @param string $key Cache key
     * @return bool Success
     */
    public static function delete($key) {
        $cache_key = self::buildKey($key);
        return delete_transient($cache_key);
    }
    
    /**
     * Clear all PraisonPress cache
     * 
     * @return int Number of cache entries cleared
     */
    public static function clearAll() {
        global $wpdb;
        
        $pattern = '_transient_' . self::$group . '_%';
        // Direct database query is necessary here for cache clearing
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $cleared = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
                $pattern,
                '_transient_timeout_' . self::$group . '_%'
            )
        );
        
        return $cleared;
    }
    
    /**
     * Check if cache is active
     * 
     * @return bool
     */
    public static function isActive() {
        // Test by setting and getting a value
        $test_key = 'test_' . time();
        self::set($test_key, 'test', 10);
        $result = self::get($test_key);
        self::delete($test_key);
        
        return $result === 'test';
    }
    
    /**
     * Build cache key with group prefix
     * 
     * @param string $key Original key
     * @return string Prefixed key
     */
    private static function buildKey($key) {
        return self::$group . '_' . $key;
    }
    
    /**
     * Get cache key for file-based content
     * 
     * @param string $type Content type (posts, pages)
     * @param array $params Query parameters
     * @return string Cache key
     */
    public static function getContentKey($type, $params = []) {
        $key_parts = [$type];

        // Use directory mtime for auto-invalidation — O(1) single syscall.
        // Linux/macOS update dir mtime whenever a file inside is added, removed, or renamed.
        // Previously used glob()+array_map('filemtime') which was O(n) — unusable at 100k+ files.
        $dir = PRAISON_CONTENT_DIR . '/' . $type;
        if (is_dir($dir)) {
            $key_parts[] = filemtime($dir);
        }

        // Add query params
        if (!empty($params)) {
            ksort($params);
            $key_parts[] = md5(serialize($params));
        }

        return implode('_', $key_parts);
    }
}
