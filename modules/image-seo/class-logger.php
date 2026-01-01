<?php
/**
 * Image SEO Module - Logger
 * 
 * Handles logging and audit trails
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
 * Logger Class
 */
class Logger {
    
    /**
     * Log an action
     *
     * @param string $action The action type
     * @param int $attachment_id The attachment ID
     * @param array $data Additional data
     */
    public function log_action($action, $attachment_id, $data = array()) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'seoautofix_imageseo_audit';
        
        $log_data = array(
            'attachment_id' => $attachment_id,
            'issue_type' => isset($data['issue_type']) ? $data['issue_type'] : '',
            'original_alt' => isset($data['old']) ? $data['old'] : '',
            'suggested_alt' => isset($data['new']) ? $data['new'] : '',
            'ai_score_before' => isset($data['score_before']) ? $data['score_before'] : 0,
            'ai_score_after' => isset($data['score_after']) ? $data['score_after'] : 0,
            'status' => $action,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );
        
        $wpdb->insert($table, $log_data);
    }
    
    /**
     * Log an error
     *
     * @param string $context Error context
     * @param string $message Error message
     */
    public function log_error($context, $message) {
        error_log(sprintf(
            'Image SEO - %s: %s',
            $context,
            $message
        ));
    }
    
    /**
     * Log API call
     *
     * @param int $tokens_used Tokens consumed
     * @param float $cost Estimated cost
     */
    public function log_api_call($tokens_used, $cost = 0) {
        // Store API usage stats
        $stats = get_option('imageseo_api_stats', array(
            'total_calls' => 0,
            'total_tokens' => 0,
            'total_cost' => 0
        ));
        
        $stats['total_calls']++;
        $stats['total_tokens'] += $tokens_used;
        $stats['total_cost'] += $cost;
        
        update_option('imageseo_api_stats', $stats);
    }
    
    /**
     * Get recent logs
     *
     * @param int $limit Number of logs to retrieve
     * @return array Recent logs
     */
    public function get_recent_logs($limit = 100) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'seoautofix_imageseo_audit';
        
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d",
            $limit
        ), ARRAY_A);
        
        return $logs;
    }
    
    /**
     * Get API usage stats
     *
     * @return array API usage statistics
     */
    public function get_api_stats() {
        return get_option('imageseo_api_stats', array(
            'total_calls' => 0,
            'total_tokens' => 0,
            'total_cost' => 0
        ));
    }
}
