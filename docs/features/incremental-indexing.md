# Incremental Indexing

The IndexManager provides O(1) index operations for the `_index.json` manifest, replacing the previous full-rescan approach. This is critical for sites with thousands of content files.

## Overview

The `_index.json` file is a JSON manifest that lists all posts for a given post type, along with their metadata. It enables fast slug-based lookups without scanning the filesystem.

Previously, any change required rebuilding the entire index (O(N) operation). With v1.8.0, individual entries can be added, updated, or removed in ~10ms.

## API

### IndexManager::addOrUpdate()

Adds a new entry or updates an existing one by slug.

```php
use PraisonPress\Index\IndexManager;

$manager = new IndexManager();
$manager->addOrUpdate('recipes', 'chocolate-cake', [
    'file'    => 'chocolate-cake.md',
    'title'   => 'Chocolate Cake Recipe',
    'slug'    => 'chocolate-cake',
    'status'  => 'publish',
    'author'  => 'admin',
    'date'    => '2026-01-15 10:00:00',
    'excerpt' => 'A rich chocolate cake recipe',
]);
```

### IndexManager::remove()

Removes an entry by slug.

```php
$manager = new IndexManager();
$manager->remove('recipes', 'chocolate-cake');
```

### IndexManager::fullRebuild()

Rebuilds the entire index for a post type (use sparingly).

```php
$count = IndexManager::fullRebuild('recipes');
echo "Rebuilt: $count entries";
```

## How It Works

1. **Read**: Load existing `_index.json` into memory
2. **Modify**: Add, update, or remove the target entry
3. **Write**: Save to a temporary file, then atomically rename

### Concurrency Safety

```
Process A                    Process B
    │                            │
    ├─ flock(LOCK_EX) ──────►  (blocked)
    ├─ Read _index.json          │
    ├─ Modify entry              │
    ├─ Write _index.tmp          │
    ├─ rename() → _index.json   │
    ├─ flock(LOCK_UN) ──────►  flock(LOCK_EX)
    │                            ├─ Read _index.json (updated!)
    │                            ├─ Modify entry
    │                            └─ Write + rename
```

- Uses `flock(LOCK_EX)` for exclusive access
- Atomic `rename()` prevents partial writes
- Safe for multi-pod Kubernetes deployments with shared storage

## WP-CLI

```bash
# Full rebuild for a specific post type
wp praison index --type=recipes --verbose

# Rebuild all post types
wp praison index
```

## When Full Rebuild Is Needed

- After initial plugin installation
- After bulk importing content files
- If `_index.json` becomes corrupted (rare with flock)
- After changing the frontmatter schema

For normal operations (creating, editing, deleting posts), incremental updates handle everything automatically.
