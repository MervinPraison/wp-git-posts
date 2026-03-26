=== PraisonAI Git Posts ===
Contributors: mervinpraison
Tags: markdown, git, content-management, file-based, version-control
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Load WordPress content from files (Markdown, JSON, YAML) without database writes, with Git-based version control.

== Description ==

**PraisonAI Git Posts** is a revolutionary WordPress plugin that enables file-based content management with Git version control integration. Store your posts, pages, and custom post types as Markdown files while maintaining full WordPress compatibility.

= Key Features =

* **File-Based Content Management** - Store all content as Markdown files
* **No Database Writes** - Pure read-only approach for content
* **Git Version Control** - Track changes with full Git integration
* **Dynamic Post Type Discovery** - Automatically registers post types from directory structure
* **Custom URL Routing** - Beautiful URLs for any post type (e.g., `/recipes/song-name`)
* **YAML Front Matter** - Rich metadata support
* **Caching System** - Built-in performance optimization
* **Auto-Update Detection** - Content updates automatically when files change
* **WordPress Compatible** - Works with themes, plugins, and filters
* **Developer Friendly** - Clean, extensible architecture

= Perfect For =

* Developers who prefer Git workflows
* Teams collaborating on content
* Sites requiring version control
* Static site generators transitioning to WordPress
* Content stored in version control repositories

= How It Works =

1. Create a `content/` directory at your WordPress root
2. Add Markdown files with YAML front matter
3. Plugin automatically discovers and loads content
4. Create new post types by simply adding directories

= Example Post =

```markdown
---
title: "My Post Title"
slug: "my-post-slug"
author: "admin"
date: "2024-10-31 12:00:00"
status: "publish"
categories:
  - "General"
tags:
  - "example"
---

# Your content here

Write your content in **Markdown** format.
```

= Requirements =

* WordPress 5.0 or higher
* PHP 7.4 or higher
* Git (optional, for version control features)

== Installation ==

= Automatic Installation =

1. Go to Plugins > Add New
2. Search for "PraisonAI Git Posts"
3. Click "Install Now"
4. Activate the plugin

= Manual Installation =

1. Download the plugin ZIP file
2. Go to Plugins > Add New > Upload Plugin
3. Choose the downloaded ZIP file
4. Click "Install Now"
5. Activate the plugin

= After Installation =

1. Create a `content/` directory at your WordPress root level
2. Create subdirectories for post types (e.g., `content/posts/`, `content/pages/`)
3. Add Markdown files with YAML front matter
4. View your content on the frontend

= Configuration =

The plugin works out of the box with default settings. To customize the content directory location, add to `wp-config.php`:

`define('PRAISON_CONTENT_DIR', '/custom/path/to/content');`

Or use a filter:

`add_filter('praison_content_dir', function($dir) {
    return '/custom/path/to/content';
});`

== External Services ==

This plugin connects to external services for certain features:

= GitHub API =

**What it is:** GitHub's REST API (https://api.github.com) is used for version control and collaboration features.

**When it's used:** The plugin connects to GitHub API when you:
- Enable GitHub OAuth authentication
- Create or manage pull requests
- Sync content with a GitHub repository
- View pull request details and changes

**What data is sent:** 
- Repository information (owner, name)
- Authentication tokens (OAuth)
- Commit messages and file changes
- Pull request data

**User consent:** GitHub features are optional and only activated when you configure GitHub integration in the plugin settings. No data is sent to GitHub unless you explicitly enable and configure GitHub features.

**Service information:**
- Service provider: GitHub, Inc.
- Terms of service: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
- Privacy policy: https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement

== Frequently Asked Questions ==

= Does this replace the WordPress database? =

No. The WordPress database is still required for WordPress core functionality, user management, settings, etc. This plugin only replaces content storage (posts, pages) with file-based content.

= Can I use this with my existing WordPress site? =

Yes! The plugin works alongside existing database-based content. You can mix file-based and database-based content.

= What file formats are supported? =

Currently, Markdown (.md) files with YAML front matter are supported.

= How do I create a custom post type? =

Simply create a new directory in your content folder. For example, creating `content/recipes/` automatically registers a "recipes" post type.

= Does it work with WordPress themes? =

Yes! File-based posts work exactly like regular WordPress posts with all template tags and filters.

= Can I use WordPress plugins with this? =

Yes! The content is loaded as proper WP_Post objects, so plugins that modify content (like SEO plugins) work normally.

= How does caching work? =

The plugin uses WordPress transients for caching. Cache automatically invalidates when files are modified.

= How do I clear the cache? =

Go to PraisonAI Git Posts → Clear Cache in the WordPress admin, or use the top admin bar menu.

= Is Git required? =

No, Git is optional. The plugin works without Git, but version control features require Git to be installed.

= How do I track version history? =

The plugin includes Git integration. If Git is installed, file changes are automatically tracked. View history in PraisonAI Git Posts → Version History.

= Can I rollback to previous versions? =

Yes, if Git is available, you can rollback any file to a previous version from the Version History page.

== Screenshots ==

1. Admin dashboard showing file-based content statistics
2. Version history interface with Git commit tracking
3. Markdown file example with YAML front matter
4. Content directory structure

== Changelog ==

= 1.6.1 =
* CRITICAL FIX: Archive safeguard - refuses to scan >500 files without _index.json, preventing OOM
* Added WordPress Settings page admin notice for index rebuild feedback
* Removed disconnected content_dir settings field
* Fixed duplicate docblock on loadSinglePost

= 1.6.0 =
* NEW: WordPress Settings page for user-friendly configuration
* NEW: Index Status table with "Rebuild Index Now" button
* All settings stored in wp_options - no ini files or CLI needed
* Auto-generates _index.json on plugin activation

= 1.5.0 =
* PERFORMANCE: Lazy constructor - zero filesystem I/O at plugin load
* PERFORMANCE: loadFromIndex() creates WP_Post from index metadata only
* PERFORMANCE: loadSinglePost() direct slug lookup - 1 file read (was: full scan)
* NEW: content.enabled safety check - plugin does nothing unless enabled
* NEW: Cache stampede protection with wp_cache_add lock

= 1.0.9 =
* HOTFIX: FrontMatterParser - Added inline YAML array support ([a, b, c]), boolean coercion (true/false → PHP bool), numeric coercion, and null coercion
* HOTFIX: MarkdownParser - Removed str_replace('\n','<br>') that was injecting <br> tags inside block-level HTML elements (h1, ul, li, pre), causing invalid HTML
* HOTFIX: PostLoader - Fixed guid from query-string format to proper permalink format
* HOTFIX: PostLoader::loadFromIndex() - Fixed field name inconsistency (custom vs custom_fields)
* HOTFIX: SmartCacheInvalidator - Fixed transient key pattern to match keys actually generated by CacheManager

= 1.0.8 =
* HOTFIX: Draft posts now correctly excluded by default from archives, feeds, and taxonomy pages (default to publish-only)
* HOTFIX: Category and tag archives now filter file-based posts by declared front-matter categories/tags — prevents all posts appearing on every taxonomy archive
* HOTFIX: Cache keys now include category_name and tag query vars to prevent taxonomy archive cache collisions

= 1.0.7 =
* HOTFIX: CacheManager::getContentKey() - Replaced O(n) glob()+filemtime() with O(1) filemtime($dir) — critical for 100k+ file deployments
* HOTFIX: PostLoader - Added _index.json fast path for single-slug queries (O(1) lookup vs full directory scan)
* HOTFIX: Bootstrap::injectFilePosts() - Added is_dir() early bail to skip post types with no content directory
* HOTFIX: Bootstrap::injectFilePosts() - Fixed is_dir() alias resolution (praison_post maps to posts/ directory)
* Added WP-CLI command: wp praison index [--type=<type>] [--verbose] to generate _index.json manifest

= 1.0.6 =
* Added .distignore file for WP-CLI wp dist-archive command
* Updated create-zip.sh to exclude all .ini and .ini.example files
* Ensures no configuration example files are included in WordPress.org distribution

= 1.0.5 =
* SECURITY FIX: Properly blocked direct file access in scripts/export-to-markdown.php and create-my-submissions-page.php
* These files were attempting to load WordPress before checking ABSPATH, which defeated the security check
* All PHP files now immediately exit if accessed directly, as required by WordPress.org guidelines

= 1.0.4 =
* Fixed WordPress coding standards - prefixed all global variables with plugin prefix
* Resolved NonPrefixedVariableFound issue in export-to-markdown.php

= 1.0.3 =
* Fixed all WordPress.org plugin review issues
* Removed not permitted .ini.example files
* Moved all inline CSS/JS to properly enqueued external files
* Added comprehensive external service documentation for GitHub API
* Fixed file/directory location references to use WordPress-approved methods
* Added ABSPATH security checks to all PHP files
* Ready for WordPress.org approval

= 1.0.2 =
* Changed plugin name from "PraisonPressGit" to "PraisonAI Git Posts" to comply with WordPress trademark guidelines
* Updated text domain from 'praisonpressgit' to 'praison-file-content-git'
* Updated all internal references and cache keys
* No functional changes - maintains full backward compatibility

= 1.0.1 =
* Fixed all WordPress coding standards compliance issues
* Added proper output escaping throughout the plugin
* Implemented nonce verification for all GET parameters
* Fixed text domain consistency (changed to 'praisonpressgit')
* Added proper global variable prefixes
* Replaced deprecated functions (strip_tags → wp_strip_all_tags, mkdir → wp_mkdir_p)
* Improved database query security with proper prepared statements
* Added phpcs ignore comments for unavoidable false positives
* Plugin now passes WordPress.org plugin check with 0 warnings
* Ready for WordPress.org directory submission

= 1.0.0 =
* Initial release
* File-based content management with Markdown support
* Git version control integration
* GitHub OAuth and pull request management
* Custom post type support
* Built-in caching system

== Upgrade Notice ==

= 1.0.9 =
Hotfix: Fixes invalid HTML from the Markdown fallback parser, YAML inline array and boolean parsing, permalink format, and SmartCacheInvalidator transient pattern.

= 1.0.8 =
Hotfix: Fixes draft posts leaking into archives/feeds and file posts appearing on wrong category/tag archives.

= 1.0.7 =
Hotfix: Critical performance fixes for large deployments (100k+ files). Run `wp praison index` after updating to generate the fast-lookup index.

= 1.0.6 =
Distribution packaging fix. Ensures .ini.example files are excluded from WordPress.org submissions.

= 1.0.4 =
WordPress coding standards compliance update. Fixed variable naming conventions.

= 1.0.3 =
WordPress.org compliance update. Fixed all plugin review issues including security improvements and proper asset enqueuing.

= 1.0.2 =
Plugin renamed from "PraisonPressGit" to "PraisonAI Git Posts" to comply with WordPress trademark guidelines. No functional changes.

= 1.0.1 =
Major security and code quality improvements. Recommended update for all users.

= 1.0.0 =
Initial release. Install and activate to start using file-based content management.

== Development ==

* GitHub Repository: https://github.com/MervinPraison/PraisonAI-Git-Posts
* Report Issues: https://github.com/MervinPraison/PraisonAI-Git-Posts/issues
* Author Website: https://mer.vin

== Credits ==

Developed by MervinPraison
