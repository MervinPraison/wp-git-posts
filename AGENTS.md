# WP Git Posts - Agent Instructions

Instructions for AI agents working on this project.

---

## 1. Project Structure

```
PraisonAI-Git-Posts/
├── praisonpressgit.php      # Main plugin file (version here)
├── readme.txt               # WordPress.org readme
├── version.txt              # Version number
├── PERFORMANCE.md           # Scale guide for large deployments
├── src/                     # PHP source code
│   ├── Core/               # Bootstrap, Router
│   ├── Loaders/            # PostLoader (file → WP_Post)
│   ├── Cache/              # CacheManager, SmartCacheInvalidator
│   ├── Parsers/            # FrontMatterParser, MarkdownParser
│   ├── CLI/                # IndexCommand (wp praison index)
│   └── Database/           # DB tables
├── views/                   # Templates
├── assets/                  # CSS, JS, images
├── scripts/                 # Build scripts
└── docs/                    # Documentation
```

---

## 2. Version Management

**Single Source of Truth:** `praisonpressgit.php` line 5

```php
Version: 1.0.9
```

Also update `version.txt` and `PRAISON_VERSION` constant.

---

## 3. Key Classes

| Class | Purpose |
|-------|---------|
| `Bootstrap` | Plugin initialization, `posts_pre_query` injection |
| `PostLoader` | Load/cache/filter file-based posts |
| `CacheManager` | Transient caching (O(1) dir-mtime key) |
| `SmartCacheInvalidator` | Cache clear on PR merge |
| `FrontMatterParser` | YAML parser (inline arrays, bool, int, null coercion) |
| `MarkdownParser` | Markdown → HTML (Parsedown if available) |
| `IndexCommand` | WP-CLI `wp praison index` |
| `SubmissionsTable` | User submissions DB table |

---

## 4. Helper Functions

```php
// Get posts from files
$posts = praison_get_posts(['limit' => 10]);

// Clear all caches
praison_clear_cache();

// Get statistics
$stats = praison_get_stats();
```

---

## 5. Content Directory

Default: `wp-content/uploads/praison-content/`

Override in wp-config.php:
```php
define('PRAISON_CONTENT_DIR', '/custom/path');
```

---

## 6. Release Process

```bash
./release.sh
```

---

## 7. Testing Checklist

- [ ] Plugin activates without errors
- [ ] Content directories created on activation
- [ ] `wp praison index` generates valid `_index.json`
- [ ] Archive page `/posts/` returns 200 and lists published posts
- [ ] Single post `/posts/{slug}/` returns 200
- [ ] Draft posts return 404 (not leaked into archive or feed)
- [ ] Category archive shows only posts with matching `categories` front matter
- [ ] Tag archive shows only posts with matching `tags` front matter
- [ ] RSS feed works, excludes drafts
- [ ] SEO plugin reads title and excerpt meta description
- [ ] Cache clears with `praison_clear_cache()`
- [ ] YAML boolean `true`/`false` parsed as PHP bool (not string)
- [ ] YAML inline array `[a, b]` parsed as PHP array

## 8. Known Limitations

| Area | Status |
|------|--------|
| WP core search (`?s=`) | ❌ File posts not in DB |
| REST API (`/wp-json/wp/v2/`) | ❌ `show_in_rest=false` |
| WP-CLI `wp post list` | ❌ Injection skipped in CLI context |
| `category__in`, `tag__in` (array) | ⚠️ Only string `category_name`/`tag` supported |
