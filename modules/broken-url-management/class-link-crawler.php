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
     * Universal link-bearing key names.
     * Builder-agnostic: works for Elementor, Gutenberg, WPBakery, Divi,
     * ACF, theme options, nav menus, widgets, and custom builders.
     */
    public static $LINK_KEYS = array(
        'url',
        'href',
        'link',
        'external_url',
        'custom_link',
        'attachment_link',
        'file_url',
        'button_link',
        'cta_link',
        'redirect_url',
        'source_url',
        '_menu_item_url',
        'icon_link',
        'link_url',
        'action_url',
        'target_url',
        'link_to',
        'slide_url',
        'banner_link',
        'card_link',
        'image_url',
        'background_url',
        'video_url',
        'document_url',
        'download_url',
        'popup_url',
    );

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
        \SEOAutoFix_Debug_Logger::log('[CRAWLER] start_scan() called');

        // Create new scan entry
        $scan_id = $this->db_manager->create_scan();
        \SEOAutoFix_Debug_Logger::log('[CRAWLER] Created scan with ID: ' . $scan_id);

        // Get all URLs to crawl and store in scan metadata
        $all_urls = $this->get_all_site_urls();
        \SEOAutoFix_Debug_Logger::log('[CRAWLER] Found ' . count($all_urls) . ' URLs to crawl');

        // Update scan with total URLs found
        $this->db_manager->update_scan($scan_id, array(
            'total_urls_found' => count($all_urls)
        ));

        // Store the URLs to process in a transient for this scan
        set_transient('seoautofix_scan_urls_' . $scan_id, $all_urls, DAY_IN_SECONDS);
        set_transient('seoautofix_scan_progress_' . $scan_id, 0, DAY_IN_SECONDS);

        \SEOAutoFix_Debug_Logger::log('[CRAWLER] Scan initialized, ready for batch processing');

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
        \SEOAutoFix_Debug_Logger::log('[CRAWLER] process_batch() called for scan: ' . $scan_id . ', batch_size: ' . $batch_size);

        // Get URLs to process
        $all_urls = get_transient('seoautofix_scan_urls_' . $scan_id);
        if ($all_urls === false) {
            \SEOAutoFix_Debug_Logger::log('[CRAWLER] No URLs found in transient, scan may have expired');
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

        \SEOAutoFix_Debug_Logger::log('[CRAWLER] Current progress: ' . $progress_index . '/' . count($all_urls));

        // Get the batch of URLs to process
        $batch_urls = array_slice($all_urls, $progress_index, $batch_size);

        if (empty($batch_urls)) {
            \SEOAutoFix_Debug_Logger::log('[CRAWLER] No more URLs to process, marking scan as completed');

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

            \SEOAutoFix_Debug_Logger::log('[CRAWLER] Extracting links from: ' . $page_url . ' (Title: ' . $page_title . ')');

            // Use v2 method to get links with metadata
            $links = $this->extract_links_from_page_v2($page_url, $page_id, $page_title);
            \SEOAutoFix_Debug_Logger::log('[CRAWLER] Found ' . count($links) . ' links on this page');

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

        \SEOAutoFix_Debug_Logger::log('[CRAWLER] Batch completed. Progress: ' . $new_progress . '/' . count($all_urls) . ' (' . $progress_percent . '%)');

        // Get current broken links and stats for real-time frontend updates
        $broken_links = $this->get_broken_links_for_scan($scan_id);
        $stats = $this->get_scan_stats($scan_id);

        \SEOAutoFix_Debug_Logger::log('[CRAWLER] 🔵 Returning batch response with ' . count($broken_links) . ' broken links');
        \SEOAutoFix_Debug_Logger::log('[CRAWLER] Stats: total=' . $stats['total'] . ', 4xx=' . $stats['4xx'] . ', 5xx=' . $stats['5xx']);

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
            \SEOAutoFix_Debug_Logger::log('[CRAWLER] Limited to ' . $max_links_per_batch . ' links per batch (from ' . count($all_links) . ' total)');
        }

        \SEOAutoFix_Debug_Logger::log('[CRAWLER] Testing ' . count($links_to_test) . ' new links');

        // Get valid internal URLs for similarity matching
        $valid_internal_urls_data = $this->get_all_site_urls();
        // Extract just the URL strings from the array of arrays
        $valid_internal_urls = array_column($valid_internal_urls_data, 'url');
        
        // Build URL => Title mapping for anchor text matching
        $valid_urls_with_titles = array();
        foreach ($valid_internal_urls_data as $url_data) {
            $valid_urls_with_titles[$url_data['url']] = $url_data['page_title'];
        }

        // Remove any URLs that are in our broken links list for this scan
        $broken_urls_in_scan = $this->db_manager->get_broken_urls_for_scan($scan_id);
        if (!empty($broken_urls_in_scan)) {
            $valid_internal_urls = array_diff($valid_internal_urls, $broken_urls_in_scan);
            // Also remove from titles mapping
            foreach ($broken_urls_in_scan as $broken_url) {
                unset($valid_urls_with_titles[$broken_url]);
            }
            \SEOAutoFix_Debug_Logger::log('[CRAWLER] Filtered out ' . count($broken_urls_in_scan) . ' broken URLs from suggestions');
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
                \SEOAutoFix_Debug_Logger::log('[CRAWLER] Skipping template-generated link: ' . $link);
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

        \SEOAutoFix_Debug_Logger::log('[CRAWLER] 📊 URL categorization: Internal=' . count($internal_links) . ', External=' . count($external_links) . ', Skipped=' . count($template_links));

        // ✅ PROCESS INTERNAL URLs (FAST - WordPress functions)
        if (!empty($internal_links)) {
            \SEOAutoFix_Debug_Logger::log('[CRAWLER] ⚡ Testing ' . count($internal_links) . ' internal URLs (fast WordPress functions)...');

            foreach ($internal_links as $link => $found_on_pages) {
                $test_result = $this->link_tester->test_url($link);
                $tested_count++;

                // Process if broken
                if ($test_result['is_broken']) {
                    $this->process_broken_link($scan_id, $link, $found_on_pages, $test_result, $valid_internal_urls, $valid_urls_with_titles);
                    $broken_count++;
                }
            }

            \SEOAutoFix_Debug_Logger::log('[CRAWLER] ✅ Internal URLs testing complete');
        }

        // ✅ PROCESS EXTERNAL URLs (PARALLEL - cURL multi-handle)
        if (!empty($external_links)) {
            \SEOAutoFix_Debug_Logger::log('[CRAWLER] 🚀 Testing ' . count($external_links) . ' external URLs (parallel testing)...');

            $external_urls_list = array_keys($external_links);
            $start_time = microtime(true);
            $parallel_results = $this->link_tester->test_urls_parallel($external_urls_list, self::PARALLEL_LIMIT);
            $duration = round(microtime(true) - $start_time, 2);

            \SEOAutoFix_Debug_Logger::log('[CRAWLER] ✅ Parallel testing complete (' . $duration . ' seconds for ' . count($external_urls_list) . ' URLs)');

            foreach ($parallel_results as $link => $test_result) {
                $tested_count++;

                // Process if broken
                if ($test_result['is_broken']) {
                    $found_on_pages = $external_links[$link];
                    $this->process_broken_link($scan_id, $link, $found_on_pages, $test_result, $valid_internal_urls, $valid_urls_with_titles);
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

        \SEOAutoFix_Debug_Logger::log('[CRAWLER] Testing batch complete. Tested: ' . $tested_count . ', Broken: ' . $broken_count);
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

        \SEOAutoFix_Debug_Logger::log('[CRAWLER] WP_Query found ' . $query->found_posts . ' total posts/pages');
        \SEOAutoFix_Debug_Logger::log('[CRAWLER] Post types queried: ' . implode(', ', $args['post_type']));

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $urls[] = array(
                    'url' => get_permalink(),
                    'page_id' => get_the_ID(),
                    'page_title' => get_the_title()
                );
                \SEOAutoFix_Debug_Logger::log('[CRAWLER] Added URL: ' . get_the_title() . ' (' . get_permalink() . ')');
            }
            wp_reset_postdata();
        }

        \SEOAutoFix_Debug_Logger::log('[CRAWLER] Total URLs to crawl (including homepage): ' . count($urls));

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
                \SEOAutoFix_Debug_Logger::log('[CRAWLER] 🎨 Found ' . count($elementor_links) . ' links in Elementor data for page: ' . $page_title);
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
    public function extract_links_from_elementor_data($page_id, $page_title, $page_url)
    {
        // Check if page uses Elementor
        $is_elementor = get_post_meta($page_id, '_elementor_edit_mode', true) === 'builder';
        
        if (!$is_elementor) {
            return array();
        }
        
        \SEOAutoFix_Debug_Logger::log('[CRAWLER] 🎨 Page ID ' . $page_id . ' (' . $page_title . ') is an Elementor page - extracting links from _elementor_data');
        
        // Get Elementor data
        $elementor_data = get_post_meta($page_id, '_elementor_data', true);
        
        if (empty($elementor_data)) {
            \SEOAutoFix_Debug_Logger::log('[CRAWLER] ⚠️ No _elementor_data found for page ID ' . $page_id);
            return array();
        }
        
        // Parse JSON
        $data = json_decode($elementor_data, true);
        
        if (!is_array($data)) {
            \SEOAutoFix_Debug_Logger::log('[CRAWLER] ⚠️ Failed to decode _elementor_data JSON for page ID ' . $page_id);
            return array();
        }
        
        $links = array();
        
        // Recursively search for URLs in Elementor data
        $this->search_elementor_data_for_links($data, $page_id, $page_title, $page_url, $links);
        
        \SEOAutoFix_Debug_Logger::log('[CRAWLER] 🎨 Extracted ' . count($links) . ' links from Elementor data');
        
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
    public function search_elementor_data_for_links($data, $page_id, $page_title, $page_url, &$links)
    {
        // Delegate entirely to the universal deep recursive engine.
        // This ensures all builders (Icon Boxes, Repeaters, Buttons, Slides, etc.)
        // are traversed at unlimited depth.
        $this->deep_extract_links(
            $data,
            $page_id,
            $page_title,
            $page_url,
            'elementor_data',
            'elementor',
            $links
        );
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

    // =========================================================================
    // UNIVERSAL DEEP RECURSIVE EXTRACTION ENGINE
    // =========================================================================

    /**
     * Recursively traverse ANY nested data structure (arrays, objects,
     * embedded JSON strings, PHP-serialized blobs) and collect all URLs
     * found at any nesting depth.
     *
     * Design principles:
     * - Builder-agnostic: no assumptions about schema shape
     * - Unlimited depth: stops only at $depth > 30 guard
     * - Inline decoding: JSON and serialized strings are decoded during
     *   traversal, so URLs buried inside nested blobs are always found
     * - Link-key aware: ANY key in self::$LINK_KEYS triggers URL collection
     *   whether the value is a plain string OR an array containing 'url'
     *   (the Elementor link-object pattern: {url:"...", is_external:"", ...})
     * - All array values are recursed regardless of whether their key
     *   matched a link key — siblings are never skipped
     *
     * @param mixed  $data      The data to traverse
     * @param int    $page_id
     * @param string $page_title
     * @param string $page_url
     * @param string $location  Semantic location tag (e.g. 'elementor_data', 'postmeta', 'content')
     * @param string $builder   Builder identifier
     * @param array  &$links    Collected link objects (appended, never wiped)
     * @param int    $depth     Current recursion depth (internal)
     */
    public function deep_extract_links(
        $data,
        $page_id,
        $page_title,
        $page_url,
        $location,
        $builder,
        &$links,
        $depth = 0
    ) {
        // Safety guard: prevent infinite recursion on circular/pathological data
        if ($depth > 30) {
            return;
        }

        // ── String ───────────────────────────────────────────────────────────
        if (is_string($data)) {
            $data = trim($data);
            if (empty($data) || strlen($data) < 4) {
                return;
            }
            // Attempt JSON decode
            if ($data[0] === '{' || $data[0] === '[') {
                $decoded = json_decode($data, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $this->deep_extract_links($decoded, $page_id, $page_title, $page_url, $location, $builder, $links, $depth + 1);
                    return;
                }
            }
            // Attempt PHP unserialize
            if (is_serialized($data)) {
                $unserialized = @unserialize($data);
                if ($unserialized !== false && (is_array($unserialized) || is_object($unserialized))) {
                    $this->deep_extract_links($unserialized, $page_id, $page_title, $page_url, $location, $builder, $links, $depth + 1);
                    return;
                }
            }
            // Plain string: only collect if it looks like an HTTP/HTTPS URL
            // (Guards against accidentally treating non-URL strings as links)
            if (preg_match('/^https?:\/\//i', $data)) {
                $this->maybe_collect_url($data, '[string value]', $page_id, $page_title, $page_url, $location, $builder, $links);
            }
            return;
        }

        // ── Object → cast to array ────────────────────────────────────────────
        if (is_object($data)) {
            $data = (array) $data;
        }

        if (!is_array($data)) {
            return;
        }

        // ── Array traversal ───────────────────────────────────────────────────
        foreach ($data as $key => $value) {
            $key_str = (string) $key;

            // ── Link-key awareness ────────────────────────────────────────────
            if (in_array($key_str, self::$LINK_KEYS, true)) {

                if (is_string($value) && !empty($value)) {
                    // Direct URL string value
                    $this->maybe_collect_url($value, $key_str, $page_id, $page_title, $page_url, $location, $builder, $links);

                } elseif (is_array($value)) {
                    // Elementor link-object pattern: {"url": "...", "is_external": "", ...}
                    if (isset($value['url']) && is_string($value['url']) && !empty($value['url'])) {
                        $this->maybe_collect_url($value['url'], $key_str . '.url', $page_id, $page_title, $page_url, $location, $builder, $links);
                    }
                    // Continue recursing into the link-value array below (intentional fall-through)
                }
            }

            // ── Always recurse into array/object children ─────────────────────
            // This is the critical difference vs the old elseif approach:
            // We NEVER skip a value just because its key matched a link key.
            // Nested repeater items inside a link array are still fully traversed.
            if (is_array($value) || is_object($value)) {
                $this->deep_extract_links($value, $page_id, $page_title, $page_url, $location, $builder, $links, $depth + 1);

            } elseif (is_string($value) && strlen($value) > 10) {
                // Detect embedded JSON or serialized blobs inside scalar meta values
                // (e.g. a postmeta key whose value is a JSON-encoded array of items)
                if ($value[0] === '{' || $value[0] === '[') {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $this->deep_extract_links($decoded, $page_id, $page_title, $page_url, $location, $builder, $links, $depth + 1);
                    }
                } elseif (is_serialized($value)) {
                    $unserialized = @unserialize($value);
                    if ($unserialized !== false && (is_array($unserialized) || is_object($unserialized))) {
                        $this->deep_extract_links($unserialized, $page_id, $page_title, $page_url, $location, $builder, $links, $depth + 1);
                    }
                }
            }
        }
    }

    /**
     * Validate and collect a URL candidate into the $links array.
     * Single point of URL sanity-checking across the entire extraction engine.
     *
     * @param string $url         Raw URL candidate
     * @param string $key_context The key name that triggered collection (for anchor_text)
     * @param int    $page_id
     * @param string $page_title
     * @param string $page_url
     * @param string $location
     * @param string $builder
     * @param array  &$links
     */
    private function maybe_collect_url(
        $url,
        $key_context,
        $page_id,
        $page_title,
        $page_url,
        $location,
        $builder,
        &$links
    ) {
        $url = trim($url);

        if (empty($url) || $url === '#') {
            return;
        }

        // Reject non-http(s) schemes
        if (preg_match('/^(mailto|tel|javascript|data:|ftp|feed|#)/i', $url)) {
            return;
        }

        // Convert root-relative URLs to absolute
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            $parsed = parse_url(home_url());
            $url    = $parsed['scheme'] . '://' . $parsed['host']
                    . (isset($parsed['port']) ? ':' . $parsed['port'] : '')
                    . $url;
        }

        // Must be a valid URL after normalization
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }

        $links[] = array(
            'url'                  => $url,
            'found_on_url'         => $page_url,
            'found_on_page_id'     => $page_id,
            'found_on_page_title'  => $page_title,
            'location'             => $location,
            'anchor_text'          => '[' . $key_context . ']',
            'builder'              => $builder,
            'dynamic_source'       => false,
        );
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
        \SEOAutoFix_Debug_Logger::log('[CRAWLER] Found ' . count($attachments) . ' attachments of type: ' . $content_type);

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
        \SEOAutoFix_Debug_Logger::log('[CRAWLER] Total unique ' . $content_type . ' URLs: ' . count($unique_urls));

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
     * @param array $valid_urls_with_titles Valid URLs mapped to page titles
     */
    private function process_broken_link($scan_id, $link, $found_on_pages, $test_result, $valid_internal_urls, $valid_urls_with_titles = array())
    {
        \SEOAutoFix_Debug_Logger::log('[CRAWLER] 🔴 Processing broken link: ' . $link);

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
                \SEOAutoFix_Debug_Logger::log('[CRAWLER] 🔍 Broken URL type detected: ' . $broken_url_type . ' for URL: ' . $link);

                // Get appropriate candidate URLs based on content type
                $candidate_urls = array();
                $candidate_urls_with_titles = array();

                if ($broken_url_type === 'image') {
                    // Get all image URLs from media library
                    $candidate_urls = $this->get_all_media_urls('image');
                    \SEOAutoFix_Debug_Logger::log('[CRAWLER] Searching within ' . count($candidate_urls) . ' image URLs for match');
                } elseif ($broken_url_type === 'document') {
                    // Get all document URLs from media library
                    $candidate_urls = $this->get_all_media_urls('document');
                    \SEOAutoFix_Debug_Logger::log('[CRAWLER] Searching within ' . count($candidate_urls) . ' document URLs for match');
                } elseif ($broken_url_type === 'video') {
                    // Get all video URLs from media library
                    $candidate_urls = $this->get_all_media_urls('video');
                    \SEOAutoFix_Debug_Logger::log('[CRAWLER] Searching within ' . count($candidate_urls) . ' video URLs for match');
                } elseif ($broken_url_type === 'audio') {
                    // Get all audio URLs from media library
                    $candidate_urls = $this->get_all_media_urls('audio');
                    \SEOAutoFix_Debug_Logger::log('[CRAWLER] Searching within ' . count($candidate_urls) . ' audio URLs for match');
                } else {
                    // For regular page URLs, use site pages/posts
                    // Filter out the page where this broken link was found
                    $candidate_urls = array_filter($valid_internal_urls, function ($url) use ($found_on_url) {
                        // Normalize URLs for comparison (remove trailing slashes)
                        return untrailingslashit($url) !== untrailingslashit($found_on_url);
                    });
                    
                    // Filter titles mapping as well
                    $candidate_urls_with_titles = array_filter($valid_urls_with_titles, function ($url) use ($found_on_url) {
                        return untrailingslashit($url) !== untrailingslashit($found_on_url);
                    }, ARRAY_FILTER_USE_KEY);
                    
                    \SEOAutoFix_Debug_Logger::log('[CRAWLER] Searching within ' . count($candidate_urls) . ' page URLs for match');
                    \SEOAutoFix_Debug_Logger::log('[CRAWLER] Anchor text for matching: ' . ($anchor_text ? $anchor_text : '[NONE]'));
                }

                // Find best match within same content type
                if (!empty($candidate_urls)) {
                    // Pass anchor text and titles to the updated suggestion algorithm
                    $match = $this->url_similarity->find_closest_match(
                        $link, 
                        $candidate_urls,
                        $anchor_text,
                        $candidate_urls_with_titles
                    );

                    // Check if a valid suggestion was returned (url is not null)
                    if (isset($match['url']) && $match['url'] !== null) {
                        $suggested_url = $match['url'];
                        $reason = isset($match['reason']) ? $match['reason'] : '';
                        $score = isset($match['score']) ? $match['score'] : 0;
                        \SEOAutoFix_Debug_Logger::log('[CRAWLER] ✅ Found suggestion: ' . $suggested_url . ' (score: ' . $score . ')');
                    } else {
                        // No suggestion found (score below threshold or no match)
                        $suggested_url = null;
                        $score = isset($match['score']) ? $match['score'] : 0;
                        $reason = sprintf(
                            __('No suitable %s replacement found (best match: %s%%). Please provide a new %s URL or remove this link.', 'seo-autofix-pro'),
                            $broken_url_type,
                            round($score, 0),
                            $broken_url_type
                        );
                        \SEOAutoFix_Debug_Logger::log('[CRAWLER] ❌ No suggestion found (best score: ' . $score . '%)');
                    }
                } else {
                    // No candidate URLs of this type available
                    $suggested_url = null;
                    $reason = sprintf(
                        __('No %s URLs available in your media library. Please upload a new %s or remove this link.', 'seo-autofix-pro'),
                        $broken_url_type,
                        $broken_url_type
                    );
                    \SEOAutoFix_Debug_Logger::log('[CRAWLER] ❌ No candidate URLs of type: ' . $broken_url_type);
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

    // =========================================================================
    // STORAGE-BASED EXTRACTION (builder-aware, no HTML fetch)
    // =========================================================================

    /**
     * Master router: extract links from storage for a single page.
     * Detects the builder and dispatches to the correct extractor.
     * Also appends nav-menu and header/footer template links.
     *
     * @param int    $page_id    Post ID (0 = homepage)
     * @param string $page_title Post title
     * @param string $page_url   Permalink
     * @return array Flat array of link objects
     */
    public function extract_links_from_storage($page_id, $page_title, $page_url)
    {
        $links = array();

        if ($page_id > 0) {
            // Detect builder
            $builder = 'classic';
            if (class_exists('SEOAutoFix\\BrokenUrlManagement\\Builder_Detector')) {
                $builder = Builder_Detector::detect($page_id);
            } elseif (get_post_meta($page_id, '_elementor_edit_mode', true) === 'builder') {
                $builder = 'elementor';
            } elseif (has_blocks(get_the_content(null, false, $page_id))) {
                $builder = 'gutenberg';
            }

            \SEOAutoFix_Debug_Logger::log('[STORAGE_EXTRACT] Page ID ' . $page_id . ' → builder: ' . $builder);

            switch ($builder) {
                case 'elementor':
                    $links = array_merge($links, $this->extract_links_from_elementor_data($page_id, $page_title, $page_url));
                    // Also scan _elementor_page_settings
                    $page_settings = get_post_meta($page_id, '_elementor_page_settings', true);
                    if (!empty($page_settings) && is_array($page_settings)) {
                        $this->search_elementor_data_for_links($page_settings, $page_id, $page_title, $page_url, $links);
                    }
                    break;

                case 'gutenberg':
                    $links = array_merge($links, $this->extract_links_from_gutenberg($page_id, $page_title, $page_url));
                    break;

                case 'wpbakery':
                case 'divi':
                case 'classic':
                default:
                    $links = array_merge($links, $this->extract_links_from_classic($page_id, $page_title, $page_url));
                    break;
            }

            // Always run postmeta deep scan as a supplemental layer (catches non-builder meta, e.g. ACF fields)
            $links = array_merge($links, $this->extract_links_from_postmeta($page_id, $page_title, $page_url, $builder));

            \SEOAutoFix_Debug_Logger::log('[STORAGE_EXTRACT] Page ' . $page_id . ': ' . count($links) . ' raw links before nav/hf merge');
        }

        return $links;
    }

    /**
     * Standalone: extract nav menu links (called once per scan by the AJAX handler,
     * not per page, since menus are site-wide).
     *
     * @return array Flat array of link objects (found_on_url = home_url)
     */
    public function extract_links_from_nav_menus()
    {
        $links = array();
        $home_url  = home_url('/');
        $site_name = get_bloginfo('name') . ' – Nav Menu';

        $menus = wp_get_nav_menus();
        if (empty($menus)) {
            return $links;
        }

        foreach ($menus as $menu) {
            $items = wp_get_nav_menu_items($menu->term_id);
            if (empty($items)) {
                continue;
            }
            foreach ($items as $item) {
                $url = get_post_meta($item->ID, '_menu_item_url', true);
                if (empty($url)) {
                    // Fallback: use the object URL if this is a custom link type
                    $url = $item->url;
                }
                if (empty($url) || $url === '#' || preg_match('/^(mailto|tel|javascript):/i', $url)) {
                    continue;
                }
                $links[] = array(
                    'url'                  => $url,
                    'found_on_url'         => $home_url,
                    'found_on_page_id'     => 0,
                    'found_on_page_title'  => $site_name,
                    'location'             => 'nav_menu',
                    'anchor_text'          => $item->title ?: '[Nav Item]',
                    'builder'              => 'nav_menu',
                    'dynamic_source'       => false,
                );
            }
        }

        \SEOAutoFix_Debug_Logger::log('[STORAGE_EXTRACT] Nav menus: ' . count($links) . ' links found');
        return $links;
    }

    /**
     * Standalone: extract links from Elementor header/footer library templates.
     *
     * @return array Flat array of link objects (found_on_url = home_url)
     */
    public function extract_links_from_hf_templates()
    {
        $links    = array();
        $home_url = home_url('/');

        // Elementor Theme Builder templates stored as 'elementor_library' posts
        $templates = get_posts(array(
            'post_type'      => 'elementor_library',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'meta_query'     => array(
                array(
                    'key'     => '_elementor_template_type',
                    'value'   => array('header', 'footer'),
                    'compare' => 'IN',
                ),
            ),
        ));

        foreach ($templates as $template) {
            $ttype  = get_post_meta($template->ID, '_elementor_template_type', true);
            $tlabel = ucfirst($ttype) . ' Template: ' . $template->post_title;

            // Extract from _elementor_data
            $el_data = get_post_meta($template->ID, '_elementor_data', true);
            if (!empty($el_data)) {
                $decoded = json_decode($el_data, true);
                if (is_array($decoded)) {
                    $template_links = array();
                    $this->search_elementor_data_for_links($decoded, $template->ID, $tlabel, $home_url, $template_links);
                    // Override location to header/footer so deduplication logic in JS still works
                    foreach ($template_links as &$tl) {
                        $tl['location']            = $ttype; // 'header' or 'footer'
                        $tl['found_on_url']        = $home_url;
                        $tl['found_on_page_id']    = 0;
                        $tl['found_on_page_title'] = 'Home Page';
                        $tl['builder']             = 'elementor';
                    }
                    unset($tl);
                    $links = array_merge($links, $template_links);
                }
            }
        }

        \SEOAutoFix_Debug_Logger::log('[STORAGE_EXTRACT] HF templates: ' . count($links) . ' links found');
        return $links;
    }

    /**
     * Extract links from Gutenberg blocks (parse_blocks on post_content).
     *
     * @param int    $page_id
     * @param string $page_title
     * @param string $page_url
     * @return array
     */
    public function extract_links_from_gutenberg($page_id, $page_title, $page_url)
    {
        $links   = array();
        $post    = get_post($page_id);
        if (!$post || empty($post->post_content)) {
            return $links;
        }

        if (!function_exists('parse_blocks')) {
            // Fallback to classic
            return $this->extract_links_from_classic($page_id, $page_title, $page_url);
        }

        $blocks = parse_blocks($post->post_content);
        $this->_collect_gutenberg_links($blocks, $page_id, $page_title, $page_url, $links);

        \SEOAutoFix_Debug_Logger::log('[STORAGE_EXTRACT] Gutenberg page ' . $page_id . ': ' . count($links) . ' links');
        return $links;
    }

    /** @internal */
    private function _collect_gutenberg_links(array $blocks, $page_id, $page_title, $page_url, &$links)
    {
        foreach ($blocks as $block) {
            // Run the universal engine on all block attrs —
            // this covers any URL field a block may define, not just a fixed whitelist
            if (!empty($block['attrs']) && is_array($block['attrs'])) {
                $this->deep_extract_links(
                    $block['attrs'],
                    $page_id,
                    $page_title,
                    $page_url,
                    'content',
                    'gutenberg',
                    $links
                );
            }

            // Also scan innerHTML for <a href> / <img src> (Gutenberg stores raw HTML here)
            if (!empty($block['innerHTML'])) {
                $this->_extract_links_from_html_string($block['innerHTML'], $page_id, $page_title, $page_url, 'gutenberg', $links);
            }

            // Recurse into inner blocks
            if (!empty($block['innerBlocks'])) {
                $this->_collect_gutenberg_links($block['innerBlocks'], $page_id, $page_title, $page_url, $links);
            }
        }
    }

    /**
     * Extract links from classic (or unknown builder) post_content using regex.
     *
     * @param int    $page_id
     * @param string $page_title
     * @param string $page_url
     * @return array
     */
    public function extract_links_from_classic($page_id, $page_title, $page_url)
    {
        $links = array();
        $post  = get_post($page_id);
        if (!$post || empty($post->post_content)) {
            return $links;
        }

        $this->_extract_links_from_html_string($post->post_content, $page_id, $page_title, $page_url, 'classic', $links);

        \SEOAutoFix_Debug_Logger::log('[STORAGE_EXTRACT] Classic page ' . $page_id . ': ' . count($links) . ' links');
        return $links;
    }

    /**
     * Internal helper: parse an HTML/shortcode string for <a href> and <img src>.
     *
     * @internal
     */
    private function _extract_links_from_html_string($html, $page_id, $page_title, $page_url, $builder, &$links)
    {
        // Extract <a href=...>
        preg_match_all('/<a\s[^>]*href=["\']([^"\'\s]+)["\'][^>]*>(.*?)<\/a>/is', $html, $a_matches, PREG_SET_ORDER);
        foreach ($a_matches as $m) {
            $href        = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $anchor_text = trim(strip_tags($m[2]));
            if (empty($href) || preg_match('/^(mailto|tel|javascript|#|data:)/i', $href)) {
                continue;
            }
            // Make absolute
            if (strpos($href, '/') === 0 && strpos($href, '//') !== 0) {
                $parsed = parse_url(home_url());
                $href   = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '') . $href;
            }
            $links[] = array(
                'url'                  => $href,
                'found_on_url'         => $page_url,
                'found_on_page_id'     => $page_id,
                'found_on_page_title'  => $page_title,
                'location'             => 'content',
                'anchor_text'          => $anchor_text ?: '[No text]',
                'builder'              => $builder,
                'dynamic_source'       => false,
            );
        }

        // Extract <img src=...>
        preg_match_all('/<img\s[^>]*src=["\']([^"\'\s]+)["\'][^>]*>/is', $html, $img_matches, PREG_SET_ORDER);
        foreach ($img_matches as $m) {
            $src = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (empty($src) || strpos($src, 'data:') === 0) {
                continue;
            }
            if (strpos($src, '/') === 0 && strpos($src, '//') !== 0) {
                $parsed = parse_url(home_url());
                $src    = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '') . $src;
            }
            $links[] = array(
                'url'                  => $src,
                'found_on_url'         => $page_url,
                'found_on_page_id'     => $page_id,
                'found_on_page_title'  => $page_title,
                'location'             => 'content',
                'anchor_text'          => '[Image]',
                'builder'              => $builder,
                'dynamic_source'       => false,
            );
        }
    }

    /**
     * Supplemental postmeta deep scan: checks all non-standard meta keys for URLs.
     * Handles JSON, PHP-serialized blobs, and plain strings.
     * This runs AFTER the primary builder extractor as a catch-all layer.
     *
     * @param int    $page_id
     * @param string $page_title
     * @param string $page_url
     * @param string $builder   Builder name (used to skip meta already covered)
     * @return array
     */
    public function extract_links_from_postmeta($page_id, $page_title, $page_url, $builder = 'classic')
    {
        $links = array();

        // Keys already handled by builder-specific extractors — skip to avoid duplicates
        static $skip_keys = null;
        if ($skip_keys === null) {
            $skip_keys = array(
                '_elementor_data',
                '_elementor_page_settings',
                '_edit_lock',
                '_edit_last',
                '_wp_page_template',
                '_wp_attachment_metadata',
                '_thumbnail_id',
                '_wp_old_slug',
                '_wp_trash_meta_status',
                '_wp_trash_meta_time',
            );
        }

        $all_meta = get_post_meta($page_id);
        if (empty($all_meta)) {
            return $links;
        }

        foreach ($all_meta as $meta_key => $meta_values) {
            if (in_array($meta_key, $skip_keys, true)) {
                continue;
            }
            // Skip core WordPress internal meta
            if (strpos($meta_key, '_wp_') === 0) {
                continue;
            }

            foreach ($meta_values as $raw_value) {
                // Route each raw meta value through the universal deep engine.
                // It handles JSON strings, serialized blobs, plain URLs, and
                // nested arrays at all levels automatically.
                $this->deep_extract_links(
                    $raw_value,
                    $page_id,
                    $page_title,
                    $page_url,
                    'postmeta',
                    $builder,
                    $links
                );
            }
        }

        \SEOAutoFix_Debug_Logger::log('[STORAGE_EXTRACT] Postmeta scan page ' . $page_id . ': ' . count($links) . ' supplemental links');
        return $links;
    }

    // _scan_meta_structure() is retained for backwards compatibility with any
    // external callers but now simply delegates to the universal engine.
    /** @internal */
    private function _scan_meta_structure($data, array $link_keys, $page_id, $page_title, $page_url, $builder, &$links)
    {
        // Ignore $link_keys param — the universal engine uses self::$LINK_KEYS instead
        $this->deep_extract_links($data, $page_id, $page_title, $page_url, 'postmeta', $builder, $links);
    }
}
