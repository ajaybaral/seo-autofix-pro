<?php
/**
 * History Manager - Manages fix history and revert functionality
 * 
 * @package SEOAutoFix\BrokenUrlManagement
 */

namespace SEOAutoFix\BrokenUrlManagement;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * History Manager Class
 * Handles backup creation and revert functionality
 */
class History_Manager
{

    /**
     * Create backup before applying fixes
     * 
     * @param string $fix_session_id Fix session ID
     * @param string $scan_id Scan ID
     * @param int $page_id WordPress post/page ID
     * @param string $original_content Original content
     * @param string $modified_content Modified content
     * @param array $fixes_applied Array of fixes applied
     * @return int|false Backup ID or false on failure
     */
    public function create_backup($fix_session_id, $scan_id, $page_id, $original_content, $modified_content, $fixes_applied)
    {
        global $wpdb;

        $table_history = $wpdb->prefix . 'seoautofix_broken_links_fixes_history';

        $result = $wpdb->insert(
            $table_history,
            array(
                'fix_session_id' => $fix_session_id,
                'scan_id' => $scan_id,
                'page_id' => $page_id,
                'original_content' => $original_content,
                'modified_content' => $modified_content,
                'fixes_applied' => wp_json_encode($fixes_applied),
                'total_fixes' => count($fixes_applied),
                'is_reverted' => 0,
                'applied_at' => current_time('mysql')
            ),
            array('%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s')
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get fix session details
     * 
     * @param string $fix_session_id Fix session ID
     * @return array Session details
     */
    public function get_fix_session($fix_session_id)
    {
        global $wpdb;

        $table_history = $wpdb->prefix . 'seoautofix_broken_links_fixes_history';

        $history = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_history} 
                WHERE fix_session_id = %s 
                ORDER BY applied_at DESC",
                $fix_session_id
            ),
            ARRAY_A
        );

        if (empty($history)) {
            return array(
                'success' => false,
                'message' => __('Fix session not found', 'seo-autofix-pro')
            );
        }

        // Parse JSON fixes_applied
        foreach ($history as &$item) {
            $item['fixes_applied'] = json_decode($item['fixes_applied'], true);
        }

        return array(
            'success' => true,
            'session_id' => $fix_session_id,
            'history' => $history,
            'total_pages' => count($history),
            'is_reverted' => (bool) $history[0]['is_reverted'],
            'applied_at' => $history[0]['applied_at']
        );
    }

    /**
     * Get revert preview (what will be reverted)
     * 
     * @param string $fix_session_id Fix session ID
     * @return array Preview data
     */
    public function get_revert_preview($fix_session_id)
    {
        $session = $this->get_fix_session($fix_session_id);

        if (!$session['success']) {
            return $session;
        }

        if ($session['is_reverted']) {
            return array(
                'success' => false,
                'message' => __('This session has already been reverted', 'seo-autofix-pro')
            );
        }

        $preview = array();
        foreach ($session['history'] as $item) {
            $post = get_post($item['page_id']);
            $preview[] = array(
                'page_id' => $item['page_id'],
                'page_title' => $post ? $post->post_title : __('Unknown Page', 'seo-autofix-pro'),
                'total_fixes' => $item['total_fixes'],
                'fixes_applied' => $item['fixes_applied'],
                'applied_at' => $item['applied_at']
            );
        }

        return array(
            'success' => true,
            'preview' => $preview,
            'total_pages' => count($preview)
        );
    }

    /**
     * Revert all changes from a fix session
     * 
     * @param string $fix_session_id Fix session ID
     * @return array Result
     */
    public function revert_session($fix_session_id)
    {
        $session = $this->get_fix_session($fix_session_id);

        if (!$session['success']) {
            return $session;
        }

        if ($session['is_reverted']) {
            return array(
                'success' => false,
                'message' => __('This session has already been reverted', 'seo-autofix-pro')
            );
        }

        $reverted_count = 0;
        $failed_count = 0;
        $messages = array();

        foreach ($session['history'] as $item) {
            $result = $this->revert_page($item);

            if ($result['success']) {
                $reverted_count++;
                $messages[] = $result['message'];
            } else {
                $failed_count++;
                $messages[] = $result['message'];
            }
        }

        // Mark session as reverted
        if ($reverted_count > 0) {
            global $wpdb;
            $table_history = $wpdb->prefix . 'seoautofix_broken_links_fixes_history';

            $wpdb->update(
                $table_history,
                array(
                    'is_reverted' => 1,
                    'reverted_at' => current_time('mysql')
                ),
                array('fix_session_id' => $fix_session_id),
                array('%d', '%s'),
                array('%s')
            );

            // Also mark entries as not fixed in scan results
            $this->unmark_fixed_entries($session['history']);
        }

        return array(
            'success' => $reverted_count > 0,
            'reverted_count' => $reverted_count,
            'failed_count' => $failed_count,
            'total_pages' => count($session['history']),
            'messages' => $messages
        );
    }

    /**
     * Revert a single page
     * 
     * @param array $history_item History item
     * @return array Result
     */
    private function revert_page($history_item)
    {
        $page_id = $history_item['page_id'];
        $original_content = $history_item['original_content'];

        $post = get_post($page_id);
        if (!$post) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __('Page ID %d not found', 'seo-autofix-pro'),
                    $page_id
                )
            );
        }

        // Restore original content
        $result = wp_update_post(array(
            'ID' => $page_id,
            'post_content' => $original_content
        ), true);

        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __('Failed to revert page "%s": %s', 'seo-autofix-pro'),
                    $post->post_title,
                    $result->get_error_message()
                )
            );
        }

        return array(
            'success' => true,
            'message' => sprintf(
                __('Reverted %d fix(es) on page: %s', 'seo-autofix-pro'),
                $history_item['total_fixes'],
                $post->post_title
            )
        );
    }

    /**
     * Unmark entries as fixed in scan results
     * 
     * @param array $history History items
     */
    private function unmark_fixed_entries($history)
    {
        global $wpdb;
        $table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';

        foreach ($history as $item) {
            $fixes_applied = $item['fixes_applied'];

            foreach ($fixes_applied as $fix) {
                if (isset($fix['entry_id'])) {
                    $wpdb->update(
                        $table_results,
                        array(
                            'is_fixed' => 0,
                            'user_modified_url' => null,
                            'fix_type' => null
                        ),
                        array('id' => $fix['entry_id']),
                        array('%d', '%s', '%s'),
                        array('%d')
                    );
                }
            }
        }
    }

    /**
     * Get all fix sessions for a scan
     * 
     * @param string $scan_id Scan ID
     * @return array Fix sessions
     */
    public function get_scan_fix_sessions($scan_id)
    {
        global $wpdb;

        $table_history = $wpdb->prefix . 'seoautofix_broken_links_fixes_history';

        $sessions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    fix_session_id,
                    COUNT(*) as pages_affected,
                    SUM(total_fixes) as total_fixes,
                    MAX(is_reverted) as is_reverted,
                    MIN(applied_at) as applied_at,
                    MAX(reverted_at) as reverted_at
                FROM {$table_history}
                WHERE scan_id = %s
                GROUP BY fix_session_id
                ORDER BY applied_at DESC",
                $scan_id
            ),
            ARRAY_A
        );

        return $sessions;
    }

    /**
     * Delete old fix history (cleanup)
     * 
     * @param int $days_old Delete history older than X days
     * @return int Number of records deleted
     */
    public function cleanup_old_history($days_old = 30)
    {
        global $wpdb;

        $table_history = $wpdb->prefix . 'seoautofix_broken_links_fixes_history';

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_history} 
                WHERE applied_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days_old
            )
        );

        return $deleted;
    }
}
