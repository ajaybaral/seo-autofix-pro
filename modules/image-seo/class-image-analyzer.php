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
     * @return array Array of images with issues
     */
    public function scan_all_images($batch_size = 50, $offset = 0, $usage_tracker = null) {
        global $wpdb;
        $history_table = $wpdb->prefix . 'seoautofix_image_history';
        
        
        // Query images with issues from history table (status = 'blank')
        $results_data = $wpdb->get_results($wpdb->prepare(
            "SELECT attachment_id, issue_type 
             FROM {$history_table} 
             WHERE status = 'blank' 
             ORDER BY attachment_id DESC 
             LIMIT %d OFFSET %d",
            $batch_size,
            $offset
        ));
        
        $results = array();
        
        foreach ($results_data as $row) {
            $attachment_id = $row->attachment_id;
            $metadata = $this->get_image_metadata($attachment_id);
            $issues = $this->detect_issues($attachment_id);
            
            // Get usage data if tracker is provided
            $usage_data = array('used_in_posts' => 0, 'used_in_pages' => 0);
            if ($usage_tracker) {
                $usage = $usage_tracker->get_image_usage($attachment_id);
                $usage_data = array(
                    'used_in_posts' => $usage['used_in_posts'] ?? 0,
                    'used_in_pages' => $usage['used_in_pages'] ?? 0
                );
            }
            
            $results[] = array(
                'id' => $attachment_id,
                'thumbnail' => wp_get_attachment_image_url($attachment_id, 'thumbnail'),
                'title' => get_the_title($attachment_id),
                'filename' => basename(get_attached_file($attachment_id)),
                'current_alt' => $metadata['alt'],
                'issues' => $issues,
                'issue_type' => $row->issue_type,
                'used_in_posts' => $usage_data['used_in_posts'],
                'used_in_pages' => $usage_data['used_in_pages']
            );
        }
        
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
        
        // Check for empty alt text
        if (empty($alt_text)) {
            $issues[] = 'empty';
        }
        // Check for generic alt text
        elseif ($this->is_generic_alt($alt_text)) {
            $issues[] = 'generic';
        }
        // Check length
        else {
            $length = strlen($alt_text);
            
            if ($length < 10) {
                $issues[] = 'too_short';
            } elseif ($length > 60) {
                $issues[] = 'too_long';
            }
        }
        
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
