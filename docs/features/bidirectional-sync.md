# Bidirectional Git Sync

Automatically synchronize content between your WordPress dashboard and a Git repository. Changes flow both ways — edit in WordPress and it pushes to Git, push to Git and WordPress picks it up.

## How It Works

```
Dashboard Edit                    Git Repository
     │                                 │
     ▼                                 ▼
AutoExporter                      SyncManager
  • Save as .md                    • git pull
  • Update _index.json             • git diff --name-status
  • git commit + push              • Process A/M/D/R files
     │                                 │
     ▼                                 ▼
  GitHub Repo  ◄──────────────►  WordPress Site
```

### Dashboard → Git (Auto-Export)

When a post is created or updated in the WordPress admin:

1. **Export**: The post content and metadata are saved as a `.md` file in the content directory
2. **Index**: The `_index.json` manifest is incrementally updated (~10ms)
3. **Commit**: Changes are committed with an auto-generated message
4. **Push**: The commit is pushed to the configured Git remote

### Git → WordPress (Auto-Import)

When changes are pushed to the Git repository:

1. **Webhook**: GitHub sends a push event to your WordPress webhook endpoint
2. **Pull**: SyncManager runs `git pull` to fetch new commits
3. **Diff**: Parses `git diff --name-status` to identify changed files
4. **Process**: Each file is processed based on its status:
   - **A** (Added): New `.md` file → added to `_index.json`
   - **M** (Modified): Updated `.md` file → updated in `_index.json`
   - **D** (Deleted): Removed `.md` file → removed from `_index.json`
   - **R** (Renamed): Old entry removed, new entry added

## Setup

### 1. Configure Git Remote

In `site-config.ini`:

```ini
[github]
repo_url = "https://github.com/your-org/your-content-repo.git"
branch = "main"
auto_push = true
auto_sync = true
```

### 2. Set Up Webhook (for Git → WordPress)

1. Go to your GitHub repository → Settings → Webhooks
2. Add webhook:
   - **URL**: `https://yoursite.com/wp-json/praison/v1/webhook`
   - **Content type**: `application/json`
   - **Events**: Push events
   - **Secret**: Match the secret in your `site-config.ini`

### 3. Enable Auto-Export (for Dashboard → Git)

In WordPress admin → PraisonPress → Settings:
- Enable **Auto-export on publish**
- Enable **Auto-push to Git**

Or in `site-config.ini`:

```ini
[export]
auto_export = true
auto_push = true
```

## File Statuses

| Action | `.md` File | `_index.json` | Git |
|--------|-----------|---------------|-----|
| Create post in dashboard | Created | Entry added | Commit + push |
| Update post in dashboard | Updated | Entry updated | Commit + push |
| Trash post in dashboard | Deleted | Entry removed | Commit + push |
| Delete post permanently | Deleted | Entry removed | Commit + push |
| Untrash post in dashboard | Restored | Entry re-added | Commit + push |
| Push new `.md` to Git | — | Entry added | Already in Git |
| Push modified `.md` to Git | — | Entry updated | Already in Git |
| Delete `.md` from Git | — | Entry removed | Already in Git |

## Concurrency Safety

All index operations use file locking (`flock()`) to prevent corruption:

- Multiple pods can safely update the index simultaneously
- Atomic file renaming ensures partial writes never corrupt the index
- Lock files are automatically cleaned up

## Performance

| Operation | Time | Complexity |
|-----------|------|------------|
| Add/update single entry | ~10ms | O(1) amortized |
| Remove single entry | ~5ms | O(1) amortized |
| Full index rebuild | ~30s (18K files) | O(N) |

The incremental approach means you almost never need a full rebuild — each individual post change is handled in milliseconds.
