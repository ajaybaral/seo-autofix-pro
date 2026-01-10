<?php
/**
 * Link Crawler - Crawls website and extracts all links
 * 
 * @package SEOAutoFix\BrokenUrlManagement
 */

namespace SEOAutoFix\BrokenUrlManagement;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Link Crawler Class
 */
class Link_Crawler {
    
    /**
     * Maximum pages to crawl
     */
    const MAX_PAGES = 1000;
    
    /**
     * Batch size for processing
     */
    const BATCH_SIZE = 10;
    
    /**
     * Database manager
     */
    private $db_manager;
    
    /**
     * Link tester
     */
    private $link_tester;
    
    /**
     * URL similarity
     */
    private $url_similarity;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db_manager = new Database_Manager();
        $this->link_tester = new Link_Tester();
        $this->url_similarity = new URL_Similarity();
    }
    
    /**
     * Start a new scan
     * 
     * @return string Scan ID
     */
    public function start_scan() {
        error_log('[CRAWLER] start_scan() called');
        
        // Create new scan entry
        $scan_id = $this->db_manager->create_scan();
        error_log('[CRAWLER] Created scan with ID: ' . $scan_id);
        
        // Get all URLs to crawl and store in scan metadata
        $all_urls = $this->get_all_site_urls();
        error_log('[CRAWLER] Found ' . count($all_urls) . ' URLs to crawl');
        
        // Update scan with total URLs found
        $this->db_manager->update_scan($scan_id, array(
            'total_urls_found' => count($all_urls)
        ));
        
        // Store the URLs to process in a transient for this scan
        set_transient('seoautofix_scan_urls_' . $scan_id, $all_urls, DAY_IN_SECONDS);
        set_transient('seoautofix_scan_progress_' . $scan_id, 0, DAY_IN_SECONDS);
        
        error_log('[CRAWLER] Scan initialized, ready for batch processing');
        
        return $scan_id;
    }
    
    /**
     * Process a batch of URLs for a scan
     * 
     * @param string $scan_id Scan ID
     * @param int $batch_size Number of pages to process
     * @return array Progress info
     */
    public function process_batch($scan_id, $batch_size = 5) {
        error_log('[CRAWLER] process_batch() called for scan: ' . $scan_id . ', batch_size: ' . $batch_size);
        
        // Get URLs to process
        $all_urls = get_transient('seoautofix_scan_urls_' . $scan_id);
        if ($all_urls === false) {
            error_log('[CRAWLER] No URLs found in transient, scan may have expired');
            return array(
                'completed' => true,
                'error' => 'Scan data expired'
            );
        }
        
        // Get current progress
        $progress_index = get_transient('seoautofix_scan_progress_' . $scan_id);
        if ($progress_index === false) {
            $progress_index = 0;
        }
        
        error_log('[CRAWLER] Current progress: ' . $progress_index . '/' . count($all_urls));
        
        // Get the batch of URLs to process
        $batch_urls = array_slice($all_urls, $progress_index, $batch_size);
        
        if (empty($batch_urls)) {
            error_log('[CRAWLER] No more URLs to process, marking scan as completed');
            
            // Mark scan as complete
            $this->db_manager->update_scan($scan_id, array(
                'status' => 'completed',
                'completed_at' => current_time('mysql')
            ));
            
            // Clean up transients
            delete_transient('seoautofix_scan_urls_' . $scan_id);
            delete_transient('seoautofix_scan_progress_' . $scan_id);
            delete_transient('seoautofix_scan_links_' . $scan_id);
            
            return array(
                'completed' => true,
                'progress' => 100,
                'pages_processed' => count($all_urls)
            );
        }
        
        // Get existing links data
        $all_links = get_transient('seoautofix_scan_links_' . $scan_id);
        if ($all_links === false) {
            $all_links = array();
        }
        
        // Extract links from this batch of pages
        foreach ($batch_urls as $page_url) {
            error_log('[CRAWLER] Extracting links from: ' . $page_url);
            
            $links = $this->extract_links_from_page($page_url);
            error_log('[CRAWLER] Found ' . count($links) . ' links on this page');
            
            foreach ($links as $link) {
                if (!isset($all_links[$link])) {
                    $all_links[$link] = array();
                }
                $all_links[$link][] = $page_url;
            }
        }
        
        // Update progress
        $new_progress = $progress_index + count($batch_urls);
        set_transient('seoautofix_scan_progress_' . $scan_id, $new_progress, DAY_IN_SECONDS);
        set_transient('seoautofix_scan_links_' . $scan_id, $all_links, DAY_IN_SECONDS);
        
        // Now test the links found so far (only new ones)
        $this->test_links_batch($scan_id, $all_links);
        
        $progress_percent = round(($new_progress / count($all_urls)) * 100, 2);
        
        error_log('[CRAWLER] Batch completed. Progress: ' . $new_progress . '/' . count($all_urls) . ' (' . $progress_percent . '%)');
        
        return array(
            'completed' => false,
            'progress' => $progress_percent,
            'pages_processed' => $new_progress,
            'total_pages' => count($all_urls),
            'links_found' => count($all_links)
        );
    }
    
    /**
     * Test links and store broken ones
     * 
     * @param string $scan_id Scan ID
     * @param array $all_links All links found with their locations
     */
    private function test_links_batch($scan_id, $all_links) {
        // Get already tested links
        $tested_links = get_transient('seoautofix_scan_tested_' . $scan_id);
        if ($tested_links === false) {
            $tested_links = array();
        }
        
        
        $links_to_test = array();
        foreach ($all_links as $link => $locations) {
            if (!in_array($link, $tested_links)) {
                $links_to_test[$link] = $locations;
            }
        }
        
        if (empty($links_to_test)) {
            return;
        }
        
        // CRITICAL: Limit to 30 links per batch to prevent timeout
        $max_links_per_batch = 30;
        if (count($links_to_test) > $max_links_per_batch) {
            $links_to_test = array_slice($links_to_test, 0, $max_links_per_batch, true);
            error_log('[CRAWLER] Limited to ' . $max_links_per_batch . ' links per batch (from ' . count($all_links) . ' total)');
        }
        
        error_log('[CRAWLER] Testing ' . count($links_to_test) . ' new links');
        
        // Get valid internal URLs for similarity matching
        $valid_internal_urls = $this->get_all_site_urls();
        
        $tested_count = 0;
        $broken_count = 0;
        
        foreach ($links_to_test as $link => $found_on_pages) {
            // Mark as tested
            $tested_links[] = $link;
            
            // Test the link
            $test_result = $this->link_tester->test_url($link);
            $tested_count++;
            
            // If broken, add to results
            if ($test_result['is_broken']) {
                $broken_count++;
                error_log('[CRAWLER] Link is broken: ' . $link);
                
                $is_internal = $this->url_similarity->is_internal_url($link);
                $link_type = $is_internal ? 'internal' : 'external';
                
                $suggested_url = null;
                $reason = '';
                
                if ($is_internal) {
                    $match = $this->url_similarity->find_closest_match($link, $valid_internal_urls);
                    $suggested_url = $match['url'];
                    $reason = $match['reason'];
                } else {
                    $reason = __('This link is not working, either delete it or provide a new link', 'seo-autofix-pro');
                }
                
                // Add entry for each page where link was found
                foreach ($found_on_pages as $found_on_url) {
                    $this->db_manager->add_broken_link($scan_id, array(
                        'found_on_url' => $found_on_url,
                        'broken_url' => $link,
                        'link_type' => $link_type,
                        'status_code' => $test_result['status_code'],
                        'suggested_url' => $suggested_url,
                        'reason' => $reason
                    ));
                }
            }
            
            // Small delay
            usleep(50000); // 0.05 seconds (reduced from 0.1)
        }
        
        // Update scan stats
        $current_stats = $this->db_manager->get_scan_progress($scan_id);
        $this->db_manager->update_scan($scan_id, array(
            'total_urls_tested' => $current_stats['tested_urls'] + $tested_count,
            'total_broken_links' => $current_stats['broken_count'] + $broken_count
        ));
        
        // Save tested links
        set_transient('seoautofix_scan_tested_' . $scan_id, $tested_links, DAY_IN_SECONDS);
        
        error_log('[CRAWLER] Testing batch complete. Tested: ' . $tested_count . ', Broken: ' . $broken_count);
    }
    
    
    /**
     * Get all site URLs (posts, pages, custom post types)
     * 
     * @return array URLs
     */
    private function get_all_site_urls() {
        $urls = array();
        
        // Add homepage
        $urls[] = home_url('/');
        
        // Get all published posts and pages
        $args = array(
            'post_type' => array('post', 'page'),
            'post_status' => 'publish',
            'posts_per_page' => self::MAX_PAGES,
            'orderby' => 'date',
            'order' => 'DESC'
        );
        
        $query = new \WP_Query($args);
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $urls[] = get_permalink();
            }
            wp_reset_postdata();
        }
        
        return array_unique($urls);
    }
    
    /**
     * Extract all links from a page
     * 
     * @param string $url Page URL
     * @return array Links found on page
     */
    private function extract_links_from_page($url) {
        $links = array();
        
        // Get page content
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'sslverify' => false
        ));
        
        if (is_wp_error($response)) {
            return $links;
        }
        
        $html = wp_remote_retrieve_body($response);
        
        // Use DOMDocument to parse HTML
        if (empty($html)) {
            return $links;
        }
        
        // Suppress warnings from malformed HTML
        libxml_use_internal_errors(true);
        
        $dom = new \DOMDocument();
        $dom->loadHTML($html);
        
        libxml_clear_errors();
        
        // Extract all anchor tags
        $anchors = $dom->getElementsByTagName('a');
        
        foreach ($anchors as $anchor) {
            $href = $anchor->getAttribute('href');
            
            if (empty($href) || $href === '#') {
                continue;
            }
            
            // Convert relative URLs to absolute
            if (strpos($href, '/') === 0 && strpos($href, '//') !== 0) {
                $href = home_url($href);
            }
            
            // Skip mailto, tel, javascript, etc.
            if (preg_match('/^(mailto|tel|javascript|#):/i', $href)) {
                continue;
            }
            
            $links[] = $href;
        }
        
        return array_unique($links);
    }
}
