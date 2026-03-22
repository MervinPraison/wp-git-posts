# File-Based Content

Store posts, pages, and custom post types as Markdown files.

## Content Format

Create `.md` files with YAML front matter:

```markdown
---
title: "My Post Title"
slug: "my-post-slug"
author: "admin"
date: "2024-10-31 12:00:00"
status: "publish"
categories:
  - "General"
tags: [markdown, wordpress]
featured: true
order: 5
featured_image: "https://example.com/image.jpg"
excerpt: "A short description used for SEO meta description."
---

# Your content here

Write your content in **Markdown** format.
```

## YAML Front Matter Reference

| Field | Required | Type | Description |
|-------|----------|------|-------------|
| `title` | ✅ | string | Post title |
| `slug` | ✅ | string | URL slug |
| `status` | ❌ | string | `publish`, `draft`, `private` — defaults to `publish` |
| `date` | ❌ | string | Publication date `"YYYY-MM-DD HH:MM:SS"` |
| `author` | ❌ | string | WordPress username |
| `categories` | ❌ | list or inline | Category names — controls which category archives show this post |
| `tags` | ❌ | list or inline | Tag slugs — controls which tag archives show this post |
| `featured_image` | ❌ | string | URL to featured image |
| `excerpt` | ❌ | string | Used as SEO meta description |
| Any custom field | ❌ | any | Stored as post property for ACF compatibility |

### Inline vs block list syntax — both work

```yaml
# Inline (YAML array)
tags: [php, wordpress, markdown]

# Block list
tags:
  - php
  - wordpress
  - markdown
```

### Boolean and numeric values

```yaml
featured: true      # PHP bool true
draft: false        # PHP bool false
order: 5            # PHP int 5
rating: 4.5         # PHP float 4.5
```

## Post Status Behaviour

| `status` value | Archive | Single URL | Feed | Admin |
|---|---|---|---|---|
| `publish` | ✅ Visible | ✅ 200 | ✅ In feed | ✅ |
| `draft` | ❌ Hidden | ❌ 404 | ❌ Hidden | ✅ |
| `private` | ❌ Hidden | ❌ 404 (logged out) | ❌ Hidden | ✅ |

> **Important:** Posts default to `publish`-only visibility — drafts are **never** leaked into archives,
> feeds, or taxonomy pages even if no `status` is explicitly set.

## Category and Tag Archives

File-based posts appear in WordPress category and tag archives based on the
`categories` and `tags` front-matter fields. Each field value is matched
against the archive's slug (via `sanitize_title()`).

```yaml
categories:
  - "Christian Music"     # appears in /category/christian-music/
tags: [worship, tamil]    # appears in /tag/worship/ and /tag/tamil/
```

A file-based post only appears in archives that match its declared categories/tags —
it will **not** appear on unrelated archives.

## Directory Structure

```
PRAISON_CONTENT_DIR/
├── posts/          # Blog posts (URL: /posts/{slug}/)
├── pages/          # Static pages (URL: /pages/{slug}/)
├── {any-name}/     # Auto-registered as custom post type
└── config/         # Reserved — not registered as post type
```

Each directory is auto-discovered and registered as a WordPress custom post type.
The directory name becomes the URL slug and the registered post type slug.

> **Special case:** `posts/` directory registers as post type `praison_post`
> (avoids conflict with the built-in `post` type).

## Known Limitations

| Feature | Status | Notes |
|---------|--------|-------|
| WP core search (`?s=`) | ❌ | File posts not in DB — not indexed |
| REST API (`/wp-json/`) | ❌ | `show_in_rest=false` |
| WP-CLI `wp post list` | ❌ | Injection skipped in CLI context |
| Category/tag by ID | ⚠️ | Only `category_name` and `tag` string slugs supported |
