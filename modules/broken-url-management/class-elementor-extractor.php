<?php
/**
 * Elementor Link Extractor
 * 
 * Extracts links from Elementor page builder data
 * 
 * @package SEO_AutoFix_Pro
 * @subpackage Broken_URL_Management
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SEO_AutoFix_Elementor_Extractor
{
    /**
     * Extract all links from Elementor data for a given post
     * 
     * @param int $post_id Post ID
     * @return array Array of link data
     */
    public function extract_links($post_id)
    {
        // Check if Elementor data exists
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        
        if (empty($elementor_data)) {
            return [];
        }

        // Parse JSON
        $elements = json_decode($elementor_data, true);
        
        if (!is_array($elements)) {
            error_log("[ELEMENTOR-EXTRACTOR] Invalid JSON for post {$post_id}");
            return [];
        }

        // Extract links recursively
        $links = [];
        $this->find_links_recursive($elements, '', $links);

        error_log("[ELEMENTOR-EXTRACTOR] Found " . count($links) . " links in post {$post_id}");

        return $links;
    }

    /**
     * Recursively search for links in Elementor data structure
     * 
     * @param array $data Current level of data
     * @param string $path JSON path to current location
     * @param array &$links Reference to links array
     * @param int $depth Current recursion depth
     */
    private function find_links_recursive($data, $path, &$links, $depth = 0)
    {
        // Prevent infinite recursion
        if ($depth > 20) {
            return;
        }

        if (!is_array($data)) {
            return;
        }

        foreach ($data as $key => $value) {
            $current_path = empty($path) ? "[{$key}]" : "{$path}[{$key}]";

            // Check if this is a link object
            if ($key === 'link' && is_array($value) && isset($value['url'])) {
                $url = $value['url'];
                
                // Only process external URLs
                if ($this->is_valid_external_url($url)) {
                    $links[] = [
                        'url' => $url,
                        'json_path' => $current_path . '.url',
                        'source_type' => 'elementor',
                        'source_meta_key' => '_elementor_data',
                        'context' => $this->get_widget_context($data),
                        'anchor_text' => $this->get_anchor_text($data)
                    ];
                }
            }

            // Check for direct URL fields (images, backgrounds, etc.)
            if ($key === 'url' && is_string($value) && $this->is_valid_external_url($value)) {
                // Avoid duplicates from link objects (already handled above)
                $parent_key = $this->get_parent_key($path);
                if ($parent_key !== 'link') {
                    $links[] = [
                        'url' => $value,
                        'json_path' => $current_path,
                        'source_type' => 'elementor',
                        'source_meta_key' => '_elementor_data',
                        'context' => $this->get_widget_context($data),
                        'anchor_text' => ''
                    ];
                }
            }

            // Recursively process arrays and objects
            if (is_array($value)) {
                $this->find_links_recursive($value, $current_path, $links, $depth + 1);
            }
        }
    }

    /**
     * Check if URL is a valid external URL
     * 
     * @param string $url URL to check
     * @return bool True if valid external URL
     */
    private function is_valid_external_url($url)
    {
        if (empty($url) || !is_string($url)) {
            return false;
        }

        // Skip internal anchors
        if (strpos($url, '#') === 0) {
            return false;
        }

        // Skip mailto and tel links
        if (preg_match('/^(mailto|tel|javascript):/i', $url)) {
            return false;
        }

        // Skip dynamic tags and shortcodes
        if (preg_match('/\{|\[/', $url)) {
            return false;
        }

        // Must be http/https
        if (!preg_match('/^https?:\/\//i', $url)) {
            return false;
        }

        // Get site URL
        $site_url = get_site_url();
        $site_domain = parse_url($site_url, PHP_URL_HOST);

        // Parse URL
        $parsed = parse_url($url);
        if (!isset($parsed['host'])) {
            return false;
        }

        // Check if external (different domain)
        $url_domain = $parsed['host'];
        
        // For now, we'll scan ALL links (both internal and external)
        // User can filter later if needed
        return true;
    }

    /**
     * Get parent key from JSON path
     * 
     * @param string $path JSON path
     * @return string Parent key or empty string
     */
    private function get_parent_key($path)
    {
        if (empty($path)) {
            return '';
        }

        // Extract last key from path like "[0][elements][2][settings][link]"
        if (preg_match('/\[([^\]]+)\]$/', $path, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Get widget context information
     * 
     * @param array $data Element data
     * @return string Widget context
     */
    private function get_widget_context($data)
    {
        if (isset($data['widgetType'])) {
            return 'Elementor ' . ucfirst(str_replace('-', ' ', $data['widgetType'])) . ' Widget';
        }

        if (isset($data['elType'])) {
            return 'Elementor ' . ucfirst($data['elType']);
        }

        return 'Elementor Element';
    }

    /**
     * Get anchor text from widget settings
     * 
     * @param array $data Element data
     * @return string Anchor text
     */
    private function get_anchor_text($data)
    {
        // Try to get button text
        if (isset($data['settings']['text'])) {
            return strip_tags($data['settings']['text']);
        }

        // Try to get heading text
        if (isset($data['settings']['title'])) {
            return strip_tags($data['settings']['title']);
        }

        // Try to get icon box heading
        if (isset($data['settings']['title_text'])) {
            return strip_tags($data['settings']['title_text']);
        }

        return '';
    }

    /**
     * Check if Elementor is active for a post
     * 
     * @param int $post_id Post ID
     * @return bool True if Elementor is being used
     */
    public static function is_elementor_page($post_id)
    {
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        return !empty($elementor_data);
    }

    /**
     * Check if Elementor plugin is installed and active
     * 
     * @return bool True if Elementor is active
     */
    public static function is_elementor_active()
    {
        return defined('ELEMENTOR_VERSION') || class_exists('\Elementor\Plugin');
    }
}
