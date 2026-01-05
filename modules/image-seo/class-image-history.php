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
        
        error_log('IMAGESEO DEBUG: update_image_history called for ID: ' . $attachment_id);
        error_log('IMAGESEO DEBUG: Data passed: ' . print_r($data, true));
        
        // Get existing record
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE attachment_id = %d",
            $attachment_id
        ));
        
        if ($existing) {
            error_log('IMAGESEO DEBUG: Existing record FOUND with status: ' . $existing->status);
        } else {
            error_log('IMAGESEO DEBUG: Existing record NOT FOUND');
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
                error_log('IMAGESEO ERROR: Update failed for ID ' . $attachment_id . ': ' . $wpdb->last_error);
            } else {
                error_log('IMAGESEO DEBUG: Updated record for ID ' . $attachment_id);
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
            
            error_log('IMAGESEO DEBUG: Inserting new record for ID ' . $attachment_id);
            
            $result = $wpdb->insert(
                $this->table_name,
                $insert_data,
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
            
            if ($result === false) {
                error_log('IMAGESEO ERROR: Insert failed for ID ' . $attachment_id . ': ' . $wpdb->last_error);
            } else {
                error_log('IMAGESEO DEBUG: Inserted new record for ID ' . $attachment_id);
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
        
        error_log('IMAGESEO DEBUG: get_statistics called');
        error_log('IMAGESEO DEBUG: Table name: ' . $this->table_name);
        error_log('CLASSIFICATION-DEBUG: === STATISTICS BREAKDOWN ===');
        
        // ORPHAN DETECTION - Find records where WordPress attachment no longer exists
        $all_ids = $wpdb->get_col("SELECT DISTINCT attachment_id FROM {$this->table_name}");
        error_log('ORPHAN-DEBUG: ===== CHECKING FOR ORPHAN RECORDS =====');
        error_log('ORPHAN-DEBUG: Total attachment IDs in history table: ' . count($all_ids));
        
        $orphan_ids = array();
        foreach ($all_ids as $att_id) {
            if (!get_post($att_id)) {
                $orphan_ids[] = $att_id;
            }
        }
        
        error_log('ORPHAN-DEBUG: Found ' . count($orphan_ids) . ' ORPHAN records (in history but deleted from WordPress)');
        if (!empty($orphan_ids)) {
            error_log('ORPHAN-DEBUG: Orphan IDs: ' . implode(', ', $orphan_ids));
            error_log('ORPHAN-DEBUG: These ' . count($orphan_ids) . ' records are inflating your stats!');
            error_log('ORPHAN-DEBUG: Auto-cleaning orphans...');
            
            // Auto-cleanup orphans
            $cleaned = $this->cleanup_orphans();
            error_log('ORPHAN-DEBUG: ✅ Auto-cleanup removed ' . $cleaned . ' orphan records');
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
        error_log('CLASSIFICATION-DEBUG: Total images: ' . $total);
        error_log('CLASSIFICATION-DEBUG: Status=optimal: ' . $optimal);
        error_log('CLASSIFICATION-DEBUG: Status=blank: ' . $blank);
        error_log('CLASSIFICATION-DEBUG: Status=generate: ' . $generate);
        error_log('CLASSIFICATION-DEBUG: Status=optimized: ' . $optimized_status);
        error_log('CLASSIFICATION-DEBUG: Status=skipped: ' . $skipped);
        error_log('CLASSIFICATION-DEBUG: issue_type=empty + status=blank: ' . $empty);
        error_log('CLASSIFICATION-DEBUG: issue_type=generic + status=blank: ' . $generic);
        
        // CLASSIFICATION-DEBUG: Check for low-score images
        $low_score_blank = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status='blank' AND issue_type NOT IN ('empty', 'generic')");
        error_log('CLASSIFICATION-DEBUG: status=blank but NOT empty/generic: ' . $low_score_blank);
        error_log('CLASSIFICATION-DEBUG: ^ These should be LOW SCORE images!');
        
        // STATS-REDESIGN-DEBUG: Get detailed breakdown
        error_log('STATS-REDESIGN-DEBUG: === DETAILED BREAKDOWN FOR NEW UI ===');
        error_log('STATS-REDESIGN-DEBUG: Low Score - Empty Alt: ' . $empty);
        error_log('STATS-REDESIGN-DEBUG: Low Score - Has Alt but low score: ' . $low_score_blank);
        error_log('STATS-REDESIGN-DEBUG: Optimized (good images): ' . ($optimal + $generate + $optimized_status));
        
        // Get sample low-score images with alt
        $sample_low_score = $wpdb->get_results(
            "SELECT attachment_id, alt_history FROM {$this->table_name} WHERE status='blank' AND issue_type NOT IN ('empty', 'generic') LIMIT 5",
            ARRAY_A
        );
        error_log('STATS-REDESIGN-DEBUG: Sample low-score images with alt text:');
        foreach ($sample_low_score as $img) {
            $alt_history = json_decode($img['alt_history'], true);
            $current_alt = isset($alt_history[0]) ? $alt_history[0] : '';
            error_log('STATS-REDESIGN-DEBUG:   ID=' . $img['attachment_id'] . ' alt="' . substr($current_alt, 0, 50) . '"');
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
        
        error_log('========================================');
        error_log('MISSING-9-DEBUG: ===== FINDING THE 9 MISSING IMAGES =====');
        error_log('MISSING-9-DEBUG: TOTAL in database: ' . $total);
        error_log('MISSING-9-DEBUG: Status breakdown:');
        error_log('MISSING-9-DEBUG:   - optimal: ' . $optimal);
        error_log('MISSING-9-DEBUG:   - blank: ' . $blank);
        error_log('MISSING-9-DEBUG:   - generate: ' . $generate);
        error_log('MISSING-9-DEBUG:   - optimized: ' . $optimized_status);
        error_log('MISSING-9-DEBUG:   - skipped: ' . $skipped);
        
        $accounted_for = $optimal + $blank + $generate + $optimized_status + $skipped;
        error_log('MISSING-9-DEBUG: Sum of all statuses: ' . $accounted_for);
        error_log('MISSING-9-DEBUG: Missing from status count: ' . ($total - $accounted_for));
        
        // What's shown to user:
        $shown_in_stats = $empty + $low_score_blank + ($optimal + $optimized_status);
        error_log('MISSING-9-DEBUG: ===== WHAT USER SEES =====');
        error_log('MISSING-9-DEBUG: Empty Alt: ' . $empty);
        error_log('MISSING-9-DEBUG: Has Alt (Low Score): ' . $low_score_blank);
        error_log('MISSING-9-DEBUG: Optimized: ' . ($optimal + $optimized_status));
        error_log('MISSING-9-DEBUG: Total shown in UI: ' . $shown_in_stats);
        error_log('MISSING-9-DEBUG: ⚠️ MISSING FROM UI: ' . ($total - $shown_in_stats) . ' images!');
        
        // Find the missing images
        $missing_ids = $wpdb->get_results(
            "SELECT attachment_id, status, issue_type, alt_history 
             FROM {$this->table_name} 
             WHERE status NOT IN ('optimal', 'optimized', 'blank')
             ORDER BY status, attachment_id",
            ARRAY_A
        );
        
        error_log('MISSING-9-DEBUG: ===== THE MISSING IMAGES =====');
        error_log('MISSING-9-DEBUG: Found ' . count($missing_ids) . ' images with status NOT IN (optimal, optimized, blank)');
        foreach ($missing_ids as $img) {
            $alt_history = json_decode($img['alt_history'], true);
            $current_alt = isset($alt_history[0]) ? $alt_history[0] : '';
            error_log('MISSING-9-DEBUG:   ID=' . $img['attachment_id'] . 
                     ' status="' . $img['status'] . '"' .
                     ' issue_type="' . $img['issue_type'] . '"' .
                     ' alt="' . substr($current_alt, 0, 50) . '"');
        }
        error_log('========================================');
        
        error_log('========================================');
        error_log('DISCREPANCY-DEBUG: ===== FINDING THE 8 MISSING IMAGES =====');
        error_log('DISCREPANCY-DEBUG: Stats says OPTIMIZED COUNT: ' . ($optimal + $optimized_status));
        error_log('DISCREPANCY-DEBUG: Breaking down:');
        error_log('DISCREPANCY-DEBUG:   - status=optimal: ' . $optimal);
        error_log('DISCREPANCY-DEBUG:   - status=generate: ' . $generate . ' (EXCLUDED from optimized count - these are queued, not done!)');
        error_log('DISCREPANCY-DEBUG:   - status=optimized: ' . $optimized_status);
        
        // Get ALL IDs that are counted as "optimized" in the stats
        $optimized_ids = $wpdb->get_col(
            "SELECT attachment_id FROM {$this->table_name} 
             WHERE status IN ('optimal', 'optimized')
             ORDER BY attachment_id"
        );
        
        error_log('DISCREPANCY-DEBUG: IDs counted as optimized in stats (' . count($optimized_ids) . ' total):');
        error_log('DISCREPANCY-DEBUG: ' . implode(', ', $optimized_ids));
        
        // Also get their alt text to check
        $optimized_details = $wpdb->get_results(
            "SELECT attachment_id, status, issue_type, alt_history 
             FROM {$this->table_name} 
             WHERE status IN ('optimal', 'optimized')
             ORDER BY attachment_id",
            ARRAY_A
        );
        
        error_log('DISCREPANCY-DEBUG: Details of optimized images:');
        foreach ($optimized_details as $img) {
            $alt_history = json_decode($img['alt_history'], true);
            $current_alt = isset($alt_history[0]) ? $alt_history[0] : '';
            error_log('DISCREPANCY-DEBUG:   ID=' . $img['attachment_id'] . 
                     ' status="' . $img['status'] . '"' .
                     ' issue_type="' . $img['issue_type'] . '"' .
                     ' alt="' . substr($current_alt, 0, 50) . '"');
        }
        error_log('========================================');
        
        error_log('CLASSIFICATION-DEBUG: NEW STATS FORMAT - low_score_empty:' . $empty . ' low_score_with_alt:' . $low_score_blank . ' optimized:' . ($optimal + $optimized_status));
        error_log('IMAGESEO DEBUG: Stats results (mapped for frontend): ' . print_r($stats, true));
        
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
        
        error_log('ORPHAN-CLEANUP: Starting cleanup');
        
        $all_ids = $wpdb->get_col("SELECT DISTINCT attachment_id FROM {$this->table_name}");
        $deleted_count = 0;
        
        foreach ($all_ids as $att_id) {
            if (!get_post($att_id)) {
                $wpdb->delete($this->table_name, array('attachment_id' => $att_id), array('%d'));
                $deleted_count++;
            }
        }
        
        error_log('ORPHAN-CLEANUP: Removed ' . $deleted_count . ' orphan records');
        return $deleted_count;
    }
}
