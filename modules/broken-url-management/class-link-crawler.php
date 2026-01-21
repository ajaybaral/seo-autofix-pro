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

        // Update scan with total URLs found (only on first batch)
        if ($progress_index === 0) {
            $this->db_manager->update_scan($scan_id, array(
                'total_urls_found' => count($all_urls)
            ));
        }

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

        foreach ($links_to_test as $link => $found_on_pages) {
            // Mark as tested
            $tested_links[] = $link;

            // Skip template-generated links (theme/plugin auto-generated)
            if ($this->is_template_generated_link($link)) {
                error_log('[CRAWLER] Skipping template-generated link: ' . $link);
                $tested_count++;
                continue;
            }

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

                    // Don't show homepage as suggestion (it's not useful)
                    $home_url_clean = untrailingslashit(home_url());
                    if (untrailingslashit($suggested_url) === $home_url_clean || untrailingslashit($suggested_url) === $home_url_clean . '/') {
                        $suggested_url = null;
                        $reason = __('No relevant page found. Please provide a custom link or redirect to home.', 'seo-autofix-pro');
                    }
                } else {
                    $reason = __('This link is not working, either delete it or provide a new link', 'seo-autofix-pro');
                }

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

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $urls[] = array(
                    'url' => get_permalink(),
                    'page_id' => get_the_ID(),
                    'page_title' => get_the_title()
                );
            }
            wp_reset_postdata();
        }

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

            if (empty($href) || $href === '#') {
                continue;
            }

            // Skip mailto, tel, javascript, etc.
            if (preg_match('/^(mailto|tel|javascript|#):/i', $href)) {
                continue;
            }

            // Convert relative URLs to absolute
            if (strpos($href, '/') === 0 && strpos($href, '//') !== 0) {
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
}
