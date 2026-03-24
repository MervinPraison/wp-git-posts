# Performance Guide

## Scale Considerations

### Cold-Start Cost (`loadAllPosts`)

On the first request (cache miss), `PostLoader::loadAllPosts()` calls `glob('*.md')` over the **entire post type directory**. This is fast for small directories but becomes a bottleneck at scale:

| File count | Estimated cold-start |
|---|---|
| < 1,000 | Negligible (<20ms) |
| 1,000–10,000 | Noticeable (50–200ms) |
| 10,000+ | Slow (500ms+) — use `_index.json` |
| 100,000+ | Unacceptable without index |

### The `_index.json` Fast Path

If a `_index.json` file exists in the post type directory, the plugin skips `glob()` entirely and reads only the specific file needed for the current query. **This is mandatory for large directories.**

**Format:**
```json
[
  {"file": "my-post.md", "title": "My Post Title", "slug": "my-post", "status": "publish", "author": "admin", "date": "2024-01-01 00:00:00"}
]
```

**Generate with a build script** (run on content changes / via webhook):
```bash
#!/bin/bash
# Usage: ./build-index.sh content/posts
DIR="${1:-content/posts}"
echo "[" > "$DIR/_index.json"
first=true
for f in "$DIR"/*.md; do
  title=$(grep '^title:' "$f" | head -1 | sed 's/title: *//')
  slug=$(grep '^slug:' "$f" | head -1 | sed 's/slug: *//')
  status=$(grep '^status:' "$f" | head -1 | sed 's/status: *//')
  date=$(grep '^date:' "$f" | head -1 | sed 's/date: *//')
  base=$(basename "$f")
  $first || echo "," >> "$DIR/_index.json"
  echo "  {\"file\":\"$base\",\"title\":$title,\"slug\":$slug,\"status\":$status,\"date\":$date}" >> "$DIR/_index.json"
  first=false
done
echo "]" >> "$DIR/_index.json"
```

### Transient TTL

Default cache TTL is **3600 seconds (1 hour)**. The cache key includes the directory's latest file modification time (`filemtime`), so adding or editing a file automatically invalidates the cache on the next request.

With a Redis object cache active, transients are stored in memory — cache reads are fast regardless of file count.

### `posts_pre_query` Overhead

The plugin hooks into `posts_pre_query` on every WordPress query. If no `content/{post_type}/` directory exists, the filter returns early with zero overhead. Only active post types (with a corresponding directory) incur any file I/O.

## Recommendations for Large Sites

1. **Always use `_index.json`** for any directory with more than 1,000 files.
2. **Regenerate the index** on file changes via a CI/CD step or a GitHub webhook.
3. **Point `PRAISON_CONTENT_DIR`** to a path on a fast local disk (not NFS or a network share).
4. **Use Redis** as the WordPress object cache backend so transients are served from memory.
5. **Do not create a content directory** for post types already managed by the WordPress database — the `posts_pre_query` filter will attempt to load and merge, which can conflict.
