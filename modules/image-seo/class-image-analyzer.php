<?php
/**
 * Image SEO Module - Image Analyzer
 * 
 * Scans media library and detects SEO issues
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
 * Image Analyzer Class
 */
class Image_Analyzer {
    
    /**
     * Generic alt text patterns
     */
    private $generic_patterns = array(
        'image',
        'img',
        'photo',
        'picture',
        'screenshot',
        '/^[0-9]+$/',
        '/^img[0-9]+$/',
        '/^image[0-9]+$/',
    );
    
    /**
     * Scan all images in media library
     *
     * @param int $batch_size Number of images per batch
     * @param int $offset Starting offset
     * @param object $usage_tracker Usage tracker instance (optional)
     * @param string $status_filter Filter by status: 'blank' (default) or 'optimal'
     * @return array Array of images
     */
    public function scan_all_images($batch_size = 50, $offset = 0, $usage_tracker = null, $status_filter = 'blank') {
        global $wpdb;
        $history_table = $wpdb->prefix . 'seoautofix_image_history';
        
        error_log('========================================');
        error_log('SCAN-ALL-DEBUG: [Backend] ===== scan_all_images() CALLED =====');
        error_log('SCAN-ALL-DEBUG: [Backend] Parameters:');
        error_log('SCAN-ALL-DEBUG: [Backend]   - batch_size: ' . $batch_size);
        error_log('SCAN-ALL-DEBUG: [Backend]   - offset: ' . $offset);
        error_log('SCAN-ALL-DEBUG: [Backend]   - status_filter: "' . $status_filter . '"');
        
        $valid_statuses = array('blank', 'optimal');
        if (!in_array($status_filter, $valid_statuses)) {
            error_log('SCAN-ALL-DEBUG: [Backend] ⚠️ Invalid status_filter, defaulting to "blank"');
            $status_filter = 'blank';
        }
        
        error_log('SCAN-ALL-DEBUG: [Backend] Building SQL query...');
        error_log('SCAN-ALL-DEBUG: [Backend] Table: ' . $history_table);
        error_log('SCAN-ALL-DEBUG: [Backend] WHERE status = "' . $status_filter . '"');
        
        
        // UX-IMPROVEMENT: Scan ALL images (no filtering)
        // Frontend will handle filtering via stat card clicks
        // This ensures user sees all 167 images and can filter by clicking stats
        error_log('UX-IMPROVEMENT-DEBUG: [Backend] Scanning ALL images (no status filter)');
        
        $sql = $wpdb->prepare(
            "SELECT attachment_id, issue_type, status 
             FROM {$history_table} 
             ORDER BY 
                CASE status
                    WHEN 'blank' THEN 1
                    WHEN 'generate' THEN 2
                    WHEN 'skipped' THEN 3
                    WHEN 'optimal' THEN 4
                    WHEN 'optimized' THEN 5
                    ELSE 6
                END,
                attachment_id DESC 
             LIMIT %d OFFSET %d",
            $batch_size,
            $offset
        );
        
        error_log('UX-IMPROVEMENT-DEBUG: [Backend] SQL will return ALL statuses, sorted by priority (issues first)');
        
        error_log('SCAN-ALL-DEBUG: [Backend] Prepared SQL: ' . $sql);
        error_log('SCAN-ALL-DEBUG: [Backend] Executing query...');
        
        $results_data = $wpdb->get_results($sql);
        
        error_log('SCAN-ALL-DEBUG: [Backend] ===== QUERY EXECUTED =====');
        error_log('SCAN-ALL-DEBUG: [Backend] Rows returned: ' . count($results_data));
        
        if (count($results_data) > 0) {
            error_log('SCAN-ALL-DEBUG: [Backend] Sample row (first): ' . print_r($results_data[0], true));
            error_log('SCAN-ALL-DEBUG: [Backend] Status values in results:');
            foreach ($results_data as $idx => $row) {
                if ($idx < 5) { // Log first 5
                    error_log('SCAN-ALL-DEBUG: [Backend]   Row ' . $idx . ': ID=' . $row->attachment_id . ', status="' . $row->status . '"');
                }
            }
        } else {
            error_log('SCAN-ALL-DEBUG: [Backend] ⚠️ NO ROWS RETURNED FROM DATABASE!');
            error_log('SCAN-ALL-DEBUG: [Backend] Checking if table has ANY rows with status="' . $status_filter . '"...');
            $count_check = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$history_table} WHERE status = %s", $status_filter));
            error_log('SCAN-ALL-DEBUG: [Backend] Total rows in DB with status="' . $status_filter . '": ' . $count_check);
        }
        error_log('========================================');
        
        $results = array();
        
        foreach ($results_data as $row) {
            $attachment_id = $row->attachment_id;
            $metadata = $this->get_image_metadata($attachment_id);
            $issues = $this->detect_issues($attachment_id);
            
            // Get usage data if tracker is provided
            $usage_data = array('used_in_posts' => 0, 'used_in_pages' => 0);
            $usage_details = array();
            
            if ($usage_tracker) {
                error_log('GROUPING-DEBUG: [Backend] Getting usage for attachment ID: ' . $attachment_id);
                $usage = $usage_tracker->get_image_usage($attachment_id);
                error_log('GROUPING-DEBUG: [Backend] Raw usage data: ' . print_r($usage, true));
                
                // Count posts vs pages from the 'pages' array
                $post_count = 0;
                $page_count = 0;
                
                if (isset($usage['pages']) && is_array($usage['pages'])) {
                    error_log('GROUPING-DEBUG: [Backend] Found ' . count($usage['pages']) . ' pages/posts using this image');
                    
                    foreach ($usage['pages'] as $page_data) {
                        if (isset($page_data['type'])) {
                            // Add to usage details for frontend grouping
                            $usage_details[] = array(
                                'post_id' => $page_data['post_id'],
                                'title' => $page_data['title'],
                                'type' => $page_data['type'],
                                'url' => isset($page_data['url']) ? $page_data['url'] : ''
                            );
                            
                            // Count by type
                            if ($page_data['type'] === 'post') {
                                $post_count++;
                            } elseif ($page_data['type'] === 'page') {
                                $page_count++;
                            }
                        }
                    }
                    
                    error_log('GROUPING-DEBUG: [Backend] Prepared usage_details: ' . print_r($usage_details, true));
                }
                
                $usage_data = array(
                    'used_in_posts' => $post_count,
                    'used_in_pages' => $page_count
                );
                
                error_log('GROUPING-DEBUG: [Backend] ID ' . $attachment_id . ' - Posts: ' . $post_count . ', Pages: ' . $page_count . ', Details count: ' . count($usage_details));
            }
            
            $results[] = array(
                'id' => $attachment_id,
                'thumbnail' => wp_get_attachment_image_url($attachment_id, 'thumbnail'),
                'title' => get_the_title($attachment_id),
                'filename' => basename(get_attached_file($attachment_id)),
                'current_alt' => $metadata['alt'],
                'issues' => $issues,
                'issue_type' => $row->issue_type,
                'status' => $row->status,  // NEW: Include status (blank/optimal) for visual distinction
                'used_in_posts' => $usage_data['used_in_posts'],
                'used_in_pages' => $usage_data['used_in_pages'],
                'usage_details' => $usage_details  // NEW: Full post/page details for grouping
            );
        }
        
        error_log('========================================');
        error_log('SCAN-RETURN-DEBUG: [Backend] ===== RETURNING RESULTS =====');
        error_log('SCAN-RETURN-DEBUG: [Backend] Total results to return: ' . count($results));
        error_log('SCAN-RETURN-DEBUG: [Backend] Status filter was: "' . $status_filter . '"');
        
        if (count($results) > 0) {
            $result_ids = array_map(function($r) { return $r['id']; }, $results);
            error_log('SCAN-RETURN-DEBUG: [Backend] IDs being returned (' . count($result_ids) . ' total):');
            error_log('SCAN-RETURN-DEBUG: [Backend] ' . implode(', ', $result_ids));
        } else {
            error_log('SCAN-RETURN-DEBUG: [Backend] ⚠️ NO RESULTS - empty array will be returned!');
        }
        error_log('========================================');
        
        return $results;
    }
    
    /**
     * Detect issues for a specific image
     *
     * @param int $attachment_id The attachment ID
     * @return array Array of detected issues
     */
    public function detect_issues($attachment_id) {
        $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $issues = array();
        
        // error_log('SCORE-THRESHOLD-DEBUG: [Backend] Checking image ID: ' . $attachment_id);
        // error_log('SCORE-THRESHOLD-DEBUG: [Backend] Alt text: "' . $alt_text . '"');
        
        // Check for empty alt text
        if (empty($alt_text)) {
            $issues[] = 'empty';
            // error_log('SCORE-THRESHOLD-DEBUG: [Backend] → Issue: EMPTY alt text');
        }
        // Check for generic alt text
        elseif ($this->is_generic_alt($alt_text)) {
            $issues[] = 'generic';
            // error_log('SCORE-THRESHOLD-DEBUG: [Backend] → Issue: GENERIC alt text');
        }
        // Check length
        else {
            $length = strlen($alt_text);
            
            if ($length < 10) {
                $issues[] = 'too_short';
                // error_log('SCORE-THRESHOLD-DEBUG: [Backend] → Issue: TOO SHORT (length: ' . $length . ')');
            } elseif ($length > 60) {
                $issues[] = 'too_long';
                // error_log('SCORE-THRESHOLD-DEBUG: [Backend] → Issue: TOO LONG (length: ' . $length . ')');
            }
        }
        
        // CRITICAL: Check SEO score - must be > 75 to be considered optimized
        if (!empty($alt_text)) {
            require_once IMAGESEO_MODULE_DIR . 'class-seo-scorer.php';
            require_once IMAGESEO_MODULE_DIR . 'class-api-manager.php';
            require_once IMAGESEO_MODULE_DIR . 'class-image-usage-tracker.php';
            
            $api_manager = new API_Manager();
            $seo_scorer = new SEO_Scorer($api_manager);
            $usage_tracker = new Image_Usage_Tracker();
            
            $context = $usage_tracker->get_image_usage($attachment_id);
            $score_data = $seo_scorer->score_alt_text($alt_text, $context);
            $score = $score_data['score'];
            
            // error_log('SCORE-THRESHOLD-DEBUG: [Backend] SEO Score: ' . $score);
            
            // SCORE MUST BE > 75 (not >= 75, but strictly greater than 75)
            if ($score <= 75) {
                $issues[] = 'low_score';
                // error_log('SCORE-THRESHOLD-DEBUG: [Backend] → Issue: LOW SCORE (<= 75) - NOT optimized');
            } else {
                // error_log('SCORE-THRESHOLD-DEBUG: [Backend] → Score GOOD (> 75) - qualifies as optimized');
            }
        }
        
        // error_log('SCORE-THRESHOLD-DEBUG: [Backend] Total issues found: ' . count($issues));
        // error_log('SCORE-THRESHOLD-DEBUG: [Backend] Issues: ' . json_encode($issues));
        
        return $issues;
    }
    
    /**
     * Check if alt text is generic
     *
     * @param string $alt_text The alt text to check
     * @return bool Whether the alt text is generic
     */
    public function is_generic_alt($alt_text) {
        $alt_text_lower = strtolower(trim($alt_text));
        
        foreach ($this->generic_patterns as $pattern) {
            // Check if pattern is regex
            if (strpos($pattern, '/') === 0) {
                if (preg_match($pattern, $alt_text_lower)) {
                    return true;
                }
            }
            // Exact match
            elseif ($alt_text_lower === $pattern) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get image metadata
     *
     * @param int $attachment_id The attachment ID
     * @return array Image metadata
     */
    public function get_image_metadata($attachment_id) {
        $post = get_post($attachment_id);
        
        return array(
            'title' => $post->post_title,
            'description' => $post->post_content,
            'caption' => $post->post_excerpt,
            'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'filename' => basename(get_attached_file($attachment_id))
        );
    }
    
    /**
     * Classify issue type (primary issue)
     *
     * @param int $attachment_id The attachment ID
     * @param string $alt_text The alt text
     * @return string Issue classification
     */
    public function classify_issue($attachment_id, $alt_text) {
        if (empty($alt_text)) {
            return 'empty';
        }
        
        if ($this->is_generic_alt($alt_text)) {
            return 'generic';
        }
        
        $length = strlen($alt_text);
        
        if ($length < 10) {
            return 'too_short';
        }
        
        if ($length > 60) {
            return 'too_long';
        }
        
        return 'none';
    }
    
    /**
     * Get total image count
     *
     * @return int Total number of images
     */
    public function get_total_images() {
        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );
        
        $images = get_posts($args);
        return count($images);
    }
    
    /**
     * Get statistics
     *
     * @return array Statistics array
     */
    public function get_statistics() {
        $all_images = get_posts(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => -1
        ));
        
        $stats = array(
            'total' => count($all_images),
            'empty' => 0,
            'generic' => 0,
            'too_short' => 0,
            'too_long' => 0,
            'optimized' => 0
        );
        
        // Debug counters
        $debug_samples = array('empty' => array(), 'optimized' => array());
        
        foreach ($all_images as $image) {
            $issues = $this->detect_issues($image->ID);
            
            if (empty($issues)) {
                $stats['optimized']++;
                // Sample first 3 optimized images for debugging
                if (count($debug_samples['optimized']) < 3) {
                    $alt = get_post_meta($image->ID, '_wp_attachment_image_alt', true);
                    $debug_samples['optimized'][] = array('id' => $image->ID, 'alt' => $alt);
                }
            } else {
                foreach ($issues as $issue) {
                    if (isset($stats[$issue])) {
                        $stats[$issue]++;
                        // Sample first 3 empty images for debugging  
                        if ($issue === 'empty' && count($debug_samples['empty']) < 3) {
                            $alt = get_post_meta($image->ID, '_wp_attachment_image_alt', true);
                            $debug_samples['empty'][] = array('id' => $image->ID, 'alt' => $alt);
                        }
                    }
                }
            }
        }
        
        // Log for debugging
        error_log('IMAGE SEO STATS DEBUG:');
        error_log('Total: ' . $stats['total']);
        error_log('Empty: ' . $stats['empty']);
        error_log('Optimized: ' . $stats['optimized']);
        error_log('Empty samples: ' . print_r($debug_samples['empty'], true));
        error_log('Optimized samples: ' . print_r($debug_samples['optimized'], true));
        
        return $stats;
    }
}
