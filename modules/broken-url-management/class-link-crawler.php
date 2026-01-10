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
        
        // Start crawling in background
        error_log('[CRAWLER] Starting crawl_and_test()');
        $this->crawl_and_test($scan_id);
        
        return $scan_id;
    }
    
    /**
     * Crawl site and test links
     * 
     * @param string $scan_id Scan ID
     */
    private function crawl_and_test($scan_id) {
        error_log('[CRAWLER] crawl_and_test() started for scan: ' . $scan_id);
        
        // Get all published posts and pages
        error_log('[CRAWLER] Getting all site URLs');
        $all_urls = $this->get_all_site_urls();
        error_log('[CRAWLER] Found ' . count($all_urls) . ' URLs to crawl');
        
        // Update scan with total URLs found
        $this->db_manager->update_scan($scan_id, array(
            'total_urls_found' => count($all_urls)
        ));
        error_log('[CRAWLER] Updated scan with total URLs found');
        
        // Extract all links from all pages
        $all_links = array();
        
        error_log('[CRAWLER] Starting to extract links from pages');
        foreach ($all_urls as $index => $page_url) {
            error_log('[CRAWLER] Extracting links from page ' . ($index + 1) . '/' . count($all_urls) . ': ' . $page_url);
            
            $links = $this->extract_links_from_page($page_url);
            error_log('[CRAWLER] Found ' . count($links) . ' links on this page');
            
            foreach ($links as $link) {
                if (!isset($all_links[$link])) {
                    $all_links[$link] = array();
                }
                $all_links[$link][] = $page_url; // Track where link was found
            }
        }
        
        error_log('[CRAWLER] Total unique links found: ' . count($all_links));
        
        // Get all valid internal URLs for similarity matching
        $valid_internal_urls = $this->get_all_site_urls();
        error_log('[CRAWLER] Got ' . count($valid_internal_urls) . ' valid internal URLs for matching');
        
        // Test each unique link
        $tested_count = 0;
        $broken_count = 0;
        
        error_log('[CRAWLER] Starting to test links');
        foreach ($all_links as $link => $found_on_pages) {
            error_log('[CRAWLER] Testing link ' . ($tested_count + 1) . '/' . count($all_links) . ': ' . $link);
            
            // Test the link
            $test_result = $this->link_tester->test_url($link);
            $tested_count++;
            
            error_log('[CRAWLER] Test result - Status: ' . $test_result['status_code'] . ', Broken: ' . ($test_result['is_broken'] ? 'yes' : 'no'));
            
            // If broken, add to results with suggestion
            if ($test_result['is_broken']) {
                $broken_count++;
                error_log('[CRAWLER] Link is broken! Total broken so far: ' . $broken_count);
                
                $is_internal = $this->url_similarity->is_internal_url($link);
                $link_type = $is_internal ? 'internal' : 'external';
                
                // Get suggestion for internal links
                $suggested_url = null;
                $reason = '';
                
                if ($is_internal) {
                    error_log('[CRAWLER] Finding suggestion for internal broken link');
                    $match = $this->url_similarity->find_closest_match($link, $valid_internal_urls);
                    $suggested_url = $match['url'];
                    $reason = $match['reason'];
                    error_log('[CRAWLER] Suggested: ' . $suggested_url . ' - Reason: ' . $reason);
                } else {
                    $reason = __('This link is not working, either delete it or provide a new link', 'seo-autofix-pro');
                    error_log('[CRAWLER] External link - no suggestion');
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
                error_log('[CRAWLER] Added broken link to database');
            }
            
            // Update progress
            $this->db_manager->update_scan($scan_id, array(
                'total_urls_tested' => $tested_count,
                'total_broken_links' => $broken_count
            ));
            
            // Small delay to avoid overwhelming server
            usleep(200000); // 0.2 seconds
        }
        
        error_log('[CRAWLER] Link testing complete. Tested: ' . $tested_count . ', Broken: ' . $broken_count);
        
        // Mark scan as complete
        $this->db_manager->update_scan($scan_id, array(
            'status' => 'completed',
            'completed_at' => current_time('mysql')
        ));
        error_log('[CRAWLER] Scan marked as completed');
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
