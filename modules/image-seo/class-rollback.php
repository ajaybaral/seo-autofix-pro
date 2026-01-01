<?php
/**
 * Image SEO Module - Rollback System
 * 
 * Handles change tracking and rollback functionality
 * 
 * @package SEO_AutoFix_Pro
 * @subpackage Image_SEO
 */

namespace SEOAutoFix\ImageSEO;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rollback Class
 */
class Rollback {
    
    /**
     * Store a change for rollback
     *
     * @param int $attachment_id The attachment ID
     * @param string $field The field name
     * @param mixed $old_value The old value
     * @param mixed $new_value The new value
     * @return bool Success status
     */
    public function store_change($attachment_id, $field, $old_value, $new_value) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'seoautofix_imageseo_rollback';
        
        $data = array(
            'attachment_id' => $attachment_id,
            'field_name' => $field,
            'old_value' => $old_value,
            'new_value' => $new_value,
            'user_id' => get_current_user_id(),
            'timestamp' => current_time('mysql'),
            'rolled_back' => 0
        );
        
        return $wpdb->insert($table, $data) !== false;
    }
    
    /**
     * Rollback a specific image
     *
     * @param int $attachment_id The attachment ID
     * @return bool Success status
     */
    public function rollback_image($attachment_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'seoautofix_imageseo_rollback';
        
        // Get all changes for this image that haven't been rolled back
        $changes = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE attachment_id = %d AND rolled_back = 0 ORDER BY timestamp DESC",
            $attachment_id
        ), ARRAY_A);
        
        if (empty($changes)) {
            return false;
        }
        
        $success = true;
        
        foreach ($changes as $change) {
            // Restore old value
            if ($change['field_name'] === '_wp_attachment_image_alt') {
                update_post_meta($attachment_id, $change['field_name'], $change['old_value']);
            }
            
            // Mark as rolled back
            $wpdb->update(
                $table,
                array('rolled_back' => 1),
                array('id' => $change['id'])
            );
        }
        
        return $success;
    }
    
    /**
     * Rollback all changes
     *
     * @return int Number of images rolled back
     */
    public function rollback_all() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'seoautofix_imageseo_rollback';
        
        // Get all unique attachment IDs
        $attachment_ids = $wpdb->get_col(
            "SELECT DISTINCT attachment_id FROM $table WHERE rolled_back = 0"
        );
        
        $count = 0;
        
        foreach ($attachment_ids as $attachment_id) {
            if ($this->rollback_image($attachment_id)) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Get rollback history for an image
     *
     * @param int $attachment_id The attachment ID
     * @return array History records
     */
    public function get_history($attachment_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'seoautofix_imageseo_rollback';
        
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE attachment_id = %d ORDER BY timestamp DESC",
            $attachment_id
        ), ARRAY_A);
        
        return $history;
    }
    
    /**
     * Clear old rollback data (older than X days)
     *
     * @param int $days Number of days to keep
     * @return int Number of records deleted
     */
    public function cleanup_old_data($days = 30) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'seoautofix_imageseo_rollback';
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        return $deleted;
    }
}
