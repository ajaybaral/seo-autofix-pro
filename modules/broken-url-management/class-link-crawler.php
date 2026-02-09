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
class Link_Crawler
{

    /**
     * Maximum pages to crawl
     */
    const MAX_PAGES = 1000;

    /**
     * Batch size for processing
     */
    const BATCH_SIZE = 10;

    /**
     * Parallel URL testing limit (concurrent requests)
     * Higher = faster but more server load
     * Recommended: 20-50 for VPS, 10-20 for shared hosting, 50-100 for local/dedicated
     */
    const PARALLEL_LIMIT = 100;

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
    public function __construct()
    {
        $this->db_manager = new Database_Manager();
        $this->link_tester = new Link_Tester();
        $this->url_similarity = new URL_Similarity();
    }

    /**
     * Start a new scan
     * 
     * @return string Scan ID
     */
    public function start_scan()
    {
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
    public function process_batch($scan_id, $batch_size = 5)
    {
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

            // Get final stats
            $stats = $this->db_manager->get_scan_progress($scan_id);

            return array(
                'completed' => true,
                'progress' => 100,
                'pages_processed' => count($all_urls),
                'total_pages' => count($all_urls),
                'tested_urls' => $stats['tested_urls'],
                'total_urls' => $stats['total_urls'],
                'broken_count' => $stats['broken_count']
            );
        }

        // Get existing links data
        $all_links = get_transient('seoautofix_scan_links_' . $scan_id);
        if ($all_links === false) {
            $all_links = array();
        }


        // Extract links from this batch of pages
        foreach ($batch_urls as $page_data) {
            // Extract page data
            $page_url = is_array($page_data) ? $page_data['url'] : $page_data;
            $page_id = is_array($page_data) && isset($page_data['page_id']) ? $page_data['page_id'] : 0;
            $page_title = is_array($page_data) && isset($page_data['page_title']) ? $page_data['page_title'] : '';

            error_log('[CRAWLER] Extracting links from: ' . $page_url . ' (Title: ' . $page_title . ')');

            // Use v2 method to get links with metadata
            $links = $this->extract_links_from_page_v2($page_url, $page_id, $page_title);
            error_log('[CRAWLER] Found ' . count($links) . ' links on this page');

            foreach ($links as $link_data) {
                $link_url = $link_data['url'];
                if (!isset($all_links[$link_url])) {
                    $all_links[$link_url] = array();
                }
                // Store the full link data including page title
                $all_links[$link_url][] = array(
                    'found_on_url' => $page_url,
                    'found_on_page_id' => $page_id,
                    'found_on_page_title' => $page_title,
                    'anchor_text' => isset($link_data['anchor_text']) ? $link_data['anchor_text'] : '',
                    'location' => isset($link_data['location']) ? $link_data['location'] : 'content'
                );
            }
        }

        // Update progress
        $new_progress = $progress_index + count($batch_urls);
        set_transient('seoautofix_scan_progress_' . $scan_id, $new_progress, DAY_IN_SECONDS);
        set_transient('seoautofix_scan_links_' . $scan_id, $all_links, DAY_IN_SECONDS);

        // Update scan with total unique links found (update every batch as links accumulate)
        $this->db_manager->update_scan($scan_id, array(
            'total_urls_found' => count($all_links)
        ));

        // Now test the links found so far (only new ones)
        $this->test_links_batch($scan_id, $all_links);

        $progress_percent = round(($new_progress / count($all_urls)) * 100, 2);

        error_log('[CRAWLER] Batch completed. Progress: ' . $new_progress . '/' . count($all_urls) . ' (' . $progress_percent . '%)');

        // Get current broken links and stats for real-time frontend updates
        $broken_links = $this->get_broken_links_for_scan($scan_id);
        $stats = $this->get_scan_stats($scan_id);

        error_log('[CRAWLER] 🔵 Returning batch response with ' . count($broken_links) . ' broken links');
        error_log('[CRAWLER] Stats: total=' . $stats['total'] . ', 4xx=' . $stats['4xx'] . ', 5xx=' . $stats['5xx']);

        return array(
            'completed' => false,
            'progress' => $progress_percent,
            'pages_processed' => $new_progress,
            'total_pages' => count($all_urls),
            'links_found' => count($all_links),
            'broken_links' => $broken_links,
            'stats' => $stats
        );
    }

    /**
     * Get broken links for a scan
     * Used for real-time updates during scanning
     * 
     * @param string $scan_id Scan ID
     * @return array Broken links
     */
    private function get_broken_links_for_scan($scan_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'seoautofix_broken_links_scan_results';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE scan_id = %s ORDER BY id DESC LIMIT 100",
            $scan_id
        ), ARRAY_A);
    }

    /**
     * Get scan statistics  
     * Used for real-time stats updates
     * 
     * @param string $scan_id Scan ID
     * @return array Stats
     */
    private function get_scan_stats($scan_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'seoautofix_broken_links_scan_results';

        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE scan_id = %s",
            $scan_id
        ));

        $count_4xx = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE scan_id = %s AND status_code >= 400 AND status_code < 500",
            $scan_id
        ));

        $count_5xx = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE scan_id = %s AND status_code >= 500 AND status_code < 600",
            $scan_id
        ));

        $internal = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE scan_id = %s AND link_type = 'internal'",
            $scan_id
        ));

        $external = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE scan_id = %s AND link_type = 'external'",
            $scan_id
        ));

        return array(
            'total' => intval($total),
            '4xx' => intval($count_4xx),
            '5xx' => intval($count_5xx),
            'internal' => intval($internal),
            'external' => intval($external)
        );
    }

    /**
     * Test links and store broken ones
     * 
     * @param string $scan_id Scan ID
     * @param array $all_links All links found with their locations
     */
    private function test_links_batch($scan_id, $all_links)
    {
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
        $valid_internal_urls_data = $this->get_all_site_urls();
        // Extract just the URL strings from the array of arrays
        $valid_internal_urls = array_column($valid_internal_urls_data, 'url');

        // Remove any URLs that are in our broken links list for this scan
        $broken_urls_in_scan = $this->db_manager->get_broken_urls_for_scan($scan_id);
        if (!empty($broken_urls_in_scan)) {
            $valid_internal_urls = array_diff($valid_internal_urls, $broken_urls_in_scan);
            error_log('[CRAWLER] Filtered out ' . count($broken_urls_in_scan) . ' broken URLs from suggestions');
        }

        $tested_count = 0;
        $broken_count = 0;

        // ✅ SEPARATE INTERNAL AND EXTERNAL URLs FOR OPTIMAL TESTING
        $internal_links = array();
        $external_links = array();
        $template_links = array();

        foreach ($links_to_test as $link => $found_on_pages) {
            // Mark as tested
            $tested_links[] = $link;

            // Skip template-generated links (theme/plugin auto-generated)
            if ($this->is_template_generated_link($link)) {
                error_log('[CRAWLER] Skipping template-generated link: ' . $link);
                $template_links[] = $link;
                $tested_count++;
                continue;
            }

            // Categorize as internal or external
            if ($this->url_similarity->is_internal_url($link)) {
                $internal_links[$link] = $found_on_pages;
            } else {
                $external_links[$link] = $found_on_pages;
            }
        }

        error_log('[CRAWLER] 📊 URL categorization: Internal=' . count($internal_links) . ', External=' . count($external_links) . ', Skipped=' . count($template_links));

        // ✅ PROCESS INTERNAL URLs (FAST - WordPress functions)
        if (!empty($internal_links)) {
            error_log('[CRAWLER] ⚡ Testing ' . count($internal_links) . ' internal URLs (fast WordPress functions)...');

            foreach ($internal_links as $link => $found_on_pages) {
                $test_result = $this->link_tester->test_url($link);
                $tested_count++;

                // Process if broken
                if ($test_result['is_broken']) {
                    $this->process_broken_link($scan_id, $link, $found_on_pages, $test_result, $valid_internal_urls);
                    $broken_count++;
                }
            }

            error_log('[CRAWLER] ✅ Internal URLs testing complete');
        }

        // ✅ PROCESS EXTERNAL URLs (PARALLEL - cURL multi-handle)
        if (!empty($external_links)) {
            error_log('[CRAWLER] 🚀 Testing ' . count($external_links) . ' external URLs (parallel testing)...');

            $external_urls_list = array_keys($external_links);
            $start_time = microtime(true);
            $parallel_results = $this->link_tester->test_urls_parallel($external_urls_list, self::PARALLEL_LIMIT);
            $duration = round(microtime(true) - $start_time, 2);

            error_log('[CRAWLER] ✅ Parallel testing complete (' . $duration . ' seconds for ' . count($external_urls_list) . ' URLs)');

            foreach ($parallel_results as $link => $test_result) {
                $tested_count++;

                // Process if broken
                if ($test_result['is_broken']) {
                    $found_on_pages = $external_links[$link];
                    $this->process_broken_link($scan_id, $link, $found_on_pages, $test_result, $valid_internal_urls);
                    $broken_count++;
                }
            }
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
    private function get_all_site_urls()
    {
        $urls = array();

        // Add homepage
        $urls[] = array(
            'url' => home_url('/'),
            'page_id' => 0,
            'page_title' => get_bloginfo('name') . ' - Home'
        );

        // Get all published posts and pages
        $args = array(
            'post_type' => array('post', 'page'),
            'post_status' => 'publish',
            'posts_per_page' => self::MAX_PAGES,
            'orderby' => 'date',
            'order' => 'DESC'
        );

        $query = new \WP_Query($args);

        error_log('[CRAWLER] WP_Query found ' . $query->found_posts . ' total posts/pages');
        error_log('[CRAWLER] Post types queried: ' . implode(', ', $args['post_type']));

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $urls[] = array(
                    'url' => get_permalink(),
                    'page_id' => get_the_ID(),
                    'page_title' => get_the_title()
                );
                error_log('[CRAWLER] Added URL: ' . get_the_title() . ' (' . get_permalink() . ')');
            }
            wp_reset_postdata();
        }

        error_log('[CRAWLER] Total URLs to crawl (including homepage): ' . count($urls));

        return $urls;
    }

    /**
     * Extract all links from a page
     * 
     * @param string $url Page URL
     * @return array Links found on page
     */
    private function extract_links_from_page($url)
    {
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

    /**
     * Extract all links from a page with location and context (ENHANCED VERSION)
     * 
     * @param string $url Page URL
     * @param int $page_id Page ID
     * @param string $page_title Page title
     * @return array Links found on page with metadata
     */
    private function extract_links_from_page_v2($url, $page_id = 0, $page_title = '')
    {
        $links = array();

        // FIRST: Check if this is an Elementor page and extract links from database
        if ($page_id > 0) {
            $elementor_links = $this->extract_links_from_elementor_data($page_id, $page_title, $url);
            if (!empty($elementor_links)) {
                error_log('[CRAWLER] 🎨 Found ' . count($elementor_links) . ' links in Elementor data for page: ' . $page_title);
                $links = array_merge($links, $elementor_links);
            }
        }

        // THEN: Continue with existing HTML scanning logic
        // Get page content
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'sslverify' => false
        ));

        if (is_wp_error($response)) {
            return $links;
        }

        $html = wp_remote_retrieve_body($response);

        if (empty($html)) {
            return $links;
        }

        // Suppress warnings from malformed HTML
        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);

        libxml_clear_errors();

        // Detect page sections for location tracking
        $header_html = $this->extract_section($html, 'header');
        $footer_html = $this->extract_section($html, 'footer');
        $sidebar_html = $this->extract_section($html, 'sidebar');

        // Extract anchor links
        $anchors = $dom->getElementsByTagName('a');
        foreach ($anchors as $anchor) {
            $href = $anchor->getAttribute('href');

            // Skip empty links and exact hash only
            if (empty($href) || $href === '#') {
                continue;
            }

            // Skip mailto, tel, javascript, etc.
            if (preg_match('/^(mailto|tel|javascript):/i', $href)) {
                continue;
            }

            //Handle anchor links - resolve relative to CURRENT PAGE being scanned, not home_url()
            if (strpos($href, '#') === 0) {
                // Anchor link like #content - append to the current page URL
                $href = $url . $href;
            }
            // Convert relative URLs to absolute
            elseif (strpos($href, '/') === 0 && strpos($href, '//') !== 0) {
                // Get only the scheme and host (without subdirectory path)
                $parsed_home = parse_url(home_url());
                $base = $parsed_home['scheme'] . '://' . $parsed_home['host'];
                if (isset($parsed_home['port'])) {
                    $base .= ':' . $parsed_home['port'];
                }
                $href = $base . $href;
            }

            // Extract anchor text
            $anchor_text = trim($anchor->textContent);
            if (empty($anchor_text)) {
                $anchor_text = '[No text]';
            }

            // Detect location
            $location = $this->detect_link_location($html, $href, $header_html, $footer_html, $sidebar_html);

            // Get context (surrounding text)
            $context = $this->get_link_context($anchor);

            $links[] = array(
                'url' => $href,
                'found_on_page_id' => $page_id,
                'found_on_page_title' => $page_title,
                'found_on_url' => $url,
                'location' => $location,
                'anchor_text' => substr($anchor_text, 0, 255), // Limit length
                'context' => substr($context, 0, 500)
            );
        }

        // Extract image links
        $images = $dom->getElementsByTagName('img');
        foreach ($images as $image) {
            $src = $image->getAttribute('src');

            if (empty($src)) {
                continue;
            }

            // Convert relative URLs to absolute
            if (strpos($src, '/') === 0 && strpos($src, '//') !== 0) {
                // Get only the scheme and host (without subdirectory path)
                $parsed_home = parse_url(home_url());
                $base = $parsed_home['scheme'] . '://' . $parsed_home['host'];
                if (isset($parsed_home['port'])) {
                    $base .= ':' . $parsed_home['port'];
                }
                $src = $base . $src;
            }

            // Skip data URIs
            if (strpos($src, 'data:') === 0) {
                continue;
            }

            // Get alt text
            $alt_text = $image->getAttribute('alt');
            if (empty($alt_text)) {
                $alt_text = '[Image]';
            }

            $links[] = array(
                'url' => $src,
                'found_on_page_id' => $page_id,
                'found_on_page_title' => $page_title,
                'found_on_url' => $url,
                'location' => 'image',
                'anchor_text' => 'Image: ' . substr($alt_text, 0, 245),
                'context' => ''
            );
        }

        return $links;
    }

    /**
     * Extract links from Elementor page data
     * 
     * @param int $page_id Page ID
     * @param string $page_title Page title
     * @param string $page_url Page URL
     * @return array Links found in Elementor data
     */
    private function extract_links_from_elementor_data($page_id, $page_title, $page_url)
    {
        // Check if page uses Elementor
        $is_elementor = get_post_meta($page_id, '_elementor_edit_mode', true) === 'builder';
        
        if (!$is_elementor) {
            return array();
        }
        
        error_log('[CRAWLER] 🎨 Page ID ' . $page_id . ' (' . $page_title . ') is an Elementor page - extracting links from _elementor_data');
        
        // Get Elementor data
        $elementor_data = get_post_meta($page_id, '_elementor_data', true);
        
        if (empty($elementor_data)) {
            error_log('[CRAWLER] ⚠️ No _elementor_data found for page ID ' . $page_id);
            return array();
        }
        
        // Parse JSON
        $data = json_decode($elementor_data, true);
        
        if (!is_array($data)) {
            error_log('[CRAWLER] ⚠️ Failed to decode _elementor_data JSON for page ID ' . $page_id);
            return array();
        }
        
        $links = array();
        
        // Recursively search for URLs in Elementor data
        $this->search_elementor_data_for_links($data, $page_id, $page_title, $page_url, $links);
        
        error_log('[CRAWLER] 🎨 Extracted ' . count($links) . ' links from Elementor data');
        
        return $links;
    }

    /**
     * Recursively search Elementor data structure for links
     * 
     * @param array $data Elementor data array
     * @param int $page_id Page ID
     * @param string $page_title Page title
     * @param string $page_url Page URL
     * @param array &$links Links array (passed by reference)
     */
    private function search_elementor_data_for_links($data, $page_id, $page_title, $page_url, &$links)
    {
        if (!is_array($data)) {
            return;
        }
        
        foreach ($data as $key => $value) {
            // Check for URL fields in common Elementor widgets
            if ($key === 'url' && is_string($value) && !empty($value) && $value !== '#') {
                // Skip mailto, tel, javascript, etc.
                if (!preg_match('/^(mailto|tel|javascript):/i', $value)) {
                    $links[] = array(
                        'url' => $value,
                        'found_on_page_id' => $page_id,
                        'found_on_page_title' => $page_title,
                        'found_on_url' => $page_url,
                        'location' => 'elementor_data',
                        'anchor_text' => $this->get_elementor_link_text($data),
                        'context' => 'Elementor widget'
                    );
                }
            }
            // Check for link fields (Elementor button/link widgets)
            elseif ($key === 'link' && is_array($value) && isset($value['url'])) {
                $link_url = $value['url'];
                if (!empty($link_url) && $link_url !== '#' && !preg_match('/^(mailto|tel|javascript):/i', $link_url)) {
                    $links[] = array(
                        'url' => $link_url,
                        'found_on_page_id' => $page_id,
                        'found_on_page_title' => $page_title,
                        'found_on_url' => $page_url,
                        'location' => 'elementor_data',
                        'anchor_text' => $this->get_elementor_link_text($data),
                        'context' => 'Elementor link widget'
                    );
                }
            }
            // Check for image URLs (background images, widget images)
            elseif (($key === 'background_image' || $key === 'image') && is_array($value) && isset($value['url'])) {
                $img_url = $value['url'];
                if (!empty($img_url) && strpos($img_url, 'data:') !== 0) {
                    $links[] = array(
                        'url' => $img_url,
                        'found_on_page_id' => $page_id,
                        'found_on_page_title' => $page_title,
                        'found_on_url' => $page_url,
                        'location' => 'elementor_image',
                        'anchor_text' => 'Image in Elementor',
                        'context' => 'Elementor ' . $key
                    );
                }
            }
            // Recurse into nested arrays
            elseif (is_array($value)) {
                $this->search_elementor_data_for_links($value, $page_id, $page_title, $page_url, $links);
            }
        }
    }

    /**
     * Get link text from Elementor widget data
     * 
     * @param array $widget_data Widget data
     * @return string Link text
     */
    private function get_elementor_link_text($widget_data)
    {
        // Try to find text field
        if (isset($widget_data['text'])) {
            return substr(strip_tags($widget_data['text']), 0, 255);
        }
        if (isset($widget_data['title'])) {
            return substr(strip_tags($widget_data['title']), 0, 255);
        }
        if (isset($widget_data['button_text'])) {
            return substr(strip_tags($widget_data['button_text']), 0, 255);
        }
        if (isset($widget_data['heading_title'])) {
            return substr(strip_tags($widget_data['heading_title']), 0, 255);
        }
        return '[Elementor Link]';
    }

    /**
     * Extract HTML section (header, footer, sidebar)
     * 
     * @param string $html Full HTML
     * @param string $section Section name
     * @return string Section HTML
     */
    private function extract_section($html, $section)
    {
        $pattern = '/<' . $section . '[^>]*>(.*?)<\/' . $section . '>/is';
        if (preg_match($pattern, $html, $matches)) {
            return $matches[1];
        }

        // Try common class names
        $class_patterns = array(
            'header' => '/<[^>]+class=["\'][^"\']*header[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is',
            'footer' => '/<[^>]+class=["\'][^"\']*footer[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is',
            'sidebar' => '/<[^>]+class=["\'][^"\']*sidebar[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/is'
        );

        if (isset($class_patterns[$section]) && preg_match($class_patterns[$section], $html, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Detect link location within page
     * 
     * @param string $html Full HTML
     * @param string $link_url Link URL
     * @param string $header_html Header HTML
     * @param string $footer_html Footer HTML
     * @param string $sidebar_html Sidebar HTML
     * @return string Location (header/footer/content/sidebar)
     */
    private function detect_link_location($html, $link_url, $header_html, $footer_html, $sidebar_html)
    {
        // Escape special regex characters in URL
        $escaped_url = preg_quote($link_url, '/');

        // Check header
        if (!empty($header_html) && preg_match('/' . $escaped_url . '/i', $header_html)) {
            return 'header';
        }

        // Check footer
        if (!empty($footer_html) && preg_match('/' . $escaped_url . '/i', $footer_html)) {
            return 'footer';
        }

        // Check sidebar
        if (!empty($sidebar_html) && preg_match('/' . $escaped_url . '/i', $sidebar_html)) {
            return 'sidebar';
        }

        // Default to content
        return 'content';
    }

    /**
     * Get context around a link (surrounding text)
     * 
     * @param \DOMElement $element Link element
     * @return string Context text
     */
    private function get_link_context($element)
    {
        $context = '';

        // Get parent element text
        if ($element->parentNode) {
            $parent_text = trim($element->parentNode->textContent);
            // Limit to 500 characters around the link
            if (strlen($parent_text) > 500) {
                $context = substr($parent_text, 0, 500) . '...';
            } else {
                $context = $parent_text;
            }
        }

        return $context;
    }

    /**
     * Check if a link is template-generated and should be ignored
     * 
     * @param string $url The URL to check
     * @return bool True if template-generated
     */
    private function is_template_generated_link($url)
    {
        // Common patterns for template-generated links that should be ignored
        $template_patterns = array(
            '#respond',              // Comment reply links
            '#comment-',             // Comment anchors
            '/wp-admin/',           // Admin links
            '/wp-login.php',        // Login links
            '/wp-comments-post.php', // Comment form actions
            '/feed/',               // Feed links
            '?replytocom=',         // Reply to comment parameter
            '/wp-content/themes/',  // Theme file links (usually not in content)
            '/wp-content/plugins/', // Plugin file links (usually not in content)
            '/wp-includes/',        // WordPress core files
        );

        foreach ($template_patterns as $pattern) {
            if (strpos($url, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get content type of a URL based on file extension
     * 
     * @param string $url The URL to analyze
     * @return string Content type: 'image', 'document', 'video', or 'page'
     */
    private function get_url_content_type($url)
    {
        // Parse the URL to get the path
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return 'page';
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Image extensions
        $image_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico');
        if (in_array($extension, $image_extensions)) {
            return 'image';
        }

        // Document extensions
        $doc_extensions = array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv');
        if (in_array($extension, $doc_extensions)) {
            return 'document';
        }

        // Video extensions
        $video_extensions = array('mp4', 'webm', 'ogg', 'avi', 'mov', 'wmv', 'flv');
        if (in_array($extension, $video_extensions)) {
            return 'video';
        }

        // Audio extensions
        $audio_extensions = array('mp3', 'wav', 'ogg', 'aac', 'm4a');
        if (in_array($extension, $audio_extensions)) {
            return 'audio';
        }

        // Default to page for URLs without extension or unknown extensions
        return 'page';
    }

    /**
     * Get all media URLs from WordPress media library
     * 
     * @param string $content_type Content type to filter: 'image', 'document', 'video', 'audio'
     * @return array Array of media URLs
     */
    private function get_all_media_urls($content_type = 'image')
    {
        $media_urls = array();

        // Get all attachments of specified type
        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'post_mime_type' => $this->get_mime_type_filter($content_type)
        );

        $attachments = get_posts($args);
        error_log('[CRAWLER] Found ' . count($attachments) . ' attachments of type: ' . $content_type);

        foreach ($attachments as $attachment) {
            $url = wp_get_attachment_url($attachment->ID);
            if ($url) {
                $media_urls[] = $url;

                // Also get image sizes if it's an image
                if ($content_type === 'image') {
                    $sizes = array('thumbnail', 'medium', 'medium_large', 'large', 'full');
                    foreach ($sizes as $size) {
                        $size_url = wp_get_attachment_image_url($attachment->ID, $size);
                        if ($size_url && $size_url !== $url) {
                            $media_urls[] = $size_url;
                        }
                    }
                }
            }
        }

        $unique_urls = array_unique($media_urls);
        error_log('[CRAWLER] Total unique ' . $content_type . ' URLs: ' . count($unique_urls));

        return $unique_urls;
    }

    /**
     * Get WordPress MIME type filter for content type
     * 
     * @param string $content_type Content type
     * @return string|array MIME type filter for WP_Query
     */
    private function get_mime_type_filter($content_type)
    {
        switch ($content_type) {
            case 'image':
                return 'image';
            case 'document':
                return array(
                    'application/pdf',
                    'application/msword',
                    'application/vnd.ms-excel',
                    'application/vnd.ms-powerpoint',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'text/plain',
                    'text/csv'
                );
            case 'video':
                return 'video';
            case 'audio':
                return 'audio';
            default:
                return null;
        }
    }

    /**
     * Process a broken link: generate suggestion and save to database
     * 
     * @param int $scan_id Scan ID
     * @param string $link Broken URL
     * @param array $found_on_pages Pages where link was found
     * @param array $test_result Test result from link tester
     * @param array $valid_internal_urls Valid internal URLs for suggestions
     */
    private function process_broken_link($scan_id, $link, $found_on_pages, $test_result, $valid_internal_urls)
    {
        error_log('[CRAWLER] 🔴 Processing broken link: ' . $link);

        $is_internal = $this->url_similarity->is_internal_url($link);
        $link_type = $is_internal ? 'internal' : 'external';

        // Add entry for each page where link was found
        foreach ($found_on_pages as $page_data) {
            // Handle both old format (string) and new format (array)
            if (is_array($page_data)) {
                $found_on_url = $page_data['found_on_url'];
                $found_on_page_id = isset($page_data['found_on_page_id']) ? $page_data['found_on_page_id'] : 0;
                $found_on_page_title = isset($page_data['found_on_page_title']) ? $page_data['found_on_page_title'] : '';
                $anchor_text = isset($page_data['anchor_text']) ? $page_data['anchor_text'] : '';
                $location = isset($page_data['location']) ? $page_data['location'] : 'content';
            } else {
                $found_on_url = $page_data;
                $found_on_page_id = 0;
                $found_on_page_title = '';
                $anchor_text = '';
                $location = 'content';
            }

            // Generate suggestion - filter out the current page to avoid suggesting itself
            $suggested_url = null;
            $reason = '';

            if ($is_internal) {
                // ✅ CONTENT-TYPE AWARE SUGGESTION MATCHING
                // Detect what type of URL this is (image, document, video, or page)
                $broken_url_type = $this->get_url_content_type($link);
                error_log('[CRAWLER] 🔍 Broken URL type detected: ' . $broken_url_type . ' for URL: ' . $link);

                // Get appropriate candidate URLs based on content type
                $candidate_urls = array();

                if ($broken_url_type === 'image') {
                    // Get all image URLs from media library
                    $candidate_urls = $this->get_all_media_urls('image');
                    error_log('[CRAWLER] Searching within ' . count($candidate_urls) . ' image URLs for match');
                } elseif ($broken_url_type === 'document') {
                    // Get all document URLs from media library
                    $candidate_urls = $this->get_all_media_urls('document');
                    error_log('[CRAWLER] Searching within ' . count($candidate_urls) . ' document URLs for match');
                } elseif ($broken_url_type === 'video') {
                    // Get all video URLs from media library
                    $candidate_urls = $this->get_all_media_urls('video');
                    error_log('[CRAWLER] Searching within ' . count($candidate_urls) . ' video URLs for match');
                } elseif ($broken_url_type === 'audio') {
                    // Get all audio URLs from media library
                    $candidate_urls = $this->get_all_media_urls('audio');
                    error_log('[CRAWLER] Searching within ' . count($candidate_urls) . ' audio URLs for match');
                } else {
                    // For regular page URLs, use site pages/posts
                    // Filter out the page where this broken link was found
                    $candidate_urls = array_filter($valid_internal_urls, function ($url) use ($found_on_url) {
                        // Normalize URLs for comparison (remove trailing slashes)
                        return untrailingslashit($url) !== untrailingslashit($found_on_url);
                    });
                    error_log('[CRAWLER] Searching within ' . count($candidate_urls) . ' page URLs for match');
                }

                // Find best match within same content type
                if (!empty($candidate_urls)) {
                    $match = $this->url_similarity->find_closest_match($link, $candidate_urls);

                    // Only use suggestion if score is good enough
                    $min_score_threshold = 0.3; // Minimum similarity score required
                    $score = isset($match['score']) ? $match['score'] : 0;

                    if ($score >= $min_score_threshold) {
                        $suggested_url = $match['url'];
                        $reason = $match['reason'];
                        error_log('[CRAWLER] ✅ Found good match: ' . $suggested_url . ' (score: ' . $score . ')');
                    } else {
                        // Score too low - don't suggest inappropriate match
                        $suggested_url = null;
                        $reason = sprintf(
                            __('No suitable %s replacement found. Please provide a new %s URL or remove this link.', 'seo-autofix-pro'),
                            $broken_url_type,
                            $broken_url_type
                        );
                        error_log('[CRAWLER] ⚠️ Match score too low (' . $score . ' < ' . $min_score_threshold . ') - no suggestion');
                    }
                } else {
                    // No candidate URLs of this type available
                    $suggested_url = null;
                    $reason = sprintf(
                        __('No %s URLs available in your media library. Please upload a new %s or remove this link.', 'seo-autofix-pro'),
                        $broken_url_type,
                        $broken_url_type
                    );
                    error_log('[CRAWLER] ❌ No candidate URLs of type: ' . $broken_url_type);
                }
            } else {
                $reason = __('This link is not working, either delete it or provide a new link', 'seo-autofix-pro');
            }

            $this->db_manager->add_broken_link($scan_id, array(
                'found_on_url' => $found_on_url,
                'found_on_page_id' => $found_on_page_id,
                'found_on_page_title' => $found_on_page_title,
                'broken_url' => $link,
                'link_type' => $link_type,
                'status_code' => $test_result['status_code'],
                'suggested_url' => $suggested_url,
                'reason' => $reason,
                'anchor_text' => $anchor_text,
                'location' => $location
            ));
        }
    }
}
