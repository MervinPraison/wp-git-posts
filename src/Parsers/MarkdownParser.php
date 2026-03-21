<?php
namespace PraisonPress\Parsers;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Simple Markdown to HTML parser
 * Supports basic Markdown syntax
 */
class MarkdownParser {
    
    /**
     * Parse Markdown to HTML
     * 
     * @param string $markdown Markdown content
     * @return string HTML content
     */
    public function parse($markdown) {
        // Use Parsedown if available, otherwise basic conversion
        if (class_exists('Parsedown')) {
            $parsedown = new \Parsedown();
            return $parsedown->text($markdown);
        }
        
        // Basic Markdown conversion
        return $this->basicParse($markdown);
    }
    
    /**
     * Basic Markdown parser (fallback)
     * 
     * @param string $markdown Markdown content
     * @return string HTML content
     */
    private function basicParse($markdown) {
        $html = $markdown;

        // Code blocks (must be first to prevent inner content being re-processed)
        $html = preg_replace('/```([a-z]*)\n(.*?)\n```/s', '<pre><code class="language-$1">$2</code></pre>', $html);

        // Headers
        $html = preg_replace('/^######\s+(.+)$/m', '<h6>$1</h6>', $html);
        $html = preg_replace('/^#####\s+(.+)$/m',  '<h5>$1</h5>', $html);
        $html = preg_replace('/^####\s+(.+)$/m',   '<h4>$1</h4>', $html);
        $html = preg_replace('/^###\s+(.+)$/m',    '<h3>$1</h3>', $html);
        $html = preg_replace('/^##\s+(.+)$/m',     '<h2>$1</h2>', $html);
        $html = preg_replace('/^#\s+(.+)$/m',      '<h1>$1</h1>', $html);

        // Bold and italic
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/__(.+?)__/',     '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/',     '<em>$1</em>', $html);
        $html = preg_replace('/_(.+?)_/',       '<em>$1</em>', $html);

        // Links and images
        $html = preg_replace('/!\[([^\]]*)\]\(([^\)]+)\)/', '<img src="$2" alt="$1" />', $html);
        $html = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)/',  '<a href="$2">$1</a>', $html);

        // Inline code (after block code so backticks inside fences are safe)
        $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);

        // Unordered lists: collect consecutive <li> lines and wrap them in <ul>
        $html = preg_replace_callback('/^(\s*)[-*]\s+(.+)$/m', function($m) {
            return '<li>' . $m[2] . '</li>';
        }, $html);
        $html = preg_replace('/(<li>(?:.|\n)*?<\/li>(?:\n|$))+/', "<ul>\n$0</ul>\n", $html);

        // Paragraphs: split on blank lines, wrap plain-text blocks in <p>
        $blocks = preg_split('/\n{2,}/', $html);
        foreach ($blocks as &$block) {
            $block = trim($block);
            // Skip empty blocks and blocks that are already block-level HTML
            if ($block === '' || preg_match('/^<(?:h[1-6]|ul|ol|li|p|pre|blockquote|div|table)/i', $block)) {
                continue;
            }
            $block = '<p>' . $block . '</p>';
        }
        unset($block);

        return implode("\n\n", array_filter($blocks));
    }

}
