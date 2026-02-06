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
            return array();
        }

        error_log('🔍 [USAGE-TRACKER] ===== BATCH USAGE START =====');
        error_log('🔍 [USAGE-TRACKER] Processing ' . count($attachment_ids) . ' images');
        $start_time = microtime(true);

        global $wpdb;
        
        // Build filename mapping for quick lookups
        $image_map = array(); // attachment_id => filename
        foreach ($attachment_ids as $id) {
            $url = wp_get_attachment_url($id);
            if ($url) {
                $image_map[$id] = basename($url);
            }
        }

        error_log('🔍 [USAGE-TRACKER] Built filename map for ' . count($image_map) . ' images');

        // Initialize results
        $results = array();
        foreach ($attachment_ids as $id) {
            $results[$id] = array(
                'attachment_id' => $id,
                'pages' => array(),
                'total_uses' => 0
            );
        }

        // QUERY 1: Get ALL published posts/pages content at once
        $query_start = microtime(true);
        $all_posts = $wpdb->get_results("
            SELECT ID, post_title, post_content, post_type 
            FROM {$wpdb->posts} 
            WHERE post_status = 'publish' 
            AND post_type IN ('post', 'page', 'elementor_library')
        ");
        $query1_time = microtime(true) - $query_start;
        error_log('⏱️ [USAGE-TRACKER] Query 1 (all posts): ' . number_format($query1_time, 3) . 's - ' . count($all_posts) . ' posts');

        // QUERY 2: Get ALL featured images at once
        $query_start = microtime(true);
        $featured_images = $wpdb->get_results("
            SELECT post_id, meta_value as attachment_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_thumbnail_id'
            AND meta_value IN (" . implode(',', array_filter($attachment_ids, 'is_numeric')) . ")
        ");
        $query2_time = microtime(true) - $query_start;
        error_log('⏱️ [USAGE-TRACKER] Query 2 (featured images): ' . number_format($query2_time, 3) . 's - ' . count($featured_images) . ' matches');

        // QUERY 3: Get ALL Elementor data at once
        $query_start = microtime(true);
        $elementor_data = $wpdb->get_results("
            SELECT post_id, meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_elementor_data'
        ");
        $query3_time = microtime(true) - $query_start;
        error_log('⏱️ [USAGE-TRACKER] Query 3 (elementor data): ' . number_format($query3_time, 3) . 's - ' . count($elementor_data) . ' posts');

        // Process featured images
        $featured_map = array(); // post_id => attachment_id
        foreach ($featured_images as $row) {
            $featured_map[$row->post_id] = intval($row->meta_value);
        }

        // Process Elementor data (build searchable index)
        $elementor_map = array(); // post_id => elementor JSON string
        foreach ($elementor_data as $row) {
            $elementor_map[$row->post_id] = $row->meta_value;
        }

        // Now match images against posts
        $matching_start = microtime(true);
        $matches_found = 0;

        foreach ($all_posts as $post) {
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

                // Check 3: Is it in Elementor data?
                if (!$is_matched && isset($elementor_map[$post->ID])) {
                    if (strpos($elementor_map[$post->ID], $filename) !== false || 
                        strpos($elementor_map[$post->ID], '"id":"' . $attachment_id . '"') !== false) {
                        $is_matched = true;
                        $match_type = 'elementor';
                    }
                }

                // If matched, add to this image's results
                if ($is_matched) {
                    $results[$attachment_id]['pages'][] = array(
                        'post_id' => $post->ID,
                        'title' => $post->post_title,
                        'url' => get_permalink($post->ID),
                        'type' => $post->post_type,
                        'match_type' => $match_type
                    );
                    $matches_found++;
                    $found_images[] = $attachment_id;
                }
            }
        }

        $matching_time = microtime(true) - $matching_start;
        error_log('⏱️ [USAGE-TRACKER] Matching took: ' . number_format($matching_time, 3) . 's - ' . $matches_found . ' matches found');

        // Calculate totals and remove duplicates
        foreach ($results as $attachment_id => &$data) {
            $data['pages'] = $this->remove_duplicate_pages($data['pages']);
            $data['total_uses'] = count($data['pages']);
        }

        $total_time = microtime(true) - $start_time;
        error_log('⏱️ [USAGE-TRACKER] TOTAL TIME: ' . number_format($total_time, 3) . 's');
        error_log('✅ [USAGE-TRACKER] ===== BATCH USAGE END =====');

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
