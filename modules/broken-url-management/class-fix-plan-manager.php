<?php
/**
 * Fix Plan Manager - Manages fix plan review queue
 * 
 * @package SEOAutoFix\BrokenUrlManagement
 */

namespace SEOAutoFix\BrokenUrlManagement;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fix Plan Manager Class
 * Handles Generate → Review → Apply workflow
 */
class Fix_Plan_Manager
{

    /**
     * Database manager
     */
    private $db_manager;

    /**
     * History manager
     */
    private $history_manager;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db_manager = new Database_Manager();
        $this->history_manager = new History_Manager();
    }

    /**
     * Generate fix plan from selected broken links
     * 
     * @param array $entry_ids Array of broken link entry IDs
     * @return array Fix plan with all proposed changes
     */
    public function generate_fix_plan($entry_ids)
    {
        if (empty($entry_ids)) {
            return array(
                'success' => false,
                'message' => __('No entries selected', 'seo-autofix-pro')
            );
        }

        $fix_plan = array();
        $plan_id = 'plan_' . uniqid();

        foreach ($entry_ids as $entry_id) {
            $entry = $this->db_manager->get_entry($entry_id);

            if (!$entry) {
                continue;
            }

            // Determine fix action
            $fix_action = $this->determine_fix_action($entry);

            $fix_plan[] = array(
                'entry_id' => $entry_id,
                'found_on_page_id' => $entry['found_on_page_id'],
                'found_on_page_title' => $entry['found_on_page_title'],
                'found_on_url' => $entry['found_on_url'],
                'link_location' => $entry['link_location'],
                'anchor_text' => $entry['anchor_text'],
                'broken_url' => $entry['broken_url'],
                'link_type' => $entry['link_type'],
                'status_code' => $entry['status_code'],
                'suggested_url' => $entry['suggested_url'],
                'user_modified_url' => $entry['user_modified_url'],
                'fix_action' => $fix_action,
                'new_url' => $entry['user_modified_url'] ?: $entry['suggested_url'],
                'can_edit' => true
            );
        }

        // Store fix plan in transient (temporary storage)
        set_transient('seoautofix_fix_plan_' . $plan_id, $fix_plan, HOUR_IN_SECONDS);

        return array(
            'success' => true,
            'plan_id' => $plan_id,
            'fix_plan' => $fix_plan,
            'total_fixes' => count($fix_plan)
        );
    }

    /**
     * Determine fix action for an entry
     * 
     * @param array $entry Broken link entry
     * @return string Fix action (replace, remove, redirect)
     */
    private function determine_fix_action($entry)
    {
        // External links can only be removed
        if ($entry['link_type'] === 'external') {
            return 'remove';
        }

        // If we have a suggested or user-modified URL, replace
        if (!empty($entry['user_modified_url']) || !empty($entry['suggested_url'])) {
            return 'replace';
        }

        // Default to remove if no suggestion
        return 'remove';
    }

    /**
     * Get fix plan from storage
     * 
     * @param string $plan_id Plan ID
     * @return array|false Fix plan or false if not found
     */
    public function get_fix_plan($plan_id)
    {
        return get_transient('seoautofix_fix_plan_' . $plan_id);
    }

    /**
     * Update a specific entry in the fix plan
     * 
     * @param string $plan_id Plan ID
     * @param int $entry_id Entry ID
     * @param string $new_url New URL
     * @param string $fix_action Fix action
     * @return array Result
     */
    public function update_fix_plan_entry($plan_id, $entry_id, $new_url, $fix_action)
    {
        $fix_plan = $this->get_fix_plan($plan_id);

        if (!$fix_plan) {
            return array(
                'success' => false,
                'message' => __('Fix plan not found or expired', 'seo-autofix-pro')
            );
        }

        // Find and update entry
        $updated = false;
        foreach ($fix_plan as &$item) {
            if ($item['entry_id'] == $entry_id) {
                $item['new_url'] = $new_url;
                $item['fix_action'] = $fix_action;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            return array(
                'success' => false,
                'message' => __('Entry not found in fix plan', 'seo-autofix-pro')
            );
        }

        // Save updated plan
        set_transient('seoautofix_fix_plan_' . $plan_id, $fix_plan, HOUR_IN_SECONDS);

        return array(
            'success' => true,
            'message' => __('Fix plan updated', 'seo-autofix-pro')
        );
    }

    /**
     * Apply fix plan (execute selected fixes)
     * 
     * @param string $plan_id Plan ID
     * @param array $selected_entry_ids Optional: only apply specific entries
     * @return array Result with statistics
     */
    public function apply_fix_plan($plan_id, $selected_entry_ids = array())
    {
        $fix_plan = $this->get_fix_plan($plan_id);

        if (!$fix_plan) {
            return array(
                'success' => false,
                'message' => __('Fix plan not found or expired', 'seo-autofix-pro')
            );
        }

        // Filter to selected entries if specified
        if (!empty($selected_entry_ids)) {
            $fix_plan = array_filter($fix_plan, function ($item) use ($selected_entry_ids) {
                return in_array($item['entry_id'], $selected_entry_ids);
            });
        }

        $fix_session_id = 'session_' . uniqid();
        $fixed_count = 0;
        $failed_count = 0;
        $removed_count = 0;
        $messages = array();

        // Group by page to minimize database updates
        $pages_to_fix = array();
        foreach ($fix_plan as $item) {
            $page_id = $item['found_on_page_id'];
            if (!isset($pages_to_fix[$page_id])) {
                $pages_to_fix[$page_id] = array();
            }
            $pages_to_fix[$page_id][] = $item;
        }

        // Apply fixes page by page
        foreach ($pages_to_fix as $page_id => $page_fixes) {
            $result = $this->apply_page_fixes($page_id, $page_fixes, $fix_session_id);

            if ($result['success']) {
                $fixed_count += $result['fixed'];
                $removed_count += $result['removed'];

                $page_title = $page_fixes[0]['found_on_page_title'];
                $messages[] = sprintf(
                    __('Applied %d fix(es) on page: %s', 'seo-autofix-pro'),
                    $result['fixed'] + $result['removed'],
                    $page_title
                );
            } else {
                $failed_count += count($page_fixes);
                $messages[] = $result['message'];
            }
        }

        // Clean up fix plan
        delete_transient('seoautofix_fix_plan_' . $plan_id);

        return array(
            'success' => $fixed_count > 0 || $removed_count > 0,
            'fix_session_id' => $fix_session_id,
            'fixed_count' => $fixed_count,
            'removed_count' => $removed_count,
            'failed_count' => $failed_count,
            'total_pages' => count($pages_to_fix),
            'messages' => $messages
        );
    }

    /**
     * Apply fixes to a specific page
     * 
     * @param int $page_id WordPress post/page ID
     * @param array $fixes Fixes to apply
     * @param string $fix_session_id Fix session ID
     * @return array Result
     */
    private function apply_page_fixes($page_id, $fixes, $fix_session_id)
    {
        if ($page_id == 0) {
            return array(
                'success' => false,
                'message' => __('Cannot edit homepage automatically', 'seo-autofix-pro')
            );
        }

        $post = get_post($page_id);
        if (!$post) {
            return array(
                'success' => false,
                'message' => __('Page not found', 'seo-autofix-pro')
            );
        }

        $original_content = $post->post_content;
        $modified_content = $original_content;

        $fixed = 0;
        $removed = 0;
        $fixes_applied = array();

        // Apply each fix
        foreach ($fixes as $fix) {
            $broken_url = $fix['broken_url'];
            $new_url = $fix['new_url'];
            $fix_action = $fix['fix_action'];

            if ($fix_action === 'replace' && !empty($new_url)) {
                // Replace broken URL with new URL
                $patterns = array(
                    '/href=["\']' . preg_quote($broken_url, '/') . '["\']/i',
                    '/src=["\']' . preg_quote($broken_url, '/') . '["\']/i',
                );

                $replacements = array(
                    'href="' . esc_url($new_url) . '"',
                    'src="' . esc_url($new_url) . '"',
                );

                $modified_content = preg_replace($patterns, $replacements, $modified_content);
                $fixed++;

                $fixes_applied[] = array(
                    'entry_id' => $fix['entry_id'],
                    'action' => 'replace',
                    'old_url' => $broken_url,
                    'new_url' => $new_url
                );

            } elseif ($fix_action === 'remove') {
                // Remove the entire link (keep text, remove <a> tag)
                $pattern = '/<a[^>]+href=["\']' . preg_quote($broken_url, '/') . '["\'][^>]*>(.*?)<\/a>/is';
                $modified_content = preg_replace($pattern, '$1', $modified_content);
                $removed++;

                $fixes_applied[] = array(
                    'entry_id' => $fix['entry_id'],
                    'action' => 'remove',
                    'old_url' => $broken_url,
                    'new_url' => null
                );
            }
        }

        // Check if any changes were made
        if ($modified_content === $original_content) {
            return array(
                'success' => false,
                'message' => __('No changes could be applied', 'seo-autofix-pro')
            );
        }

        // Create backup before applying
        $this->history_manager->create_backup(
            $fix_session_id,
            $fixes[0]['entry_id'], // Use first entry's scan_id
            $page_id,
            $original_content,
            $modified_content,
            $fixes_applied
        );

        // Update post
        $result = wp_update_post(array(
            'ID' => $page_id,
            'post_content' => $modified_content
        ), true);

        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message()
            );
        }

        // Mark entries as fixed in database
        global $wpdb;
        $table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';

        foreach ($fixes as $fix) {
            $wpdb->update(
                $table_results,
                array(
                    'user_modified_url' => $fix['new_url'],
                    'fix_type' => $fix['fix_action'],
                    'is_fixed' => 1
                ),
                array('id' => $fix['entry_id']),
                array('%s', '%s', '%d'),
                array('%d')
            );
        }

        return array(
            'success' => true,
            'fixed' => $fixed,
            'removed' => $removed
        );
    }
}
