<?php
/**
 * Image SEO Module - Image Usage Tracker
 * 
 * Tracks where images are used across the site
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
 * Image Usage Tracker Class
 */
class Image_Usage_Tracker
{

    /**
     * Get batch usage with caching (PERFORMANCE OPTIMIZED)
     * Calculates usage for ALL images ONCE and caches for 1 hour
     * 
     * @param array $attachment_ids Array of attachment IDs
     * @return array Usage data for requested attachments
     */
    public function get_cached_batch_usage($attachment_ids)
    {
        if (empty($attachment_ids)) {
            return array();
        }

        global $wpdb;
        $cache_key = 'seoautofix_image_usage_all';
        $cache_meta_key = 'seoautofix_image_usage_meta';

        // Get current counts for change detection
        $current_image_count = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'attachment' 
            AND p.post_mime_type LIKE 'image/%'
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->posts} p2 
                WHERE p2.post_type = 'attachment' 
                AND p2.ID = p.post_parent
                AND p2.post_mime_type LIKE 'image/%'
            )
        ");

        $current_page_count = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_status = 'publish' AND post_type IN ('post', 'page')
        ");

        // Get last modified timestamp for content change detection
        // This detects when images are removed from posts/pages
        $current_last_modified = $wpdb->get_var("
            SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}
            WHERE post_status = 'publish' AND post_type IN ('post', 'page')
        ");

        // Check cache and metadata
        $cached_data = get_transient($cache_key);
        $cached_meta = get_transient($cache_meta_key);

        // Validate cache: check if counts OR content have changed
        $cache_valid = false;
        if ($cached_data !== false && $cached_meta !== false) {
            $cache_valid = (
                $cached_meta['image_count'] === $current_image_count &&
                $cached_meta['page_count'] === $current_page_count &&
                $cached_meta['last_modified'] === $current_last_modified
            );

            if ($cache_valid) {
                \SEOAutoFix_Debug_Logger::log('✅ Cache valid - no changes detected (' . count($cached_data) . ' images)', 'image-seo');
            } else {
                $change_reason = '';
                if ($cached_meta['image_count'] !== $current_image_count) {
                    $change_reason .= 'images: ' . $cached_meta['image_count'] . '→' . $current_image_count . ' ';
                }
                if ($cached_meta['page_count'] !== $current_page_count) {
                    $change_reason .= 'pages: ' . $cached_meta['page_count'] . '→' . $current_page_count . ' ';
                }
                if ($cached_meta['last_modified'] !== $current_last_modified) {
                    $change_reason .= 'content modified ';
                }
                \SEOAutoFix_Debug_Logger::log('🔄 Cache invalid - changes detected (' . trim($change_reason) . ')', 'image-seo');
            }
        }

        // If cache is valid, return cached data
        if ($cache_valid) {
            $result = array();
            foreach ($attachment_ids as $id) {
                if (isset($cached_data[$id])) {
                    $result[$id] = $cached_data[$id];
                }
            }
            return $result;
        }

        // Cache invalid or missing - recalculate
        \SEOAutoFix_Debug_Logger::log('📊 Recalculating usage for ' . $current_image_count . ' images...', 'image-seo');
        $calc_start = microtime(true);

        // Get ALL images
        $all_image_ids = $wpdb->get_col("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'attachment' 
            AND p.post_mime_type LIKE 'image/%'
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->posts} p2 
                WHERE p2.post_type = 'attachment' 
                AND p2.ID = p.post_parent
                AND p2.post_mime_type LIKE 'image/%'
            )
        ");

        // Calculate usage for ALL images
        $all_usage = $this->get_batch_image_usage($all_image_ids);

        // Cache with metadata (NO TIME LIMIT - only invalidates on change)
        set_transient($cache_key, $all_usage, 0); // 0 = never expires
        set_transient($cache_meta_key, array(
            'image_count' => $current_image_count,
            'page_count' => $current_page_count,
            'last_modified' => $current_last_modified,
            'last_updated' => time()
        ), 0); // 0 = never expires

        $calc_time = microtime(true) - $calc_start;
        \SEOAutoFix_Debug_Logger::log('✅ Cached usage for ' . count($all_usage) . ' images in ' . number_format($calc_time, 2) . 's', 'image-seo');

        // Return data for requested IDs
        $result = array();
        foreach ($attachment_ids as $id) {
            if (isset($all_usage[$id])) {
                $result[$id] = $all_usage[$id];
            }
        }
        return $result;
    }

    /**
     * Get raw Elementor data for frontend parsing (PERFORMANCE OPTIMIZATION)
     * Returns unprocessed Elementor JSON for all pages
     * Frontend JavaScript will parse this data (much faster than PHP)
     * 
     * @return array Array of [post_id => elementor_json]
     */
    public function get_raw_elementor_data()
    {
        global $wpdb;

        $cache_key = 'seoautofix_elementor_raw_data';
        $cache_meta_key = 'seoautofix_elementor_meta';

        // Get current Elementor page count for change detection
        $current_elementor_count = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->postmeta}
            WHERE meta_key = '_elementor_data' AND meta_value != ''
        ");

        // Check cache and metadata
        $cached_data = get_transient($cache_key);
        $cached_meta = get_transient($cache_meta_key);

        // Validate cache: check if Elementor page count changed
        $cache_valid = false;
        if ($cached_data !== false && $cached_meta !== false) {
            $cache_valid = ($cached_meta['elementor_count'] === $current_elementor_count);

            if ($cache_valid) {
                \SEOAutoFix_Debug_Logger::log('✅ Elementor cache valid (' . count($cached_data) . ' pages)', 'image-seo');
                return $cached_data;
            } else {
                \SEOAutoFix_Debug_Logger::log('🔄 Elementor cache invalid - changes detected (' . $cached_meta['elementor_count'] . '→' . $current_elementor_count . ')', 'image-seo');
            }
        }

        // Cache invalid or missing - fetch fresh data
        \SEOAutoFix_Debug_Logger::log('📊 Fetching Elementor data for ' . $current_elementor_count . ' pages...', 'image-seo');
        $start_time = microtime(true);

        // Simple query - just fetch the data, no parsing
        $results = $wpdb->get_results("
            SELECT post_id, meta_value as elementor_json
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_elementor_data'
            AND meta_value != ''
        ", ARRAY_A);

        // Convert to associative array
        $elementor_data = array();
        foreach ($results as $row) {
            $elementor_data[$row['post_id']] = $row['elementor_json'];
        }

        // Cache with metadata (NO TIME LIMIT - only invalidates on change)
        set_transient($cache_key, $elementor_data, 0); // 0 = never expires
        set_transient($cache_meta_key, array(
            'elementor_count' => $current_elementor_count,
            'last_updated' => time()
        ), 0); // 0 = never expires

        $elapsed = microtime(true) - $start_time;
        \SEOAutoFix_Debug_Logger::log('✅ Cached Elementor data for ' . count($elementor_data) . ' pages in ' . number_format($elapsed, 3) . 's', 'image-seo');

        return $elementor_data;
    }

    /**
     * Get raw posts data for frontend processing (PERFORMANCE OPTIMIZATION)
     * Returns unprocessed post content for JavaScript matching
     * Frontend JavaScript will do the matching (much faster than PHP)
     * 
     * @return array Array of posts with ID, title, content, type
     */
    public function get_raw_posts_data()
    {
        global $wpdb;

        $cache_key = 'seoautofix_raw_posts_data';
        $cache_meta_key = 'seoautofix_raw_posts_meta';

        // Get current post count for change detection
        $current_post_count = (int) $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_status = 'publish' AND post_type IN ('post', 'page')
        ");

        // Get last modified timestamp for content change detection
        $current_last_modified = $wpdb->get_var("
            SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}
            WHERE post_status = 'publish' AND post_type IN ('post', 'page')
        ");

        // Check cache and metadata
        $cached_data = get_transient($cache_key);
        $cached_meta = get_transient($cache_meta_key);

        // Validate cache: check if counts OR content have changed
        $cache_valid = false;
        if ($cached_data !== false && $cached_meta !== false) {
            $cache_valid = (
                $cached_meta['post_count'] === $current_post_count &&
                $cached_meta['last_modified'] === $current_last_modified
            );

            if ($cache_valid) {
                \SEOAutoFix_Debug_Logger::log('✅ Posts cache valid (' . count($cached_data) . ' posts)', 'image-seo');
                return $cached_data;
            } else {
                \SEOAutoFix_Debug_Logger::log('🔄 Posts cache invalid - changes detected', 'image-seo');
            }
        }

        // Cache invalid or missing - fetch fresh data
        \SEOAutoFix_Debug_Logger::log('📊 Fetching raw posts data for ' . $current_post_count . ' posts...', 'image-seo');
        $start_time = microtime(true);

        // Simple query - just fetch the data, no processing
        $posts = $wpdb->get_results("
            SELECT ID, post_title, post_content, post_type 
            FROM {$wpdb->posts} 
            WHERE post_status = 'publish' 
            AND post_type IN ('post', 'page')
        ", ARRAY_A);

        // Cache with metadata (NO TIME LIMIT - only invalidates on change)
        set_transient($cache_key, $posts, 0); // 0 = never expires
        set_transient($cache_meta_key, array(
            'post_count' => $current_post_count,
            'last_modified' => $current_last_modified,
            'last_updated' => time()
        ), 0); // 0 = never expires

        $elapsed = microtime(true) - $start_time;
        \SEOAutoFix_Debug_Logger::log('✅ Cached raw posts data for ' . count($posts) . ' posts in ' . number_format($elapsed, 3) . 's', 'image-seo');

        return $posts;
    }

    /**
     * Get raw featured images map for frontend processing
     * Returns simple map of post_id => attachment_id
     * 
     * @return array Map of post_id => attachment_id
     */
    public function get_raw_featured_images()
    {
        global $wpdb;

        $cache_key = 'seoautofix_raw_featured_map';

        // Check cache
        $cached_data = get_transient($cache_key);
        if ($cached_data !== false) {
            \SEOAutoFix_Debug_Logger::log('✅ Featured images cache valid (' . count($cached_data) . ' entries)', 'image-seo');
            return $cached_data;
        }

        // Fetch fresh data
        \SEOAutoFix_Debug_Logger::log('📊 Fetching featured images map...', 'image-seo');
        $start_time = microtime(true);

        $results = $wpdb->get_results("
            SELECT post_id, meta_value as attachment_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_thumbnail_id'
            AND meta_value != ''
        ", ARRAY_A);

        $map = array();
        foreach ($results as $row) {
            $map[$row['post_id']] = (int) $row['attachment_id'];
        }

        // Cache it (invalidates when posts cache invalidates)
        set_transient($cache_key, $map, 0);

        $elapsed = microtime(true) - $start_time;
        \SEOAutoFix_Debug_Logger::log('✅ Cached featured images map (' . count($map) . ' entries) in ' . number_format($elapsed, 3) . 's', 'image-seo');

        return $map;
    }


    /**
     * Get image usage information
     *
     * @param int $attachment_id The attachment ID
     * @return array Usage information
     */
    public function get_image_usage($attachment_id)
    {
        $usage = array(
            'attachment_id' => $attachment_id,
            'pages' => array(),
            'total_uses' => 0
        );

        // Check post content usage
        $content_usage = $this->scan_post_content($attachment_id);
        $usage['pages'] = array_merge($usage['pages'], $content_usage);

        // Check featured image usage
        $featured_usage = $this->scan_featured_images($attachment_id);
        $usage['pages'] = array_merge($usage['pages'], $featured_usage);

        // Check background image usage (CSS backgrounds)
        $background_usage = $this->scan_background_images($attachment_id);
        $usage['pages'] = array_merge($usage['pages'], $background_usage);

        // Remove duplicates
        $usage['pages'] = $this->remove_duplicate_pages($usage['pages']);

        $usage['total_uses'] = count($usage['pages']);

        return $usage;
    }

    /**
     * OPTIMIZED: Get usage for multiple images at once (batch processing)
     * This reduces 780 queries to just 3-4 queries!
     * 
     * @param array $attachment_ids Array of attachment IDs
     * @return array Associative array of attachment_id => usage data
     */
    public function get_batch_image_usage($attachment_ids)
    {
        if (empty($attachment_ids)) {
            \SEOAutoFix_Debug_Logger::log('⚠️ [USAGE-TRACKER] get_batch_image_usage called with empty IDs');
            return array();
        }

        \SEOAutoFix_Debug_Logger::log('USAGE TRACKER: Processing ' . count($attachment_ids) . ' images', 'image-seo');
        \SEOAutoFix_Debug_Logger::log('First 5 IDs: ' . implode(', ', array_slice($attachment_ids, 0, 5)), 'image-seo');
        $start_time = microtime(true);

        global $wpdb;
        \SEOAutoFix_Debug_Logger::log('Building filename map...', 'image-seo');

        // Build filename mapping for quick lookups
        $image_map = array(); // attachment_id => filename
        foreach ($attachment_ids as $id) {
            $url = wp_get_attachment_url($id);
            if ($url) {
                $image_map[$id] = basename($url);
            }
        }

        \SEOAutoFix_Debug_Logger::log('Built filename map for ' . count($image_map) . ' images', 'image-seo');

        // Initialize results
        \SEOAutoFix_Debug_Logger::log('Initializing results array...', 'image-seo');
        $results = array();
        foreach ($attachment_ids as $id) {
            $results[$id] = array(
                'attachment_id' => $id,
                'pages' => array(),
                'total_uses' => 0
            );
        }
        \SEOAutoFix_Debug_Logger::log('Results array initialized', 'image-seo');

        // QUERY 1: Get ALL published posts/pages content at once
        \SEOAutoFix_Debug_Logger::log('About to run QUERY 1: Get all published posts...', 'image-seo');
        $query_start = microtime(true);

        try {
            $all_posts = $wpdb->get_results("
                SELECT ID, post_title, post_content, post_type 
                FROM {$wpdb->posts} 
                WHERE post_status = 'publish' 
                AND post_type IN ('post', 'page', 'elementor_library')
            ");
            $query1_time = microtime(true) - $query_start;
            \SEOAutoFix_Debug_Logger::log('QUERY 1 completed: ' . count($all_posts) . ' posts in ' . number_format($query1_time, 3) . 's', 'image-seo');
        } catch (Exception $e) {
            \SEOAutoFix_Debug_Logger::log('QUERY 1 FAILED: ' . $e->getMessage(), 'image-seo');
            throw $e;
        }

        // QUERY 2: Get ALL featured images at once
        \SEOAutoFix_Debug_Logger::log('About to run QUERY 2: Get featured images...', 'image-seo');
        $query_start = microtime(true);
        $numeric_ids = array_filter($attachment_ids, 'is_numeric');
        \SEOAutoFix_Debug_Logger::log('Filtered to ' . count($numeric_ids) . ' numeric IDs', 'image-seo');
        $featured_images = array();

        if (!empty($numeric_ids)) {
            \SEOAutoFix_Debug_Logger::log('Building Query 2 SQL...', 'image-seo');
            $ids_list = implode(',', $numeric_ids);
            \SEOAutoFix_Debug_Logger::log('IDs list length: ' . strlen($ids_list) . ' chars', 'image-seo');

            try {
                \SEOAutoFix_Debug_Logger::log('Executing Query 2...', 'image-seo');
                $featured_images = $wpdb->get_results("
                    SELECT post_id, meta_value as attachment_id
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = '_thumbnail_id'
                    AND meta_value IN (" . $ids_list . ")
                ");
                \SEOAutoFix_Debug_Logger::log('Query 2 completed: ' . count($featured_images) . ' featured images', 'image-seo');
            } catch (Exception $e) {
                \SEOAutoFix_Debug_Logger::log('Query 2 FAILED: ' . $e->getMessage(), 'image-seo');
                throw $e;
            }
        } else {
            \SEOAutoFix_Debug_Logger::log('No numeric IDs, skipping Query 2', 'image-seo');
        }
        $query2_time = microtime(true) - $query_start;
        \SEOAutoFix_Debug_Logger::log('Query 2 total time: ' . number_format($query2_time, 3) . 's', 'image-seo');
        \SEOAutoFix_Debug_Logger::log('⏱️ [USAGE-TRACKER] Query 2 (featured images): ' . number_format($query2_time, 3) . 's - ' . count($featured_images) . ' matches');

        // QUERY 3: REMOVED - Elementor parsing moved to frontend for performance
        // Frontend JavaScript will handle Elementor data parsing (much faster than PHP)
        // This eliminates the timeout issue on sites with many pages
        \SEOAutoFix_Debug_Logger::log('Elementor parsing skipped - will be handled by frontend', 'image-seo');
        $elementor_matches = array(); // Empty array - no backend Elementor matching

        // Process featured images
        \SEOAutoFix_Debug_Logger::log('Processing featured images map...', 'image-seo');
        $featured_map = array(); // post_id => attachment_id
        foreach ($featured_images as $row) {
            $featured_map[$row->post_id] = intval($row->meta_value);
        }
        \SEOAutoFix_Debug_Logger::log('Featured map built: ' . count($featured_map) . ' entries', 'image-seo');

        // Now match images against posts
        \SEOAutoFix_Debug_Logger::log('Starting image matching loop...', 'image-seo');
        \SEOAutoFix_Debug_Logger::log('Total posts to check: ' . count($all_posts), 'image-seo');
        \SEOAutoFix_Debug_Logger::log('Total images to match: ' . count($image_map), 'image-seo');
        $matching_start = microtime(true);
        $matches_found = 0;
        $posts_processed = 0;

        foreach ($all_posts as $post) {
            $posts_processed++;
            if ($posts_processed % 50 == 0) {
                \SEOAutoFix_Debug_Logger::log('Processed ' . $posts_processed . ' posts...', 'image-seo');
            }
            $found_images = array();

            // Check each image to see if it's in this post
            foreach ($image_map as $attachment_id => $filename) {
                $is_matched = false;
                $match_type = '';

                // Check 1: Is it the featured image?
                if (isset($featured_map[$post->ID]) && $featured_map[$post->ID] == $attachment_id) {
                    $is_matched = true;
                    $match_type = 'featured';
                }

                // Check 2: Is it in post content? (regular images, backgrounds, Gutenberg blocks)
                if (!$is_matched && strpos($post->post_content, $filename) !== false) {
                    $is_matched = true;
                    $match_type = 'content';
                }

                // Check 2b: Gutenberg block with attachment ID
                if (!$is_matched && strpos($post->post_content, '"id":' . $attachment_id) !== false) {
                    $is_matched = true;
                    $match_type = 'gutenberg_block';
                }

                // Check 3: REMOVED - Elementor matching moved to frontend
                // Frontend will handle this check using JavaScript

                // If matched, add to this image's results
                if ($is_matched) {
                    $matches_found++;
                    $found_images[] = array(
                        'attachment_id' => $attachment_id,
                        'match_type' => $match_type
                    );

                    $results[$attachment_id]['pages'][] = array(
                        'post_id' => $post->ID,
                        'title' => $post->post_title,
                        'type' => $post->post_type,
                        'match_type' => $match_type
                    );
                    $results[$attachment_id]['total_uses']++;
                }
            }
        }

        \SEOAutoFix_Debug_Logger::log('Matching complete! Processed ' . $posts_processed . ' posts', 'image-seo');
        \SEOAutoFix_Debug_Logger::log('Total matches found: ' . $matches_found, 'image-seo');

        $matching_elapsed = microtime(true) - $matching_start;
        \SEOAutoFix_Debug_Logger::log('Matching elapsed: ' . number_format($matching_elapsed, 3) . 's', 'image-seo');
        \SEOAutoFix_Debug_Logger::log('⏱️ [USAGE-TRACKER] Matching completed in ' . number_format($matching_elapsed, 3) . 's - ' . $matches_found . ' matches');

        \SEOAutoFix_Debug_Logger::log('Preparing final results...', 'image-seo');
        // Calculate totals and remove duplicates
        foreach ($results as $attachment_id => &$data) {
            $data['pages'] = $this->remove_duplicate_pages($data['pages']);
            // $data['total_uses'] = count($data['pages']); // total_uses is incremented directly now
        }

        $total_time = microtime(true) - $start_time;
        \SEOAutoFix_Debug_Logger::log('BATCH COMPLETE! Total time: ' . number_format($total_time, 3) . 's', 'image-seo');
        \SEOAutoFix_Debug_Logger::log('Returning ' . count($results) . ' results', 'image-seo');
        \SEOAutoFix_Debug_Logger::log('✅ [USAGE-TRACKER] ===== BATCH USAGE END =====');

        return $results;
    }

    /**
     * Scan post content for image usage
     *
     * @param int $attachment_id The attachment ID
     * @return array Array of pages using this image
     */
    public function scan_post_content($attachment_id)
    {
        global $wpdb;

        $file_url = wp_get_attachment_url($attachment_id);
        $filename = basename($file_url);
        $pages = array();

        // Search for posts containing this image
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_content, post_type 
            FROM {$wpdb->posts} 
            WHERE post_status = 'publish' 
            AND post_content LIKE %s
            LIMIT 10",
            '%' . $wpdb->esc_like($filename) . '%'
        ));

        foreach ($posts as $post) {
            $pages[] = array(
                'post_id' => $post->ID,
                'title' => $post->post_title,
                'url' => get_permalink($post->ID),
                'type' => $post->post_type,
                'h1' => $this->extract_h1($post->post_content),
                'surrounding_content' => $this->get_surrounding_text($post->post_content, $filename)
            );
        }

        return $pages;
    }

    /**
     * Scan for featured image usage
     *
     * @param int $attachment_id The attachment ID
     * @return array Array of pages using this as featured image
     */
    public function scan_featured_images($attachment_id)
    {
        global $wpdb;

        $pages = array();

        // Find posts where this is the featured image
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_content, p.post_type 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_thumbnail_id'
            AND pm.meta_value = %d
            AND p.post_status = 'publish'
            LIMIT 10",
            $attachment_id
        ));

        foreach ($posts as $post) {
            $pages[] = array(
                'post_id' => $post->ID,
                'title' => $post->post_title,
                'url' => get_permalink($post->ID),
                'type' => $post->post_type,
                'h1' => $this->extract_h1($post->post_content),
                'surrounding_content' => wp_trim_words($post->post_content, 50, '...')
            );
        }

        return $pages;
    }

    /**
     * Scan for background image CSS usage
     * Detects images used as backgrounds via inline styles, blocks, page builders
     *
     * @param int $attachment_id The attachment ID
     * @return array Array of pages using this as background image
     */
    public function scan_background_images($attachment_id)
    {
        global $wpdb;

        $file_url = wp_get_attachment_url($attachment_id);
        $filename = basename($file_url);
        $pages = array();
        $found_post_ids = array(); // Track to avoid duplicates

        // Pattern 1: Search for background-image CSS in post content
        // Matches: background-image: url('filename.jpg'), background: url(filename.jpg)
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_content, post_type 
            FROM {$wpdb->posts} 
            WHERE post_status = 'publish' 
            AND (
                post_content LIKE %s 
                OR post_content LIKE %s
                OR post_content LIKE %s
            )
            LIMIT 50",
            '%background-image:%' . $wpdb->esc_like($filename) . '%',
            '%background:%url%' . $wpdb->esc_like($filename) . '%',
            '%style=%background%' . $wpdb->esc_like($filename) . '%'
        ));

        foreach ($posts as $post) {
            // Verify it's actually in a background-image style (not just text)
            if (preg_match('/background(-image)?:\s*url\([\'"]?[^\'\")]*' . preg_quote($filename, '/') . '/i', $post->post_content)) {
                if (!in_array($post->ID, $found_post_ids)) {
                    $pages[] = array(
                        'post_id' => $post->ID,
                        'title' => $post->post_title,
                        'url' => get_permalink($post->ID),
                        'type' => $post->post_type,
                        'h1' => $this->extract_h1($post->post_content),
                        'surrounding_content' => wp_trim_words($post->post_content, 50, '...')
                    );
                    $found_post_ids[] = $post->ID;
                }
            }
        }

        // Pattern 2: Gutenberg blocks with backgroundImage or background attributes
        // Matches blocks like: {"backgroundImage":{"id":123}}
        $gutenberg_posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_content, post_type 
            FROM {$wpdb->posts} 
            WHERE post_status = 'publish' 
            AND (
                post_content LIKE %s
                OR post_content LIKE %s
            )
            LIMIT 50",
            '%backgroundImage%' . $wpdb->esc_like($attachment_id) . '%',
            '%\"id\":' . $wpdb->esc_like($attachment_id) . '%'
        ));

        foreach ($gutenberg_posts as $post) {
            if (!in_array($post->ID, $found_post_ids)) {
                // Verify attachment ID is in block attributes
                if (
                    strpos($post->post_content, '"id":' . $attachment_id) !== false ||
                    strpos($post->post_content, $filename) !== false
                ) {
                    $pages[] = array(
                        'post_id' => $post->ID,
                        'title' => $post->post_title,
                        'url' => get_permalink($post->ID),
                        'type' => $post->post_type,
                        'h1' => $this->extract_h1($post->post_content),
                        'surrounding_content' => wp_trim_words($post->post_content, 50, '...')
                    );
                    $found_post_ids[] = $post->ID;
                }
            }
        }

        // Pattern 3: Elementor data (stored in postmeta)
        // Elementor stores page data in _elementor_data meta field
        $elementor_posts = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_content, p.post_type 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_status = 'publish'
            AND pm.meta_key = '_elementor_data'
            AND (
                pm.meta_value LIKE %s
                OR pm.meta_value LIKE %s
            )
            LIMIT 50",
            '%' . $wpdb->esc_like($filename) . '%',
            '%\"id\":\"' . $wpdb->esc_like($attachment_id) . '\"%'
        ));

        foreach ($elementor_posts as $post) {
            if (!in_array($post->ID, $found_post_ids)) {
                $pages[] = array(
                    'post_id' => $post->ID,
                    'title' => $post->post_title,
                    'url' => get_permalink($post->ID),
                    'type' => $post->post_type,
                    'h1' => $this->extract_h1($post->post_content),
                    'surrounding_content' => 'Background image (Elementor)'
                );
                $found_post_ids[] = $post->ID;
            }
        }

        // Pattern 4: ACF (Advanced Custom Fields) and other meta fields
        // Search for attachment ID in all postmeta
        $acf_posts = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_content, p.post_type, pm.meta_key
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_status = 'publish'
            AND pm.meta_value = %s
            AND pm.meta_key NOT IN ('_thumbnail_id', '_elementor_data')
            LIMIT 50",
            $attachment_id
        ));

        foreach ($acf_posts as $post) {
            if (!in_array($post->ID, $found_post_ids)) {
                $pages[] = array(
                    'post_id' => $post->ID,
                    'title' => $post->post_title,
                    'url' => get_permalink($post->ID),
                    'type' => $post->post_type,
                    'h1' => $this->extract_h1($post->post_content),
                    'surrounding_content' => 'Background image (Custom field: ' . $post->meta_key . ')'
                );
                $found_post_ids[] = $post->ID;
            }
        }

        return $pages;
    }

    /**
     * Extract H1 from content
     *
     * @param string $content The post content
     * @return string The H1 text or empty string
     */
    private function extract_h1($content)
    {
        // Try to find H1 in content
        preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $content, $matches);

        if (!empty($matches[1])) {
            return wp_strip_all_tags($matches[1]);
        }

        return '';
    }

    /**
     * Get text surrounding the image
     *
     * @param string $content The post content
     * @param string $filename The image filename
     * @return string Surrounding text
     */
    private function get_surrounding_text($content, $filename)
    {
        $pos = strpos($content, $filename);

        if ($pos === false) {
            return wp_trim_words($content, 50, '...');
        }

        // Get 250 chars before and after
        $start = max(0, $pos - 250);
        $excerpt = substr($content, $start, 500);

        // Strip HTML
        $excerpt = wp_strip_all_tags($excerpt);

        // Limit to 50 words
        return wp_trim_words($excerpt, 50, '...');
    }

    /**
     * Remove duplicate pages from array
     *
     * @param array $pages Array of page data
     * @return array Deduplicated array
     */
    private function remove_duplicate_pages($pages)
    {
        $seen = array();
        $unique = array();

        foreach ($pages as $page) {
            $id = $page['post_id'];

            if (!in_array($id, $seen)) {
                $seen[] = $id;
                $unique[] = $page;
            }
        }

        return $unique;
    }

    /**
     * Format usage data for display
     *
     * @param int $attachment_id The attachment ID
     * @return string Formatted usage string
     */
    public function format_usage_data($attachment_id)
    {
        $usage = $this->get_image_usage($attachment_id);

        if ($usage['total_uses'] === 0) {
            return 'Not used on any pages';
        }

        if ($usage['total_uses'] === 1) {
            return 'Used on: ' . $usage['pages'][0]['title'];
        }

        return sprintf('Used on %d pages', $usage['total_uses']);
    }
}
