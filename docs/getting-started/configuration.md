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

| Directory | Content Type |
|-----------|--------------|
| `posts/` | Blog posts |
| `pages/` | Static pages |
| `lyrics/` | Song lyrics |
| `recipes/` | Recipe content |
| `config/` | Configuration files |

## Done!

Start adding content files. [Learn about file formats →](../features/file-content.md)
