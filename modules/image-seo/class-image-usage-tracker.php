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
