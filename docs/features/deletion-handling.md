# Deletion Handling

When posts are deleted from the WordPress dashboard, the plugin automatically manages the corresponding `.md` files and `_index.json` entries to keep everything in sync.

## Supported Actions

| WordPress Action | Plugin Response |
|-----------------|-----------------|
| **Trash** a post | Deletes `.md` file, removes from `_index.json`, commits + pushes to Git |
| **Permanently delete** | Deletes `.md` file, removes from `_index.json`, commits + pushes to Git |
| **Untrash** (restore) | Re-exports the `.md` file, re-adds to `_index.json`, commits + pushes to Git |

## How It Works

### Trashing a Post

When a post is moved to trash:

1. The AutoExporter's `wp_trash_post` hook fires
2. Locates the corresponding `.md` file by slug
3. Deletes the `.md` file from the content directory
4. Calls `IndexManager::remove()` to update `_index.json`
5. Stages the deletion with `git add -A` (which stages file removals)
6. Commits with message: `Deleted: {post_slug}`
7. Pushes to the configured Git remote

### Permanent Deletion

Same flow as trashing — the `before_delete_post` hook fires and handles cleanup.

### Restoring from Trash

When a post is restored:

1. The AutoExporter's `untrash_post` hook fires
2. Re-exports the post content to a new `.md` file
3. Calls `IndexManager::addOrUpdate()` to re-add to `_index.json`
4. Commits and pushes to Git

## Git Staging

The plugin uses `git add -A` instead of `git add .` to ensure:

- **File additions** are staged ✅
- **File modifications** are staged ✅
- **File deletions** are staged ✅

This was a critical fix — previously, deletions were not staged because `git add .` only handles additions and modifications.

## Deletion from Git Side

When a `.md` file is deleted via Git (and pushed/webhook triggers a pull):

1. SyncManager runs `git pull`
2. Parses `git diff --name-status` output
3. Detects `D` (Deleted) status for the file
4. Calls `IndexManager::remove()` to update `_index.json`
5. The post is no longer served on the frontend

## Safety

- **No database changes**: Deletion only affects `.md` files and `_index.json`
- **Git history**: All deletions are tracked in Git history and can be reverted
- **Concurrency safe**: `flock()` prevents index corruption during parallel deletions
