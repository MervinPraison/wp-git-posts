<?php
namespace PraisonPress\Parsers;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Parse YAML front matter from Markdown files
 */
class FrontMatterParser {
    
    /**
     * Parse content with YAML front matter
     * 
     * @param string $content File content with front matter
     * @return array ['metadata' => array, 'content' => string]
     */
    public function parse($content) {
        $metadata = [];
        $body = $content;
        
        // Check for front matter (--- at start)
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)/s', $content, $matches)) {
            $yaml = $matches[1];
            $body = $matches[2];
            
            // Parse YAML (simple parser for basic structures)
            $metadata = $this->parseYaml($yaml);
        }
        
        return [
            'metadata' => $metadata,
            'content' => trim($body)
        ];
    }
    
    /**
     * Simple YAML parser for basic key-value pairs and lists
     * 
     * @param string $yaml YAML content
     * @return array Parsed data
     */
    private function parseYaml($yaml) {
        $result = [];
        $lines = explode("\n", $yaml);
        $currentKey = null;
        $inList = false;

        foreach ($lines as $line) {
            $line = rtrim($line);

            if (empty($line)) {
                continue;
            }

            // List item (starts with - )
            if (preg_match('/^\s*-\s+(.+)$/', $line, $matches)) {
                if ($currentKey && $inList) {
                    $result[$currentKey][] = $this->castValue(trim($matches[1], '"\''));
                }
                continue;
            }

            // Key-value pair
            if (preg_match('/^([^:]+):\s*(.*)$/', $line, $matches)) {
                $key   = trim($matches[1]);
                $raw   = trim($matches[2]);

                if (empty($raw)) {
                    // Empty value: next indented lines are list items
                    $currentKey = $key;
                    $inList     = true;
                    $result[$key] = [];
                } elseif ($raw[0] === '[') {
                    // Inline YAML array: [item1, item2, ...]
                    $inList     = false;
                    $currentKey = $key;
                    $inner      = trim($raw, '[]');
                    $items      = array_map(function($v) {
                        return $this->castValue(trim($v, '"\''));
                    }, array_filter(array_map('trim', explode(',', $inner))));
                    $result[$key] = array_values($items);
                } else {
                    $inList     = false;
                    $currentKey = $key;
                    $result[$key] = $this->castValue(trim($raw, '"\''));
                }
            }
        }

        return $result;
    }

    /**
     * Cast a scalar YAML value to the appropriate PHP type.
     * Converts 'true'/'false'/'yes'/'no' to bool, numeric strings to int/float.
     */
    private function castValue($value) {
        $lower = strtolower($value);
        if (in_array($lower, ['true', 'yes'], true))  return true;
        if (in_array($lower, ['false', 'no'], true))  return false;
        if (in_array($lower, ['null', '~'], true))    return null;
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float) $value : (int) $value;
        }
        return $value;
    }
}
