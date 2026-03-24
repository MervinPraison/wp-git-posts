# Performance & Caching

## Cache Key Strategy (v1.0.7+)

Cache keys are built as: `praisonpress_{type}_{dir_mtime}_{md5(params)}`

The directory `mtime` is a **single O(1) syscall** — the OS updates it whenever
a file is added, renamed, or removed, so the cache auto-invalidates on any
content change without scanning individual files.

> **Before v1.0.7:** keys used `glob()` + `filemtime()` on every file — O(n)
> on every request, unusable at 100k+ files.

## Single-Post Slug Fast Path (v1.0.7+)

When WordPress requests a single post by slug (e.g. `/posts/hello-world/`),
the plugin reads only **one file** instead of loading everything:

1. Checks `_index.json` → finds `{"file":"hello-world.md","slug":"hello-world",...}`
2. Reads that one `.md` file
3. Caches the result under `praisonpress_posts_{mtime}_{md5(slug)}`

Without `_index.json`, it falls back to a full directory scan.

## Generating the Index

Run after adding/modifying content:

```bash
# All post types
wp praison index

# Specific type
wp praison index --type=posts

# Verbose (shows each file)
wp praison index --type=posts --verbose
```

Add this to CI/CD or a GitHub webhook so the index stays current.

## Scale Reference

| File count | Without `_index.json` | With `_index.json` |
|---|---|---|
| < 1,000 | ~20ms | ~5ms |
| 1,000–10,000 | 50–200ms | ~5ms |
| 10,000–100,000 | 500ms+ | ~10ms |
| 100,000+ | **Unusable** | ~10ms |

## Cache Invalidation

Cache auto-invalidates when:
- A file is **added or removed** (directory mtime changes → new cache key)
- **TTL expires** (default 3600 s / 1 hour)
- **Manual clear:**

```bash
# WP-CLI
wp eval 'praison_clear_cache();'

# Or via Admin Dashboard — PraisonPress → Clear Cache
```

## Object Cache (Redis)

Transients are stored in Redis when a Redis object cache plugin is active —
cache reads are then in-memory with no DB hit.

```php
// wp-config.php
define('WP_REDIS_HOST', 'redis-service');
define('WP_REDIS_PORT', 6379);
```

## Taxonomy Archive Cache Keys

Each taxonomy archive (category, tag) gets its **own cache entry**:
- `/category/general/` → key includes `category_name=general`
- `/tag/markdown/`     → key includes `tag=markdown`

This prevents one archive's results being served for another.

## Object Cache Filter

```php
add_filter('praison_cache_ttl', function($ttl, $post_type) {
    return 7200; // 2 hours
}, 10, 2);
```

## Recommendations for Large Sites

1. Always generate `_index.json` before traffic (`wp praison index`)
2. Point `PRAISON_CONTENT_DIR` to fast local storage (not NFS)
3. Use Redis as the object cache backend
4. Regenerate the index in CI on each content deploy
5. Do not create a content directory for post types managed by the database
