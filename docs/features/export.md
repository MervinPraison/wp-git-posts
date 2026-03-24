# Export to Markdown

Convert existing WordPress content to Markdown files with full metadata preservation.

## Overview

The Export feature allows you to convert your entire WordPress site to Markdown files, making it easy to migrate to a file-based workflow.

## Export Methods

### Admin Panel Export

1. Go to **PraisonPress → Export**
2. Select post type (or "All Post Types")
3. Choose batch size (100 recommended for 50K+ posts)
4. Click "Start Export"
5. Background processing with real-time progress tracking

### CLI Export

```bash
php wp-content/plugins/praisonpressgit/scripts/export-to-markdown.php
```

## What Gets Exported

| Content | Included |
|---------|----------|
| Post title | ✅ |
| Post content | ✅ |
| Post excerpt | ✅ |
| Post status | ✅ |
| Publication dates | ✅ |
| Categories | ✅ |
| Tags | ✅ |
| Custom taxonomies | ✅ |
| Featured images | ✅ |
| Custom fields | ✅ |
| ACF fields | ✅ |
| Author info | ✅ |

## Export Configuration

Configure export behavior in `export-config.ini`:

```ini
[posts]
directory = "posts"
structure = "flat"
filename_pattern = "{date}-{slug}.md"

[lyrics]
directory = "lyrics"
structure = "alphabetical"
alphabetical_field = "title"
```

## Directory Structures

| Structure | Example |
|-----------|---------|
| `flat` | `/content/posts/my-post.md` |
| `alphabetical` | `/content/posts/a/amazing-post.md` |
| `date` | `/content/posts/2025/01/my-post.md` |
| `hierarchical` | `/content/bible/genesis/1/1.md` |
| `category` | `/content/posts/news/my-news.md` |

## Large-Scale Exports

Tested with 50,000+ posts:
- Background processing
- Batch processing to avoid timeouts
- Progress tracking
- Resume support
