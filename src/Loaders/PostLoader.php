<?php
namespace PraisonPress\Loaders;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use PraisonPress\Parsers\MarkdownParser;
use PraisonPress\Parsers\FrontMatterParser;
use PraisonPress\Cache\CacheManager;

/**
 * Load posts from Markdown files
 */
class PostLoader {
    
    private $parser;
    private $frontMatterParser;
    private $postsDir;
    private $postType;
    
    public function __construct($postType = 'posts') {
        $this->parser = new MarkdownParser();
        $this->frontMatterParser = new FrontMatterParser();
        $this->postType = $postType;
        $this->postsDir = PRAISON_CONTENT_DIR . '/' . $postType;
    }
    
    /**
     * Load posts from files based on query
     * 
     * @param \WP_Query $query WordPress query object
     * @return array Array of WP_Post objects
     */
    public function loadPosts($query) {
        $slug           = $query->get('name');
        $posts_per_page = $query->get('posts_per_page') ?: 10;

        // ── Fast path: single-post slug query ────────────────────────────────────
        // For archive/search queries we must load everything, but for a single post
        // we only need ONE file. Check _index.json first (O(1) lookup), then fall
        // back to full scan only if the index doesn't exist.
        if ($slug && !$query->get('s')) {
            $cache_key = CacheManager::getContentKey($this->postType, ['name' => $slug]);
            $cached    = CacheManager::get($cache_key);
            if ($cached !== false && is_array($cached)) {
                $this->setPaginationVars($query, $cached);
                return $cached['posts'];
            }

            $posts = $this->loadSinglePost($slug);

            $cache_data = [
                'posts'         => $posts,
                'found_posts'   => count($posts),
                'max_num_pages' => 1,
            ];
            CacheManager::set($cache_key, $cache_data, 3600);
            $this->setPaginationVars($query, $cache_data);
            return $posts;
        }

        // ── Normal path: archive / search / paginated query ───────────────────────
        $cache_key = CacheManager::getContentKey($this->postType, [
            'paged'          => $query->get('paged'),
            'posts_per_page' => $posts_per_page,
            's'              => $query->get('s'),
            'p'              => $query->get('p'),
            'category_name'  => $query->get('category_name'), // taxonomy archives need separate keys
            'tag'            => $query->get('tag'),
        ]);

        $cached = CacheManager::get($cache_key);
        if ($cached !== false && is_array($cached)) {
            $this->setPaginationVars($query, $cached);
            return $cached['posts'];
        }

        $all_posts      = $this->loadAllPosts();
        $filtered_posts = $this->filterPosts($all_posts, $query);

        usort($filtered_posts, function($a, $b) {
            return strtotime($b->post_date) - strtotime($a->post_date);
        });

        $paginated_posts = $this->applyPagination($filtered_posts, $query);

        $cache_data = [
            'posts'         => $paginated_posts,
            'found_posts'   => count($filtered_posts),
            'max_num_pages' => ceil(count($filtered_posts) / max(1, $posts_per_page)),
        ];
        CacheManager::set($cache_key, $cache_data, 3600);
        $this->setPaginationVars($query, $cache_data);

        return $paginated_posts;
    }
    
    /**
     * Load all posts from files
     * 
     * @return array Array of WP_Post objects
     */
    private function loadAllPosts() {
        if (!file_exists($this->postsDir)) {
            return [];
        }
        
        // Check for index file (fast path for large directories)
        $indexFile = $this->postsDir . '/_index.json';
        if (file_exists($indexFile)) {
            return $this->loadFromIndex($indexFile);
        }
        
        // Fallback: Scan directory recursively (supports subdirectories)
        $files = glob($this->postsDir . '/*.md');
        
        // Also scan subdirectories (for hierarchical organization)
        $subdirFiles = glob($this->postsDir . '/*/*.md');
        if (!empty($subdirFiles)) {
            $files = array_merge($files, $subdirFiles);
        }
        
        if (empty($files)) {
            return [];
        }
        
        $posts = [];
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $parsed = $this->frontMatterParser->parse($content);
            
            // Create virtual WP_Post object
            $post = $this->createPostObject($parsed, $file);
            
            if ($post) {
                $posts[] = $post;
            }
        }
        
        return $posts;
    }
    
    /**
     * Load posts from pre-built index file (fast for large directories)
     * 
     * @param string $indexFile Path to index file
     * @return array Array of WP_Post objects
     */
    private function loadFromIndex($indexFile) {
        $indexData = json_decode(file_get_contents($indexFile), true);
        
        if (!is_array($indexData)) {
            return [];
        }
        
        $posts = [];
        
        foreach ($indexData as $entry) {
            // Build full file path
            $file = $this->postsDir . '/' . $entry['file'];
            
            if (!file_exists($file)) {
                continue;
            }
            
            // Read only the file content (front matter already parsed in index)
            $content = file_get_contents($file);
            
            // Extract content after front matter
            if (preg_match('/^---\s*\n.*?\n---\s*\n(.*)$/s', $content, $matches)) {
                $markdownContent = $matches[1];
            } else {
                $markdownContent = $content;
            }
            
            // Parse markdown to HTML
            $htmlContent = $this->parser->parse($markdownContent);
            
            // Get author ID
            $author_id = $this->getUserIdByLogin($entry['author'] ?? 'admin');
            
            // Create post data from index
            $post_data = [
                'ID' => abs(crc32($entry['slug'])),
                'post_author' => $author_id,
                'post_date' => $entry['date'],
                'post_date_gmt' => $entry['date'],
                'post_content' => $htmlContent,
                'post_title' => $entry['title'],
                'post_excerpt' => $entry['excerpt'] ?? '',
                'post_status' => $entry['status'] ?? 'publish',
                'comment_status' => 'open',
                'ping_status' => 'open',
                'post_password' => '',
                'post_name' => $entry['slug'],
                'to_ping' => '',
                'pinged' => '',
                'post_modified' => $entry['modified'] ?? current_time('mysql'),
                'post_modified_gmt' => $entry['modified'] ?? current_time('mysql', 1),
                'post_content_filtered' => '',
                'post_parent' => 0,
                'guid' => home_url($this->postsDir . '/' . $entry['slug'] . '/'),
                'menu_order' => 0,
                'post_type' => $this->postType === 'posts' ? 'praison_post' : $this->postType,
                'post_mime_type' => '',
                'comment_count' => 0,
                'filter' => 'raw',
            ];
            
            // Create WP_Post object
            $post = new \WP_Post((object) $post_data);
            
            // Store additional metadata
            $post->_praison_file = $file;
            $post->_praison_categories = $entry['categories'] ?? [];
            $post->_praison_tags = $entry['tags'] ?? [];
            $post->_praison_featured_image = $entry['featured_image'] ?? '';
            $post->_praison_custom_fields = $entry['custom_fields'] ?? $entry['custom'] ?? [];

            // Store custom fields as post properties for ACF compatibility
            $custom = $entry['custom_fields'] ?? $entry['custom'] ?? [];
            foreach ($custom as $key => $value) {
                $post->{$key} = $value;
            }
            
            $posts[] = $post;
        }
        
        return $posts;
    }
    
    /**
     * Create a WP_Post object from parsed data
     * 
     * @param array $parsed Parsed content with metadata
     * @param string $file File path
     * @return \WP_Post|null Post object or null on error
     */
    private function createPostObject($parsed, $file) {
        $metadata = $parsed['metadata'];
        
        // Required fields
        if (empty($metadata['title']) || empty($metadata['slug'])) {
            return null;
        }
        
        // Parse markdown content to HTML
        $content = $this->parser->parse($parsed['content']);
        
        // Get author ID
        $author_id = $this->getUserIdByLogin($metadata['author'] ?? 'admin');
        
        // Create post data
        $post_data = [
            'ID' => abs(crc32($metadata['slug'])), // Generate numeric ID from slug
            'post_author' => $author_id,
            'post_date' => $metadata['date'] ?? current_time('mysql'),
            'post_date_gmt' => $metadata['date'] ?? current_time('mysql', 1),
            'post_content' => $content,
            'post_title' => $metadata['title'],
            'post_excerpt' => $metadata['excerpt'] ?? '',
            'post_status' => $metadata['status'] ?? 'publish',
            'comment_status' => 'open',
            'ping_status' => 'open',
            'post_password' => '',
            'post_name' => $metadata['slug'],
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => $metadata['modified'] ?? current_time('mysql'),
            'post_modified_gmt' => $metadata['modified'] ?? current_time('mysql', 1),
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => home_url($this->postType . '/' . $metadata['slug'] . '/'),
            'menu_order' => 0,
            'post_type' => $this->postType === 'posts' ? 'praison_post' : $this->postType,
            'post_mime_type' => '',
            'comment_count' => 0,
            'filter' => 'raw',
        ];
        
        // Create WP_Post object
        $post = new \WP_Post((object) $post_data);
        
        // Store additional metadata
        $post->_praison_file = $file;
        $post->_praison_categories = $metadata['categories'] ?? [];
        $post->_praison_tags = $metadata['tags'] ?? [];
        $post->_praison_featured_image = $metadata['featured_image'] ?? '';
        $post->_praison_custom_fields = $metadata['custom_fields'] ?? [];
        
        // Store custom fields as post meta for ACF compatibility
        // This allows get_field() and other ACF functions to work
        if (!empty($metadata['custom_fields'])) {
            foreach ($metadata['custom_fields'] as $key => $value) {
                // Store in the post object so ACF can access it
                $post->{$key} = $value;
            }
        }
        
        return $post;
    }
    
    /**
     * Filter posts based on query parameters
     * 
     * @param array $posts All posts
     * @param \WP_Query $query Query object
     * @return array Filtered posts
     */
    private function filterPosts($posts, $query) {
        $filtered = [];

        foreach ($posts as $post) {
            // Match by slug (for single post queries)
            $slug = $query->get('name');
            if ($slug && $post->post_name !== $slug) {
                continue;
            }

            // Match by post ID
            $post_id = $query->get('p');
            if ($post_id && $post->ID != $post_id) {
                continue;
            }

            // Match post status.
            // Default to 'publish' when no status is requested (prevents drafts leaking into
            // feeds, archives, and taxonomy pages which do not set an explicit post_status).
            $status = $query->get('post_status');
            if (empty($status) || $status === 'publish') {
                if ($post->post_status !== 'publish') {
                    continue;
                }
            } elseif ($status !== 'any' && $post->post_status !== $status) {
                continue;
            }

            // Match search query
            $search = $query->get('s');
            if ($search) {
                $haystack = strtolower($post->post_title . ' ' . $post->post_content);
                if (strpos($haystack, strtolower($search)) === false) {
                    continue;
                }
            }

            // Taxonomy filtering — category and tag archives.
            // File-based posts store their category/tag slugs in _praison_categories/_praison_tags.
            // Without this filter every file post appears on every taxonomy archive page.
            $cat_name = $query->get('category_name');
            $tag      = $query->get('tag');

            if ($cat_name) {
                $post_cats = array_map('sanitize_title', (array) ($post->_praison_categories ?? []));
                if (!in_array(sanitize_title($cat_name), $post_cats, true)) {
                    continue;
                }
            }

            if ($tag) {
                $post_tags = array_map('sanitize_title', (array) ($post->_praison_tags ?? []));
                if (!in_array(sanitize_title($tag), $post_tags, true)) {
                    continue;
                }
            }

            $filtered[] = $post;
        }

        return $filtered;
    }

    
    /**
     * Apply pagination to posts
     * 
     * @param array $posts All posts
     * @param \WP_Query $query Query object
     * @return array Paginated posts
     */
    private function applyPagination($posts, $query) {
        $paged = max(1, $query->get('paged'));
        $posts_per_page = $query->get('posts_per_page') ?: get_option('posts_per_page', 10);
        
        if ($posts_per_page == -1) {
            return $posts;
        }
        
        $offset = ($paged - 1) * $posts_per_page;
        return array_slice($posts, $offset, $posts_per_page);
    }
    
    /**
     * Set pagination variables on query object
     * 
     * @param \WP_Query $query Query object
     * @param array $data Cache data with counts
     */
    private function setPaginationVars($query, $data) {
        $query->found_posts = $data['found_posts'];
        $query->max_num_pages = $data['max_num_pages'];
    }
    
    /**
     * Get user ID by login name
     * 
     * @param string $login User login
     * @return int User ID (defaults to 1 if not found)
     */
    private function getUserIdByLogin($login) {
        $user = get_user_by('login', $login);
        return $user ? $user->ID : 1;
    }
    
    /**
     * Get posts directly (for helper functions)
     * 
     * @param array $args Query arguments
     * @return array Array of WP_Post objects
     */
    /**
     * Load a single post by slug.
     * Checks _index.json first for an O(1) file lookup, then falls back to full scan.
     *
     * @param string $slug Post slug
     * @return array Array with 0 or 1 WP_Post objects
     */
    private function loadSinglePost(string $slug): array {
        $indexFile = $this->postsDir . '/_index.json';

        if (file_exists($indexFile)) {
            $index = json_decode(file_get_contents($indexFile), true);
            if (is_array($index)) {
                foreach ($index as $entry) {
                    if (isset($entry['slug']) && $entry['slug'] === $slug) {
                        $post = $this->loadFileFromIndexEntry($entry);
                        return $post ? [$post] : [];
                    }
                }
                return []; // slug not in index → post doesn't exist
            }
        }

        // Fallback: full scan (no _index.json present)
        $all = $this->loadAllPosts();
        return array_values(array_filter($all, function($p) use ($slug) {
            return $p->post_name === $slug;
        }));
    }

    /**
     * Load a single post from its index entry.
     * Reads only the one .md file referenced by the entry.
     *
     * @param array $entry Row from _index.json
     * @return \WP_Post|null
     */
    private function loadFileFromIndexEntry(array $entry): ?\WP_Post {
        $file = $this->postsDir . '/' . ($entry['file'] ?? '');
        if (!file_exists($file)) {
            return null;
        }

        $content = file_get_contents($file);
        $parsed  = $this->frontMatterParser->parse($content);
        return $this->createPostObject($parsed, $file);
    }

    public function getPosts($args = []) {
        $query = new \WP_Query($args);
        return $this->loadAllPosts();
    }
    
    /**
     * Get statistics about file-based posts
     * 
     * @return array Stats array
     */
    public function getStats() {
        $base_dir = PRAISON_CONTENT_DIR;
        $stats = [
            'cache_active' => CacheManager::isActive(),
            'last_modified' => current_time('mysql'),
        ];
        
        // Dynamically scan all directories for markdown files
        if (file_exists($base_dir) && is_dir($base_dir)) {
            $items = scandir($base_dir);
            foreach ($items as $item) {
                // Skip hidden files and config directory
                if ($item[0] === '.' || $item === 'config') {
                    continue;
                }
                
                $full_path = $base_dir . '/' . $item;
                if (is_dir($full_path)) {
                    $count = count(glob($full_path . '/*.md'));
                    if ($count > 0) {
                        $stats['total_' . $item] = $count;
                    }
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * Get last modification time of posts directory
     * 
     * @return string Formatted date or 'Never'
     */
    private function getLastModified() {
        if (!file_exists($this->postsDir)) {
            return 'Never';
        }
        
        $files = glob($this->postsDir . '/*.md');
        if (empty($files)) {
            return 'Never';
        }
        
        $mtimes = array_map('filemtime', $files);
        $latest = max($mtimes);
        
        return gmdate('Y-m-d H:i:s', $latest);
    }
}
