# Configuration

Set up your content directory and Git integration.

```mermaid
graph LR
    A[⚙️ wp-config.php] --> B[📁 Content Dir]
    B --> C[🔄 Git Repo]
    
    style A fill:#6366F1,stroke:#7C90A0,color:#fff
    style B fill:#F59E0B,stroke:#7C90A0,color:#fff
    style C fill:#10B981,stroke:#7C90A0,color:#fff
```

## Custom Content Directory

Add to `wp-config.php`:

```php
define('PRAISON_CONTENT_DIR', '/path/to/your/content');
```

## Using a Filter

```php
add_filter('praison_content_dir', function($dir) {
    return '/custom/path/to/content';
});
```

## Environment Variables

Copy `.env.example` to `.env` and configure:

```env
GITHUB_TOKEN=your_token
GITHUB_REPO=owner/repo
```

## Content Structure

| Directory | Post Type Registered | URL Pattern |
|-----------|---------------------|-------------|
| `posts/` | `praison_post` | `/posts/{slug}/` |
| `pages/` | `pages` | `/pages/{slug}/` |
| `{any-name}/` | `{any-name}` | `/{any-name}/{slug}/` |
| `config/` | *(reserved)* | *(not registered)* |

## After Adding Content

Build the `_index.json` manifest for fast slug lookups:

```bash
# All types
wp praison index

# One specific type
wp praison index --type=posts
```

Start adding content files. [Learn about file formats →](../features/file-based-content.md)
