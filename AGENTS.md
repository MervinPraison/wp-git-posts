# PraisonPressGit - Agent Instructions

Instructions for AI agents working on this project.

---

## 1. Project Structure

```
PraisonAI-Git-Posts/
├── praisonpressgit.php      # Main plugin file (version here)
├── readme.txt               # WordPress.org readme
├── version.txt              # Version number
├── src/                     # PHP source code
│   ├── Core/               # Bootstrap, Router
│   ├── Loaders/            # Content loaders
│   ├── Cache/              # Cache management
│   └── Database/           # DB tables
├── views/                   # Templates
├── assets/                  # CSS, JS, images
├── scripts/                 # Build scripts
└── docs/                    # Documentation
```

---

## 2. Version Management

**Single Source of Truth:** `praisonpressgit.php` line 5

```php
Version: 1.0.6
```

Also update `version.txt` and `PRAISON_VERSION` constant.

---

## 3. Key Classes

| Class | Purpose |
|-------|---------|
| `Bootstrap` | Plugin initialization |
| `PostLoader` | Load content from files |
| `CacheManager` | Content caching |
| `SubmissionsTable` | User submissions |

---

## 4. Helper Functions

```php
// Get posts from files
$posts = praison_get_posts(['limit' => 10]);

// Clear all caches
praison_clear_cache();

// Get statistics
$stats = praison_get_stats();
```

---

## 5. Content Directory

Default: `wp-content/uploads/praison-content/`

Override in wp-config.php:
```php
define('PRAISON_CONTENT_DIR', '/custom/path');
```

---

## 6. Release Process

```bash
./release.sh
```

---

## 7. Testing Checklist

- [ ] Plugin activates without errors
- [ ] Content directories created
- [ ] Markdown files load correctly
- [ ] JSON files parse properly
- [ ] YAML files work
- [ ] Submissions page functional
- [ ] Cache clears properly
