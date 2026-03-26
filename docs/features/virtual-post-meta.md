# Virtual Post Meta

Headless (file-based) posts now support `get_post_meta()`, `get_field()`, and other WordPress meta functions. Custom fields defined in your Markdown frontmatter are accessible through the standard WordPress API.

## Overview

File-based posts use negative IDs (e.g., `-2867413659`) to avoid collisions with database posts. WordPress's metadata system is designed for database-backed posts, so the plugin bridges the gap by:

1. Reading custom fields from the `_index.json` manifest
2. Pre-populating the WordPress metadata cache
3. Intercepting `get_post_metadata` filter calls

## How It Works

### Frontmatter → Meta

When you define custom fields in your Markdown frontmatter:

```yaml
---
title: "My Recipe"
slug: "chocolate-cake"
status: "publish"
date: "2026-01-15 10:00:00"
author: "admin"
prep_time: "30 minutes"
servings: 8
difficulty: "Medium"
ingredients:
  - "Flour"
  - "Sugar"
  - "Cocoa"
---

# Chocolate Cake

Your content here...
```

The structural fields (`title`, `slug`, `status`, `date`, `author`) are used for the WP_Post object. All remaining fields (`prep_time`, `servings`, `difficulty`, `ingredients`) become accessible via `get_post_meta()`.

### Using in Templates

```php
// In your theme's single-recipes.php template:
$prep_time  = get_post_meta(get_the_ID(), 'prep_time', true);
$servings   = get_post_meta(get_the_ID(), 'servings', true);
$difficulty = get_post_meta(get_the_ID(), 'difficulty', true);
$ingredients = get_post_meta(get_the_ID(), 'ingredients', true);

echo "<p>Prep: $prep_time | Serves: $servings | Difficulty: $difficulty</p>";

// Works with ACF too
$prep_time = get_field('prep_time');
```

### Querying All Meta

```php
// Get all custom fields for a post
$all_meta = get_post_meta(get_the_ID());
// Returns: ['prep_time' => ['30 minutes'], 'servings' => [8], ...]
```

## Technical Details

### WordPress absint() Compatibility

WordPress's `get_metadata_raw()` calls `absint()` on the post ID before any processing. This converts negative IDs (e.g., `-2867413659`) to their positive equivalent (`2867413659`). The plugin stores meta under both keys to ensure compatibility.

### Cache Serialization Safety

When Redis or Memcached caches WP_Post objects, extra properties added at runtime are stripped during serialization. The plugin handles this by reading meta directly from `_index.json` on every request, using slug-based lookups with a static in-memory cache.

### Structural vs Custom Fields

The following frontmatter keys are treated as structural (used for WP_Post properties, not meta):

| Key | Purpose |
|-----|---------|
| `title` | `post_title` |
| `slug` | `post_name` |
| `status` | `post_status` |
| `date` | `post_date` |
| `modified` | `post_modified` |
| `author` | `post_author` |
| `excerpt` | `post_excerpt` |
| `categories` | Category assignment |
| `tags` | Tag assignment |
| `featured_image` | Post thumbnail |
| `custom_fields` | Legacy nested fields container |

**Everything else** is treated as a custom field and accessible via `get_post_meta()`.

## Performance

- **Zero overhead for database posts**: The meta filter short-circuits for positive (database) post IDs
- **Index caching**: `_index.json` is loaded once per request and cached in a static variable
- **No database queries**: Virtual meta comes entirely from the filesystem
