<?php
namespace PraisonPress\Export;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Export Configuration Manager
 * 
 * Reads and parses export-config.ini to determine how different post types
 * should be exported to markdown files with custom directory structures.
 */
class ExportConfig {
    
    private $config = [];
    private $configFile;
    
    /**
     * Constructor
     * 
     * @param string $configFile Path to export-config.ini
     */
    public function __construct($configFile = null) {
        if ($configFile === null) {
            $configFile = PRAISON_PLUGIN_DIR . '/export-config.ini';
        }
        
        $this->configFile = $configFile;
        $this->loadConfig();
    }
    
    /**
     * Load configuration from INI file
     */
    private function loadConfig() {
        // Try main config file first
        if (!file_exists($this->configFile)) {
            // Try .example file
            $exampleFile = $this->configFile . '.example';
            if (file_exists($exampleFile)) {
                $this->configFile = $exampleFile;
            } else {
                // Use default configuration
                $this->config = $this->getDefaultConfig();
                return;
            }
        }
        
        $parsed = parse_ini_file($this->configFile, true);
        
        if ($parsed === false) {
            // error_log('PraisonPress: Failed to parse export config file');
            $this->config = $this->getDefaultConfig();
            return;
        }
        
        $this->config = $parsed;
    }
    
    /**
     * Get default configuration
     * 
     * @return array
     */
    private function getDefaultConfig() {
        return [
            'general' => [
                'export_base_dir' => 'content',
                'date_format' => 'Y-m-d',
                'default_date_prefix' => false,
            ],
        ];
    }
    
    /**
     * Get configuration for a specific post type
     * 
     * @param string $postType Post type name
     * @return array Configuration array
     */
    public function getPostTypeConfig($postType) {
        if (isset($this->config[$postType])) {
            return $this->config[$postType];
        }
        
        // Return default configuration
        // Default behavior: flat structure with date prefix
        return [
            'directory' => $postType,
            'structure' => 'flat',
            'filename_pattern' => '{date}-{slug}.md',
            'date_prefix' => true,  // Include date by default
            'custom_fields' => [],
        ];
    }
    
    /**
     * Get export directory for a post type
     * 
     * @param string $postType Post type name
     * @return string Directory path
     */
    public function getExportDirectory($postType) {
        $config = $this->getPostTypeConfig($postType);
        $baseDir = $this->config['general']['export_base_dir'] ?? 'content';
        $postTypeDir = $config['directory'] ?? $postType;
        
        return $baseDir . '/' . $postTypeDir;
    }
    
    /**
     * Get directory structure type for a post type
     * 
     * @param string $postType Post type name
     * @return string Structure type (flat, alphabetical, hierarchical, etc.)
     */
    public function getStructureType($postType) {
        $config = $this->getPostTypeConfig($postType);
        return $config['structure'] ?? 'flat';
    }
    
    /**
     * Get hierarchy levels for hierarchical structure
     * 
     * @param string $postType Post type name
     * @return array Array of custom field names
     */
    public function getHierarchyLevels($postType) {
        $config = $this->getPostTypeConfig($postType);
        return $config['hierarchy_levels'] ?? [];
    }
    
    /**
     * Get alphabetical field for alphabetical structure
     * 
     * @param string $postType Post type name
     * @return string Field name to use for alphabetical sorting
     */
    public function getAlphabeticalField($postType) {
        $config = $this->getPostTypeConfig($postType);
        return $config['alphabetical_field'] ?? 'title';
    }
    
    /**
     * Get filename pattern for a post type
     * 
     * @param string $postType Post type name
     * @return string Filename pattern
     */
    public function getFilenamePattern($postType) {
        $config = $this->getPostTypeConfig($postType);
        return $config['filename_pattern'] ?? '{slug}.md';
    }
    
    /**
     * Check if date prefix should be used
     * 
     * @param string $postType Post type name
     * @return bool
     */
    public function useDatePrefix($postType) {
        $config = $this->getPostTypeConfig($postType);
        return (bool) ($config['date_prefix'] ?? false);
    }
    
    /**
     * Get custom fields to include in export
     * 
     * @param string $postType Post type name
     * @return array Array of custom field names
     */
    public function getCustomFields($postType) {
        $config = $this->getPostTypeConfig($postType);
        return $config['custom_fields'] ?? [];
    }
    
    /**
     * Get meta fields to exclude from export.
     * Merges global [exclude_meta] keys with per-post-type exclude_meta.
     * 
     * @param string $postType Post type name
     * @return array Array of meta field names to exclude
     */
    public function getExcludeMeta($postType) {
        // Global excludes from [exclude_meta] section
        $globalExcludes = [];
        if (isset($this->config['exclude_meta'])) {
            $globalExcludes = array_keys(array_filter($this->config['exclude_meta']));
        }
        
        // Per-post-type excludes
        $config = $this->getPostTypeConfig($postType);
        $typeExcludes = [];
        if (isset($config['exclude_meta'])) {
            if (is_string($config['exclude_meta'])) {
                $typeExcludes = array_map('trim', explode(',', $config['exclude_meta']));
            } elseif (is_array($config['exclude_meta'])) {
                $typeExcludes = $config['exclude_meta'];
            }
        }
        
        return array_unique(array_merge($globalExcludes, $typeExcludes));
    }
    
    /**
     * Generate file path for a post based on configuration
     * 
     * @param string $postType Post type name
     * @param array $postData Post data including custom fields
     * @return string Relative file path
     */
    public function generateFilePath($postType, $postData) {
        $baseDir = $this->getExportDirectory($postType);
        $structure = $this->getStructureType($postType);
        $subdirs = [];
        
        switch ($structure) {
            case 'hierarchical':
                $subdirs = $this->generateHierarchicalPath($postType, $postData);
                break;
                
            case 'alphabetical':
                $subdirs = $this->generateAlphabeticalPath($postType, $postData);
                break;
                
            case 'category':
                $subdirs = $this->generateCategoryPath($postType, $postData);
                break;
                
            case 'date':
                $subdirs = $this->generateDatePath($postType, $postData);
                break;
                
            case 'flat':
            default:
                $subdirs = [];
                break;
        }
        
        $filename = $this->generateFilename($postType, $postData);
        
        $path = $baseDir;
        foreach ($subdirs as $dir) {
            $path .= '/' . $this->sanitizeDirectoryName($dir);
        }
        $path .= '/' . $filename;
        
        return $path;
    }
    
    /**
     * Generate hierarchical path based on custom fields
     * 
     * @param string $postType Post type name
     * @param array $postData Post data
     * @return array Array of directory names
     */
    private function generateHierarchicalPath($postType, $postData) {
        $levels = $this->getHierarchyLevels($postType);
        $path = [];
        
        foreach ($levels as $i => $fieldName) {
            // Get custom field value
            $value = $postData['custom_fields'][$fieldName] ?? '';
            
            if (empty($value)) {
                continue;
            }
            
            // For the last level, it becomes the filename, not a directory
            if ($i === count($levels) - 1) {
                break;
            }
            
            $path[] = $value;
        }
        
        return $path;
    }
    
    /**
     * Generate alphabetical path
     * 
     * @param string $postType Post type name
     * @param array $postData Post data
     * @return array Array of directory names
     */
    private function generateAlphabeticalPath($postType, $postData) {
        $field = $this->getAlphabeticalField($postType);
        $value = $postData[$field] ?? $postData['title'] ?? '';
        
        if (empty($value)) {
            return ['other'];
        }
        
        // Get first letter
        $firstLetter = strtolower(mb_substr($value, 0, 1));
        
        // Check if it's a letter
        if (!preg_match('/[a-z]/', $firstLetter)) {
            return ['other'];
        }
        
        return [$firstLetter];
    }
    
    /**
     * Generate category path
     * 
     * @param string $postType Post type name
     * @param array $postData Post data
     * @return array Array of directory names
     */
    private function generateCategoryPath($postType, $postData) {
        $categories = $postData['categories'] ?? [];
        
        if (empty($categories)) {
            return ['uncategorized'];
        }
        
        // Use first category
        $category = is_array($categories) ? $categories[0] : $categories;
        return [sanitize_title($category)];
    }
    
    /**
     * Generate date-based path
     * 
     * @param string $postType Post type name
     * @param array $postData Post data
     * @return array Array of directory names
     */
    private function generateDatePath($postType, $postData) {
        $config = $this->getPostTypeConfig($postType);
        $depth = $config['date_depth'] ?? 2;
        
        $date = $postData['date'] ?? current_time('mysql');
        $timestamp = strtotime($date);
        
        $path = [];
        
        if ($depth >= 1) {
            $path[] = gmdate('Y', $timestamp);  // Year
        }
        
        if ($depth >= 2) {
            $path[] = gmdate('m', $timestamp);  // Month
        }
        
        if ($depth >= 3) {
            $path[] = gmdate('d', $timestamp);  // Day
        }
        
        return $path;
    }
    
    /**
     * Generate filename for a post
     * 
     * @param string $postType Post type name
     * @param array $postData Post data
     * @return string Filename
     */
    private function generateFilename($postType, $postData) {
        $pattern = $this->getFilenamePattern($postType);
        $structure = $this->getStructureType($postType);
        
        // For hierarchical structure, use the last hierarchy level as filename
        if ($structure === 'hierarchical') {
            $levels = $this->getHierarchyLevels($postType);
            if (!empty($levels)) {
                $lastLevel = end($levels);
                $value = $postData['custom_fields'][$lastLevel] ?? $postData['slug'];
                return $this->sanitizeFilename($value) . '.md';
            }
        }
        
        // Replace variables in pattern
        $filename = $pattern;
        
        // {slug}
        $filename = str_replace('{slug}', $postData['slug'] ?? 'untitled', $filename);
        
        // {title}
        $title = sanitize_title($postData['title'] ?? 'untitled');
        $filename = str_replace('{title}', $title, $filename);
        
        // {date}
        $date = $postData['date'] ?? current_time('mysql');
        $dateFormatted = gmdate($this->config['general']['date_format'] ?? 'Y-m-d', strtotime($date));
        $filename = str_replace('{date}', $dateFormatted, $filename);
        
        // {id}
        $filename = str_replace('{id}', $postData['ID'] ?? 0, $filename);
        
        // Ensure .md extension
        if (!str_ends_with($filename, '.md')) {
            $filename .= '.md';
        }
        
        return $this->sanitizeFilename($filename);
    }
    
    /**
     * Sanitize directory name
     * 
     * @param string $name Directory name
     * @return string Sanitized name
     */
    private function sanitizeDirectoryName($name) {
        // Convert to lowercase
        $name = strtolower($name);
        
        // Replace spaces and special characters with hyphens
        $name = preg_replace('/[^a-z0-9-_]/', '-', $name);
        
        // Remove multiple consecutive hyphens
        $name = preg_replace('/-+/', '-', $name);
        
        // Trim hyphens from ends
        $name = trim($name, '-');
        
        return $name;
    }
    
    /**
     * Sanitize filename
     * 
     * @param string $filename Filename
     * @return string Sanitized filename
     */
    private function sanitizeFilename($filename) {
        // Remove .md extension temporarily
        $hasExtension = str_ends_with($filename, '.md');
        if ($hasExtension) {
            $filename = substr($filename, 0, -3);
        }
        
        // Sanitize
        $filename = sanitize_title($filename);
        
        // Add extension back
        if ($hasExtension) {
            $filename .= '.md';
        }
        
        return $filename;
    }
}
