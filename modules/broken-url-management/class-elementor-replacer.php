<?php
/**
 * Elementor Link Replacer
 * 
 * Replaces/fixes links in Elementor page builder data
 * 
 * @package SEO_AutoFix_Pro
 * @subpackage Broken_URL_Management
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SEO_AutoFix_Elementor_Replacer
{
    /**
     * Replace a link in Elementor data
     * 
     * @param int $post_id Post ID
     * @param string $old_url Old URL to replace
     * @param string $new_url New URL
     * @param string $json_path JSON path to the link
     * @return bool True on success, false on failure
     */
    public function replace_link($post_id, $old_url, $new_url, $json_path)
    {
        try {
            // Get Elementor data
            $elementor_data = get_post_meta($post_id, '_elementor_data', true);
            
            if (empty($elementor_data)) {
                error_log("[ELEMENTOR-REPLACER] No Elementor data found for post {$post_id}");
                return false;
            }

            // Parse JSON
            $elements = json_decode($elementor_data, true);
            
            if (!is_array($elements)) {
                error_log("[ELEMENTOR-REPLACER] Invalid JSON for post {$post_id}");
                return false;
            }

            // Set new value at the specified path
            $success = $this->set_value_at_path($elements, $json_path, $new_url);

            if (!$success) {
                error_log("[ELEMENTOR-REPLACER] Failed to set value at path: {$json_path}");
                return false;
            }

            // Save updated data
            $updated_json = wp_json_encode($elements);
            update_post_meta($post_id, '_elementor_data', wp_slash($updated_json));

            // Clear Elementor cache
            $this->clear_elementor_cache($post_id);

            error_log("[ELEMENTOR-REPLACER] Successfully replaced link in post {$post_id}");
            error_log("[ELEMENTOR-REPLACER] Path: {$json_path}");
            error_log("[ELEMENTOR-REPLACER] Old: {$old_url}");
            error_log("[ELEMENTOR-REPLACER] New: {$new_url}");

            return true;

        } catch (Exception $e) {
            error_log("[ELEMENTOR-REPLACER] Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete/remove a link from Elementor data
     * 
     * @param int $post_id Post ID
     * @param string $json_path JSON path to the link
     * @return bool True on success, false on failure
     */
    public function delete_link($post_id, $json_path)
    {
        // For Elementor, set the URL to empty string
        // We don't want to delete the entire widget
        return $this->replace_link($post_id, '', '', $json_path);
    }

    /**
     * Set value at a specific JSON path
     * 
     * @param array &$data Reference to data array
     * @param string $path JSON path (e.g., "[0][settings][link].url")
     * @param mixed $value Value to set
     * @return bool True on success, false on failure
     */
    private function set_value_at_path(&$data, $path, $value)
    {
        // Parse the path
        // Example: "[0][elements][2][settings][link].url"
        // Split into: [0], [elements], [2], [settings], [link], url
        
        // Remove the final ".url" or similar property accessor
        $parts = explode('.', $path);
        $property = array_pop($parts); // Get 'url'
        $array_path = $parts[0]; // Get "[0][elements][2][settings][link]"

        // Extract array keys from path like "[0][elements][2]"
        preg_match_all('/\[([^\]]+)\]/', $array_path, $matches);
        
        if (empty($matches[1])) {
            return false;
        }

        $keys = $matches[1];

        // Navigate to the target location
        $current = &$data;
        
        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                error_log("[ELEMENTOR-REPLACER] Key not found: {$key} in path: {$path}");
                return false;
            }
            $current = &$current[$key];
        }

        // Set the value
        if (!isset($current[$property])) {
            error_log("[ELEMENTOR-REPLACER] Property not found: {$property} in path: {$path}");
            return false;
        }

        $current[$property] = $value;
        return true;
    }

    /**
     * Clear Elementor cache for a post
     * 
     * @param int $post_id Post ID
     */
    private function clear_elementor_cache($post_id)
    {
        // Check if Elementor is active
        if (!class_exists('\Elementor\Plugin')) {
            return;
        }

        try {
            // Clear post CSS cache
            $css_file = new \Elementor\Core\Files\CSS\Post($post_id);
            $css_file->delete();

            // Clear global cache
            \Elementor\Plugin::$instance->files_manager->clear_cache();

            error_log("[ELEMENTOR-REPLACER] Cleared Elementor cache for post {$post_id}");

        } catch (Exception $e) {
            error_log("[ELEMENTOR-REPLACER] Error clearing cache: " . $e->getMessage());
        }
    }

    /**
     * Get a value at a specific JSON path (for verification)
     * 
     * @param array $data Data array
     * @param string $path JSON path
     * @return mixed Value at path or null
     */
    public function get_value_at_path($data, $path)
    {
        // Parse the path
        $parts = explode('.', $path);
        $property = array_pop($parts);
        $array_path = $parts[0];

        // Extract array keys
        preg_match_all('/\[([^\]]+)\]/', $array_path, $matches);
        
        if (empty($matches[1])) {
            return null;
        }

        $keys = $matches[1];

        // Navigate to location
        $current = $data;
        
        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                return null;
            }
            $current = $current[$key];
        }

        return isset($current[$property]) ? $current[$property] : null;
    }

    /**
     * Replace all occurrences of a URL in Elementor data
     * Useful for bulk replace operations
     * 
     * @param int $post_id Post ID
     * @param string $old_url Old URL to replace
     * @param string $new_url New URL
     * @return int Number of replacements made
     */
    public function replace_all_occurrences($post_id, $old_url, $new_url)
    {
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        
        if (empty($elementor_data)) {
            return 0;
        }

        // Simple string replace in JSON (faster for bulk operations)
        $original = $elementor_data;
        $replaced = str_replace(
            '"' . $old_url . '"',
            '"' . $new_url . '"',
            $elementor_data
        );

        if ($original === $replaced) {
            return 0; // No changes
        }

        // Count occurrences
        $count = substr_count($original, '"' . $old_url . '"');

        // Save updated data
        update_post_meta($post_id, '_elementor_data', wp_slash($replaced));

        // Clear cache
        $this->clear_elementor_cache($post_id);

        error_log("[ELEMENTOR-REPLACER] Bulk replaced {$count} occurrences in post {$post_id}");

        return $count;
    }
}
