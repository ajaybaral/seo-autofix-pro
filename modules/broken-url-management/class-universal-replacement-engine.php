<?php
/**
 * Universal Replacement Engine - Builder-agnostic URL replacement
 * 
 * Handles URL replacement in any data structure without needing to know
 * the source page builder. Works with JSON, serialized data, and plain strings.
 * 
 * @package SEOAutoFix\BrokenUrlManagement
 * @since 1.0.0
 */

namespace SEOAutoFix\BrokenUrlManagement;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Universal Replacement Engine Class
 * 
 * This class provides builder-agnostic URL replacement functionality.
 * It scans all post meta fields and intelligently handles different data formats:
 * - JSON strings (Elementor, Oxygen, etc.)
 * - PHP serialized data (WPBakery, some themes)
 * - Plain strings (Classic Editor, Gutenberg)
 */
class Universal_Replacement_Engine
{
    /**
     * Data type constants
     */
    const TYPE_JSON = 'json';
    const TYPE_SERIALIZED = 'serialized';
    const TYPE_STRING = 'string';

    /**
     * Statistics tracking
     */
    private $stats = [
        'meta_keys_scanned' => 0,
        'meta_keys_modified' => 0,
        'replacements_made' => 0,
        'json_fields_processed' => 0,
        'serialized_fields_processed' => 0,
        'string_fields_processed' => 0
    ];

    /**
     * Meta keys to exclude from scanning (performance optimization)
     * These keys typically don't contain URLs or are system-managed
     */
    private $excluded_meta_keys = [
        '_edit_lock',
        '_edit_last',
        '_wp_page_template',
        '_thumbnail_id',
        '_wp_attachment_metadata',
        '_wp_attached_file',
        '_encloseme',
        '_pingme',
        '_elementor_css', // Elementor compiled CSS - don't modify
        '_elementor_version',
        '_elementor_edit_mode',
        '_elementor_template_type',
        '_elementor_pro_version'
    ];

    /**
     * Replace URL in a WordPress post and all its meta data
     * 
     * @param int $post_id Post ID
     * @param string $old_url URL to replace
     * @param string $new_url Replacement URL
     * @return array Result with statistics
     */
    public function replace_url_in_post($post_id, $old_url, $new_url)
    {
        \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] Starting URL replacement for post ID: ' . $post_id);
        \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] Old URL: ' . $old_url);
        \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] New URL: ' . $new_url);

        // Reset stats
        $this->stats = [
            'meta_keys_scanned' => 0,
            'meta_keys_modified' => 0,
            'replacements_made' => 0,
            'json_fields_processed' => 0,
            'serialized_fields_processed' => 0,
            'string_fields_processed' => 0,
            'post_content_modified' => false
        ];

        $success = false;

        // Step 1: Replace in post_content
        $content_result = $this->replace_url_in_post_content($post_id, $old_url, $new_url);
        if ($content_result) {
            $success = true;
            $this->stats['post_content_modified'] = true;
            \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] ✅ Replaced in post_content');
        }

        // Step 2: Replace in ALL post meta
        $meta_result = $this->replace_url_in_all_meta($post_id, $old_url, $new_url);
        if ($meta_result) {
            $success = true;
            \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] ✅ Replaced in post meta');
        }

        // Log final statistics
        \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] === Replacement Statistics ===');
        \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] Meta keys scanned: ' . $this->stats['meta_keys_scanned']);
        \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] Meta keys modified: ' . $this->stats['meta_keys_modified']);
        \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] Total replacements: ' . $this->stats['replacements_made']);
        \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] JSON fields: ' . $this->stats['json_fields_processed']);
        \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] Serialized fields: ' . $this->stats['serialized_fields_processed']);
        \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] String fields: ' . $this->stats['string_fields_processed']);

        return [
            'success' => $success,
            'stats' => $this->stats
        ];
    }

    /**
     * Replace URL in post_content field
     * 
     * @param int $post_id Post ID
     * @param string $old_url URL to replace
     * @param string $new_url Replacement URL
     * @return bool True if replacement was made
     */
    public function replace_url_in_post_content($post_id, $old_url, $new_url)
    {
        $post = get_post($post_id);

        if (!$post || empty($post->post_content)) {
            \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] No post_content to process');
            return false;
        }

        // Check if URL exists in content
        if (stripos($post->post_content, $old_url) === false) {
            \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] URL not found in post_content');
            return false;
        }

        \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] URL found in post_content, replacing...');

        // Perform replacement (case-insensitive)
        $new_content = str_ireplace($old_url, $new_url, $post->post_content);

        // Count replacements
        $count = substr_count(strtolower($post->post_content), strtolower($old_url));
        $this->stats['replacements_made'] += $count;

        // Update post
        $result = wp_update_post([
            'ID' => $post_id,
            'post_content' => $new_content
        ], true);

        if (is_wp_error($result)) {
            \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] ❌ wp_update_post failed: ' . $result->get_error_message());
            return false;
        }

        \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] ✅ post_content updated (' . $count . ' replacement(s))');
        return true;
    }

    /**
     * Replace URL in all post meta fields
     * 
     * Scans all post meta keys and performs replacement in any field
     * containing the target URL, regardless of data format.
     * 
     * @param int $post_id Post ID
     * @param string $old_url URL to replace
     * @param string $new_url Replacement URL
     * @return bool True if any replacement was made
     */
    public function replace_url_in_all_meta($post_id, $old_url, $new_url)
    {
        // Get all post meta (returns array with meta_key => array of values)
        $all_meta = get_post_meta($post_id);

        if (empty($all_meta)) {
            \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] No post meta found');
            return false;
        }

        \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] Scanning ' . count($all_meta) . ' meta keys...');

        $any_modified = false;

        // Apply user-defined exclusions from settings
        $user_excluded = $this->get_user_excluded_meta_keys();
        $excluded_keys = array_merge($this->excluded_meta_keys, $user_excluded);

        foreach ($all_meta as $meta_key => $meta_values) {
            $this->stats['meta_keys_scanned']++;

            // Skip excluded keys
            if (in_array($meta_key, $excluded_keys)) {
                continue;
            }

            // Process each value (meta can have multiple values for same key)
            foreach ($meta_values as $meta_value) {
                // Skip if URL not present (performance optimization)
                if (stripos($meta_value, $old_url) === false) {
                    continue;
                }

                \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] URL found in meta key: ' . $meta_key);

                // Detect data type and process accordingly
                $result = $this->replace_in_meta_value($meta_key, $meta_value, $old_url, $new_url, $post_id);

                if ($result['modified']) {
                    $any_modified = true;
                    $this->stats['meta_keys_modified']++;
                    $this->stats['replacements_made'] += $result['count'];
                }
            }
        }

        return $any_modified;
    }

    /**
     * Replace URL in a single meta value
     * 
     * Automatically detects data format (JSON, serialized, string) and
     * handles replacement accordingly.
     * 
     * @param string $meta_key Meta key
     * @param mixed $meta_value Meta value
     * @param string $old_url URL to replace
     * @param string $new_url Replacement URL
     * @param int $post_id Post ID (for updating)
     * @return array Result with 'modified' (bool) and 'count' (int)
     */
    private function replace_in_meta_value($meta_key, $meta_value, $old_url, $new_url, $post_id)
    {
        // Detect data type
        $data_info = $this->detect_and_decode_data($meta_value);
        $data_type = $data_info['type'];
        $decoded_data = $data_info['data'];

        \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] Meta key "' . $meta_key . '" detected as: ' . $data_type);

        $modified = false;
        $replacement_count = 0;

        switch ($data_type) {
            case self::TYPE_JSON:
                // Handle JSON data (Elementor, Oxygen, etc.)
                $this->stats['json_fields_processed']++;
                
                $replaced_data = $this->replace_in_structure_recursive($decoded_data, $old_url, $new_url, $replacement_count);
                
                if ($replacement_count > 0) {
                    $new_json = wp_json_encode($replaced_data);
                    update_post_meta($post_id, $meta_key, wp_slash($new_json));
                    $modified = true;
                    \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] ✅ Updated JSON meta: ' . $meta_key . ' (' . $replacement_count . ' replacement(s))');
                }
                break;

            case self::TYPE_SERIALIZED:
                // Handle PHP serialized data (WPBakery, some themes)
                $this->stats['serialized_fields_processed']++;
                
                $replaced_data = $this->replace_in_structure_recursive($decoded_data, $old_url, $new_url, $replacement_count);
                
                if ($replacement_count > 0) {
                    update_post_meta($post_id, $meta_key, $replaced_data);
                    $modified = true;
                    \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] ✅ Updated serialized meta: ' . $meta_key . ' (' . $replacement_count . ' replacement(s))');
                }
                break;

            case self::TYPE_STRING:
                // Handle plain string data
                $this->stats['string_fields_processed']++;
                
                $new_value = str_ireplace($old_url, $new_url, $meta_value);
                $replacement_count = substr_count(strtolower($meta_value), strtolower($old_url));
                
                if ($new_value !== $meta_value) {
                    update_post_meta($post_id, $meta_key, $new_value);
                    $modified = true;
                    \SEOAutoFix_Debug_Logger::log('[UNIVERSAL] ✅ Updated string meta: ' . $meta_key . ' (' . $replacement_count . ' replacement(s))');
                }
                break;
        }

        return [
            'modified' => $modified,
            'count' => $replacement_count
        ];
    }

    /**
     * Recursively replace URLs in any data structure
     * 
     * Handles nested arrays, objects, and strings to any depth.
     * 
     * @param mixed $data Data to process (string, array, object, etc.)
     * @param string $old_url URL to replace
     * @param string $new_url Replacement URL
     * @param int &$count Reference to replacement counter
     * @return mixed Modified data
     */
    public function replace_in_structure_recursive($data, $old_url, $new_url, &$count)
    {
        // Handle strings
        if (is_string($data)) {
            if (stripos($data, $old_url) !== false) {
                $occurrences = substr_count(strtolower($data), strtolower($old_url));
                $count += $occurrences;
                return str_ireplace($old_url, $new_url, $data);
            }
            return $data;
        }

        // Handle arrays
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->replace_in_structure_recursive($value, $old_url, $new_url, $count);
            }
            return $data;
        }

        // Handle objects
        if (is_object($data)) {
            foreach ($data as $key => $value) {
                $data->$key = $this->replace_in_structure_recursive($value, $old_url, $new_url, $count);
            }
            return $data;
        }

        // Return unchanged for other types (int, bool, null, etc.)
        return $data;
    }

    /**
     * Detect data type and decode if necessary
     * 
     * Automatically detects whether data is JSON, PHP serialized, or plain string.
     * 
     * @param string $value Raw value from database
     * @return array ['type' => string, 'data' => mixed]
     */
    private function detect_and_decode_data($value)
    {
        // Try JSON first (most common for modern builders)
        if (is_string($value) && strlen($value) > 0 && ($value[0] === '{' || $value[0] === '[')) {
            $decoded = @json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return [
                    'type' => self::TYPE_JSON,
                    'data' => $decoded
                ];
            }
        }

        // Try PHP serialization (older builders, some themes)
        if (is_string($value) && $this->is_serialized($value)) {
            $decoded = @unserialize($value);
            if ($decoded !== false) {
                return [
                    'type' => self::TYPE_SERIALIZED,
                    'data' => $decoded
                ];
            }
        }

        // Default to plain string
        return [
            'type' => self::TYPE_STRING,
            'data' => $value
        ];
    }

    /**
     * Check if a value is serialized (more reliable than is_serialized())
     * 
     * @param string $data Data to check
     * @return bool True if serialized
     */
    private function is_serialized($data)
    {
        if (!is_string($data)) {
            return false;
        }

        $data = trim($data);
        
        if ('N;' === $data) {
            return true;
        }
        
        if (strlen($data) < 4) {
            return false;
        }
        
        if (':' !== $data[1]) {
            return false;
        }
        
        $lastc = substr($data, -1);
        if (';' !== $lastc && '}' !== $lastc) {
            return false;
        }
        
        $token = $data[0];
        switch ($token) {
            case 's':
            case 'a':
            case 'O':
                return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
            case 'b':
            case 'i':
            case 'd':
                return (bool) preg_match("/^{$token}:[0-9.E-]+;$/", $data);
        }
        
        return false;
    }

    /**
     * Get user-defined excluded meta keys from settings
     * 
     * @return array Array of meta keys to exclude
     */
    private function get_user_excluded_meta_keys()
    {
        $settings = get_option('seoautofix_settings', []);
        $excluded = isset($settings['excluded_meta_keys']) ? $settings['excluded_meta_keys'] : '';

        if (empty($excluded)) {
            return [];
        }

        // Split by newlines and trim whitespace
        $keys = array_map('trim', explode("\n", $excluded));
        
        // Remove empty entries
        $keys = array_filter($keys);

        return $keys;
    }

    /**
     * Get replacement statistics
     * 
     * @return array Statistics array
     */
    public function get_stats()
    {
        return $this->stats;
    }
}
