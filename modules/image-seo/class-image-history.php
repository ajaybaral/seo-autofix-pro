<?php
/**
 * Image SEO Module - Image History Tracker
 * 
 * Manages centralized tracking of all image alt/title changes
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
 * Image History Tracker Class
 */
class Image_History {
    
    /**
     * Table name
     */
    private $table_name;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'seoautofix_image_history';
    }
    
    /**
     * Update or create image history record
     *
     * @param int $attachment_id The attachment ID
     * @param array $data Data to update
     * @return bool Success status
     */
    public function update_image_history($attachment_id, $data) {
        global $wpdb;
        


        
        // Get existing record
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE attachment_id = %d",
            $attachment_id
        ));
        
        if ($existing) {

        } else {

        }
        
        $current_time = current_time('mysql');
        
        if ($existing) {
            // Update existing record
            $update_data = array('last_updated' => $current_time);
            
            // Handle alt history
            if (isset($data['new_alt'])) {
                $alt_history = json_decode($existing->alt_history, true) ?: array();
                $alt_history[] = $data['new_alt'];
                $update_data['alt_history'] = json_encode($alt_history);
            }
            
            // Handle title history
            if (isset($data['new_title'])) {
                $title_history = json_decode($existing->title_history, true) ?: array();
                $title_history[] = $data['new_title'];
                $update_data['title_history'] = json_encode($title_history);
            }
            
            // Update status
            if (isset($data['status'])) {
                $update_data['status'] = $data['status'];
            }
            
            // Update issue type
            if (isset($data['issue_type'])) {
                $update_data['issue_type'] = $data['issue_type'];
            }
            
            $result = $wpdb->update(
                $this->table_name,
                $update_data,
                array('attachment_id' => $attachment_id),
                array('%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );
            
            if ($result === false) {

            } else {

            }
            
            return $result;
        } else {
            // Create new record
            $insert_data = array(
                'attachment_id' => $attachment_id,
                'image_permalink' => get_permalink($attachment_id),
                'image_name' => basename(get_attached_file($attachment_id)),
                'alt_history' => isset($data['alt_history']) ? json_encode($data['alt_history']) : json_encode(array()),
                'title_history' => isset($data['title_history']) ? json_encode($data['title_history']) : json_encode(array()),
                'status' => isset($data['status']) ? $data['status'] : 'blank',
                'issue_type' => isset($data['issue_type']) ? $data['issue_type'] : null,
                'image_url' => wp_get_attachment_url($attachment_id),
                'media_link' => admin_url('post.php?post=' . $attachment_id . '&action=edit'),
                'scan_timestamp' => $current_time,
                'last_updated' => $current_time
            );
            

            
            $result = $wpdb->insert(
                $this->table_name,
                $insert_data,
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
            
            if ($result === false) {

            } else {

            }
            
            return $result;
        }
    }
    
    /**
     * Get image history
     *
     * @param int $attachment_id The attachment ID
     * @return object|null History record
     */
    public function get_image_history($attachment_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE attachment_id = %d",
            $attachment_id
        ));
    }
    
    /**
     * Get statistics from history table
     *
     * @return array Statistics
     */
    public function get_statistics() {
        global $wpdb;
        



        
        // ORPHAN DETECTION - Find records where WordPress attachment no longer exists
        $all_ids = $wpdb->get_col("SELECT DISTINCT attachment_id FROM {$this->table_name}");


        
        $orphan_ids = array();
        foreach ($all_ids as $att_id) {
            if (!get_post($att_id)) {
                $orphan_ids[] = $att_id;
            }
        }
        

        if (!empty($orphan_ids)) {



            
            // Auto-cleanup orphans
            $cleaned = $this->cleanup_orphans();

        }
        
        // Get raw counts from database
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $optimal = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status='optimal'");
        $blank = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status='blank'");
        $generate = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status='generate'");
        $optimized_status = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status='optimized'");
        $skipped = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status='skipped'");
        $empty = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE issue_type='empty' AND status='blank'");
        $generic = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE issue_type='generic' AND status='blank'");
        
        // CLASSIFICATION-DEBUG: Log each status count








        
        // CLASSIFICATION-DEBUG: Check for low-score images
        $low_score_blank = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status='blank' AND issue_type NOT IN ('empty', 'generic')");


        
        // LOW-SCORE-COUNT-DEBUG: Detailed breakdown to find the 4 missing images

        $all_blank_rows = $wpdb->get_results("SELECT attachment_id, status, issue_type FROM {$this->table_name} WHERE status='blank'", ARRAY_A);

        
        $empty_count = 0;
        $generic_count = 0;
        $should_be_low_score = 0;
        $null_issue_type = 0;
        
        foreach ($all_blank_rows as $row) {
            if ($row['issue_type'] === 'empty') {
                $empty_count++;
            } elseif ($row['issue_type'] === 'generic') {
                $generic_count++;
            } elseif ($row['issue_type'] === null || $row['issue_type'] === '') {
                $null_issue_type++;

            } else {
                $should_be_low_score++;
            }
        }
        







        
        // STATS-REDESIGN-DEBUG: Get detailed breakdown




        
        // Get sample low-score images with alt
        $sample_low_score = $wpdb->get_results(
            "SELECT attachment_id, alt_history FROM {$this->table_name} WHERE status='blank' AND issue_type NOT IN ('empty', 'generic') LIMIT 5",
            ARRAY_A
        );

        foreach ($sample_low_score as $img) {
            $alt_history = json_decode($img['alt_history'], true);
            $current_alt = isset($alt_history[0]) ? $alt_history[0] : '';

        }
        
        // Map to frontend-expected field names (NEW STRUCTURE)
        // FIX: Do NOT count 'generate' status as optimized!
        // 'generate' = user queued AI generation but hasn't applied it yet
        // Only count 'optimal' (score >75) and 'optimized' (manually marked)
        $stats = array(
            'total' => $total,
            'low_score_empty' => $empty,  // Empty alt text
            'low_score_with_alt' => $low_score_blank,  // Has alt but low score
            'optimized' => $optimal + $optimized_status  // FIXED: Exclude $generate
        );
        









        
        $accounted_for = $optimal + $blank + $generate + $optimized_status + $skipped;


        
        // What's shown to user:
        $shown_in_stats = $empty + $low_score_blank + ($optimal + $optimized_status);






        
        // Find the missing images
        $missing_ids = $wpdb->get_results(
            "SELECT attachment_id, status, issue_type, alt_history 
             FROM {$this->table_name} 
             WHERE status NOT IN ('optimal', 'optimized', 'blank')
             ORDER BY status, attachment_id",
            ARRAY_A
        );
        


        foreach ($missing_ids as $img) {
            $alt_history = json_decode($img['alt_history'], true);
            $current_alt = isset($alt_history[0]) ? $alt_history[0] : '';
        }

        







        
        // Get ALL IDs that are counted as "optimized" in the stats
        $optimized_ids = $wpdb->get_col(
            "SELECT attachment_id FROM {$this->table_name} 
             WHERE status IN ('optimal', 'optimized')
             ORDER BY attachment_id"
        );
        


        
        // Also get their alt text to check
        $optimized_details = $wpdb->get_results(
            "SELECT attachment_id, status, issue_type, alt_history 
             FROM {$this->table_name} 
             WHERE status IN ('optimal', 'optimized')
             ORDER BY attachment_id",
            ARRAY_A
        );
        


        foreach ($optimized_details as $img) {
            $alt_history = json_decode($img['alt_history'], true);
            $current_alt = isset($alt_history[0]) ? $alt_history[0] : '';
        }

        


        
        return $stats;
    }
    
    /**
     * Get all history records for export
     *
     * @return array History records
     */
    public function get_all_for_export() {
        global $wpdb;
        
        // Get ALL images from history table (not just those with actions)
        $results = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} ORDER BY attachment_id DESC"
        );
        
        return $results;
    }
    
    /**
     * Clear all pending records from previous scan
     */
    public function clear_pending() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$this->table_name} WHERE status='blank'");
    }
    
    /**
     * Clean up orphan records
     */
    public function cleanup_orphans() {
        global $wpdb;
        

        
        $all_ids = $wpdb->get_col("SELECT DISTINCT attachment_id FROM {$this->table_name}");
        $deleted_count = 0;
        
        foreach ($all_ids as $att_id) {
            if (!get_post($att_id)) {
                $wpdb->delete($this->table_name, array('attachment_id' => $att_id), array('%d'));
                $deleted_count++;
            }
        }
        

        return $deleted_count;
    }
}
