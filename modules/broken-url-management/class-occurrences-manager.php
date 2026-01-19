<?php
/**
 * Occurrences Manager - Groups and manages duplicate broken links
 * 
 * @package SEOAutoFix\BrokenUrlManagement
 */

namespace SEOAutoFix\BrokenUrlManagement;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Occurrences Manager Class
 * Handles grouping of same broken URLs found on multiple pages
 */
class Occurrences_Manager
{

    /**
     * Database manager
     */
    private $db_manager;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db_manager = new Database_Manager();
    }

    /**
     * Group broken links by URL and count occurrences
     * 
     * @param string $scan_id Scan ID
     * @return array Grouped results with occurrence counts
     */
    public function group_by_broken_url($scan_id)
    {
        global $wpdb;

        $table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';

        // Group by broken_url and count occurrences
        $grouped = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    broken_url,
                    link_type,
                    status_code,
                    error_type,
                    COUNT(*) as occurrence_count,
                    MIN(id) as first_id,
                    GROUP_CONCAT(found_on_page_title SEPARATOR '|||') as page_titles,
                    GROUP_CONCAT(id SEPARATOR ',') as entry_ids
                FROM {$table_results}
                WHERE scan_id = %s AND is_deleted = 0
                GROUP BY broken_url, link_type, status_code
                ORDER BY occurrence_count DESC, broken_url ASC",
                $scan_id
            ),
            ARRAY_A
        );

        // Format results
        $results = array();
        foreach ($grouped as $group) {
            $results[] = array(
                'broken_url' => $group['broken_url'],
                'link_type' => $group['link_type'],
                'status_code' => (int) $group['status_code'],
                'error_type' => $group['error_type'],
                'occurrence_count' => (int) $group['occurrence_count'],
                'first_id' => (int) $group['first_id'],
                'page_titles' => explode('|||', $group['page_titles']),
                'entry_ids' => array_map('intval', explode(',', $group['entry_ids']))
            );
        }

        return $results;
    }

    /**
     * Get all occurrences of a specific broken URL
     * 
     * @param string $broken_url Broken URL
     * @param string $scan_id Scan ID
     * @return array All pages where this URL appears
     */
    public function get_occurrences($broken_url, $scan_id)
    {
        global $wpdb;

        $table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';

        $occurrences = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    id,
                    found_on_page_id,
                    found_on_page_title,
                    found_on_url,
                    broken_url,
                    link_location,
                    anchor_text,
                    link_context,
                    link_type,
                    status_code,
                    error_type,
                    suggested_url,
                    user_modified_url,
                    reason,
                    is_fixed
                FROM {$table_results}
                WHERE scan_id = %s 
                    AND broken_url = %s 
                    AND is_deleted = 0
                ORDER BY found_on_page_title ASC",
                $scan_id,
                $broken_url
            ),
            ARRAY_A
        );

        return $occurrences;
    }

    /**
     * Bulk fix all occurrences of a broken URL
     * 
     * @param string $broken_url Broken URL to fix
     * @param string $replacement_url New URL
     * @param string $scan_id Scan ID
     * @param array $occurrence_ids Optional: specific occurrence IDs to fix
     * @return array Result with counts
     */
    public function bulk_fix_occurrences($broken_url, $replacement_url, $scan_id, $occurrence_ids = array())
    {
        global $wpdb;

        $table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';

        // Get occurrences to fix
        if (!empty($occurrence_ids)) {
            // Fix only specific occurrences
            $placeholders = implode(',', array_fill(0, count($occurrence_ids), '%d'));
            $query = $wpdb->prepare(
                "SELECT * FROM {$table_results} 
                WHERE id IN ($placeholders) 
                    AND broken_url = %s 
                    AND scan_id = %s 
                    AND is_deleted = 0",
                array_merge($occurrence_ids, array($broken_url, $scan_id))
            );
        } else {
            // Fix all occurrences
            $query = $wpdb->prepare(
                "SELECT * FROM {$table_results} 
                WHERE broken_url = %s 
                    AND scan_id = %s 
                    AND is_deleted = 0",
                $broken_url,
                $scan_id
            );
        }

        $occurrences = $wpdb->get_results($query, ARRAY_A);

        $fixed_count = 0;
        $failed_count = 0;
        $messages = array();

        // Group by page to avoid multiple updates to same page
        $pages_to_fix = array();
        foreach ($occurrences as $occurrence) {
            $page_id = $occurrence['found_on_page_id'];
            if (!isset($pages_to_fix[$page_id])) {
                $pages_to_fix[$page_id] = array();
            }
            $pages_to_fix[$page_id][] = $occurrence;
        }

        // Fix each page
        foreach ($pages_to_fix as $page_id => $page_occurrences) {
            $success = $this->fix_page_occurrences($page_id, $broken_url, $replacement_url, $page_occurrences);

            if ($success) {
                $fixed_count += count($page_occurrences);

                // Mark as fixed in database
                foreach ($page_occurrences as $occ) {
                    $wpdb->update(
                        $table_results,
                        array(
                            'user_modified_url' => $replacement_url,
                            'is_fixed' => 1
                        ),
                        array('id' => $occ['id']),
                        array('%s', '%d'),
                        array('%d')
                    );
                }

                $page_title = $page_occurrences[0]['found_on_page_title'];
                $messages[] = sprintf(
                    __('Fixed %d occurrence(s) on page: %s', 'seo-autofix-pro'),
                    count($page_occurrences),
                    $page_title
                );
            } else {
                $failed_count += count($page_occurrences);
                $page_title = $page_occurrences[0]['found_on_page_title'];
                $messages[] = sprintf(
                    __('Failed to fix %d occurrence(s) on page: %s', 'seo-autofix-pro'),
                    count($page_occurrences),
                    $page_title
                );
            }
        }

        return array(
            'success' => $fixed_count > 0,
            'fixed_count' => $fixed_count,
            'failed_count' => $failed_count,
            'total_pages' => count($pages_to_fix),
            'messages' => $messages
        );
    }

    /**
     * Fix all occurrences of broken URL on a specific page
     * 
     * @param int $page_id WordPress post/page ID
     * @param string $broken_url Broken URL
     * @param string $replacement_url New URL
     * @param array $occurrences Occurrence details
     * @return bool Success
     */
    private function fix_page_occurrences($page_id, $broken_url, $replacement_url, $occurrences)
    {
        if ($page_id == 0) {
            // Homepage - can't easily edit
            return false;
        }

        $post = get_post($page_id);
        if (!$post) {
            return false;
        }

        $content = $post->post_content;
        $original_content = $content;

        // Replace all occurrences of broken URL
        $patterns = array(
            '/href=["\']' . preg_quote($broken_url, '/') . '["\']/i',
            '/src=["\']' . preg_quote($broken_url, '/') . '["\']/i',
        );

        $replacements = array(
            'href="' . esc_url($replacement_url) . '"',
            'src="' . esc_url($replacement_url) . '"',
        );

        $content = preg_replace($patterns, $replacements, $content);

        // Check if any changes were made
        if ($content === $original_content) {
            return false;
        }

        // Update post
        $result = wp_update_post(array(
            'ID' => $page_id,
            'post_content' => $content
        ), true);

        return !is_wp_error($result);
    }

    /**
     * Get occurrence statistics for a scan
     * 
     * @param string $scan_id Scan ID
     * @return array Statistics
     */
    public function get_occurrence_stats($scan_id)
    {
        global $wpdb;

        $table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';

        // Get total unique broken URLs
        $unique_urls = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT broken_url) 
                FROM {$table_results} 
                WHERE scan_id = %s AND is_deleted = 0",
                $scan_id
            )
        );

        // Get total broken link instances
        $total_instances = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) 
                FROM {$table_results} 
                WHERE scan_id = %s AND is_deleted = 0",
                $scan_id
            )
        );

        // Get URLs with multiple occurrences
        $multi_occurrence_urls = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT broken_url, COUNT(*) as cnt
                    FROM {$table_results}
                    WHERE scan_id = %s AND is_deleted = 0
                    GROUP BY broken_url
                    HAVING cnt > 1
                ) as multi",
                $scan_id
            )
        );

        return array(
            'unique_broken_urls' => (int) $unique_urls,
            'total_instances' => (int) $total_instances,
            'multi_occurrence_urls' => (int) $multi_occurrence_urls,
            'average_occurrences' => $unique_urls > 0 ? round($total_instances / $unique_urls, 2) : 0
        );
    }
}
