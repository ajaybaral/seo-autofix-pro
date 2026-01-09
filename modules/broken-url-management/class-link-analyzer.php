<?php
/**
 * Link Analyzer - Applies fixes to broken links
 * 
 * @package SEOAutoFix\BrokenUrlManagement
 */

namespace SEOAutoFix\BrokenUrlManagement;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Link Analyzer Class
 */
class Link_Analyzer {
    
    /**
     * Database manager
     */
    private $db_manager;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db_manager = new Database_Manager();
    }
    
    /**
     * Apply fixes for selected broken links
     * 
     * @param array $entry_ids Entry IDs to fix
     * @return array Result with counts
     */
    public function apply_fixes($entry_ids) {
        $fixed_count = 0;
        $failed_count = 0;
        $messages = array();
        
        foreach ($entry_ids as $entry_id) {
            // Get entry details
            $entry = $this->db_manager->get_entry($entry_id);
            
            if (!$entry) {
                $failed_count++;
                $messages[] = sprintf(
                    __('Entry #%d not found', 'seo-autofix-pro'),
                    $entry_id
                );
                continue;
            }
            
            // Skip external links (require manual intervention)
            if ($entry['link_type'] === 'external') {
                $failed_count++;
                $messages[] = sprintf(
                    __('Skipped external link: %s (manual intervention required)', 'seo-autofix-pro'),
                    esc_url($entry['broken_url'])
                );
                continue;
            }
            
            // Determine replacement URL (user modified or suggested)
            $replacement_url = !empty($entry['user_modified_url']) 
                ? $entry['user_modified_url'] 
                : $entry['suggested_url'];
            
            if (empty($replacement_url)) {
                $failed_count++;
                $messages[] = sprintf(
                    __('No replacement URL for: %s', 'seo-autofix-pro'),
                    esc_url($entry['broken_url'])
                );
                continue;
            }
            
            // Apply fix to the content
            $success = $this->replace_link_in_content(
                $entry['found_on_url'],
                $entry['broken_url'],
                $replacement_url
            );
            
            if ($success) {
                $fixed_count++;
                $this->db_manager->mark_as_fixed($entry_id);
                $messages[] = sprintf(
                    __('Fixed: %s → %s', 'seo-autofix-pro'),
                    esc_url($entry['broken_url']),
                    esc_url($replacement_url)
                );
            } else {
                $failed_count++;
                $messages[] = sprintf(
                    __('Failed to fix: %s', 'seo-autofix-pro'),
                    esc_url($entry['broken_url'])
                );
            }
        }
        
        return array(
            'fixed_count' => $fixed_count,
            'failed_count' => $failed_count,
            'messages' => $messages
        );
    }
    
    /**
     * Replace link in post/page content
     * 
     * @param string $page_url Page where link was found
     * @param string $broken_url Broken URL to replace
     * @param string $replacement_url New URL
     * @return bool Success
     */
    private function replace_link_in_content($page_url, $broken_url, $replacement_url) {
        // Get post ID from URL
        $post_id = url_to_postid($page_url);
        
        if (!$post_id) {
            return false;
        }
        
        // Get post content
        $post = get_post($post_id);
        
        if (!$post) {
            return false;
        }
        
        $content = $post->post_content;
        
        // Replace the broken URL with the replacement URL
        // Need to handle both href="" and src="" attributes
        $patterns = array(
            '/href=["\']' . preg_quote($broken_url, '/') . '["\']/i',
            '/src=["\']' . preg_quote($broken_url, '/') . '["\']/i',
        );
        
        $replacements = array(
            'href="' . esc_url($replacement_url) . '"',
            'src="' . esc_url($replacement_url) . '"',
        );
        
        $new_content = preg_replace($patterns, $replacements, $content);
        
        // Check if replacement was made
        if ($new_content === $content) {
            // No changes made, link might not be in content
            return false;
        }
        
        // Update post
        $result = wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $new_content
        ), true);
        
        return !is_wp_error($result);
    }
    
    /**
     * Get statistics for scan results
     * 
     * @param string $scan_id Scan ID
     * @return array Statistics
     */
    public function get_scan_statistics($scan_id) {
        global $wpdb;
        
        $table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';
        
        $stats = array(
            'total_broken' => 0,
            'internal_broken' => 0,
            'external_broken' => 0,
            'fixed' => 0,
            'pending' => 0
        );
        
        // Get total broken links
        $stats['total_broken'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_results} 
                WHERE scan_id = %s AND is_deleted = 0",
                $scan_id
            )
        );
        
        // Get internal broken links
        $stats['internal_broken'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_results} 
                WHERE scan_id = %s AND is_deleted = 0 AND link_type = 'internal'",
                $scan_id
            )
        );
        
        // Get external broken links
        $stats['external_broken'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_results} 
                WHERE scan_id = %s AND is_deleted = 0 AND link_type = 'external'",
                $scan_id
            )
        );
        
        // Get fixed links
        $stats['fixed'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_results} 
                WHERE scan_id = %s AND is_fixed = 1",
                $scan_id
            )
        );
        
        // Get pending links
        $stats['pending'] = $stats['total_broken'] - $stats['fixed'];
        
        return $stats;
    }
}
