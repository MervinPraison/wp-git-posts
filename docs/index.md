# WP Git Posts

Load WordPress content from files without database writes. Bidirectional Git sync keeps your content repository and WordPress site perfectly in sync.

```mermaid
graph LR
    A[📁 Git Repo] -->|push| B[🔌 Plugin]
    B -->|pull| A
    B --> C[🌐 WordPress]
    C -->|auto-export| B
    
    style A fill:#14B8A6,stroke:#7C90A0,color:#fff
    style B fill:#6366F1,stroke:#7C90A0,color:#fff
    style C fill:#10B981,stroke:#7C90A0,color:#fff
```

## Quick Start

1. **Install** → Upload plugin to WordPress
2. **Create** → Add Markdown files to `content/` directory
3. **Sync** → Changes flow both ways automatically

No database writes! 🎉

## Key Features

| Feature | Description |
|---------|-------------|
| 🔄 Bidirectional Sync | Dashboard ↔ Git automatic synchronization |
| ⚡ Incremental Indexing | O(1) updates (~10ms per post change) |
| 📋 Virtual Post Meta | `get_post_meta()` works for file-based posts |
| 🗑️ Deletion Handling | Trash/delete auto-manages `.md` files and index |
| 👥 Collaborative Editing | Submit edits via pull requests |
| ☁️ Cloud Native | Docker, Kubernetes, multi-pod ready |
| 🔒 Concurrency Safe | `flock()` locking for parallel operations |
| ⚡ No DB Writes | Fast, portable, version-controlled |

## v1.8.0 Highlights

- **Bidirectional sync**: Edit in WordPress → auto-push to Git. Push to Git → auto-import to WordPress
- **Incremental indexing**: No more full rebuilds. Each post change updates `_index.json` in ~10ms
- **Virtual post meta**: `get_post_meta()`, `get_field()` work seamlessly with file-based posts
- **Deletion handling**: Trash/delete/restore automatically managed across Git and WordPress

## Next Steps

- [Installation](getting-started/installation.md)
- [Configuration](getting-started/configuration.md)
- [Bidirectional Git Sync](features/bidirectional-sync.md)
- [Incremental Indexing](features/incremental-indexing.md)
- [Virtual Post Meta](features/virtual-post-meta.md)
- [Deletion Handling](features/deletion-handling.md)

