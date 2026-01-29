<?php
/**
 * Broken URL Management Module - Main Class
 * 
 * @package SEOAutoFix\BrokenUrlManagement
 */

namespace SEOAutoFix\BrokenUrlManagement;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main Broken URL Management Class
 */
class SEOAutoFix_Broken_Url_Management
{

    /**
     * Module version
     */
    const VERSION = '2.0.0';

    /**
     * Database table names
     */
    private $table_scans;
    private $table_results;

    /**
     * Constructor
     */
    public function __construct()
    {
        global $wpdb;

        // Set table names
        $this->table_scans = $wpdb->prefix . 'seoautofix_broken_links_scans';
        $this->table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';

        // Initialize module
        $this->init();
    }

    /**
     * Initialize module
     */
    private function init()
    {
        // Create database tables on activation
        add_action('seoautofix_activated', array($this, 'create_database_tables'));

        // Wait for WordPress to load before registering admin hooks
        add_action('plugins_loaded', array($this, 'register_hooks'));

        // Load required files immediately
        $this->load_dependencies();
    }

    /**
     * Register WordPress hooks (called after WordPress loads)
     */
    public function register_hooks()
    {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Enqueue admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Register AJAX endpoints
        $this->register_ajax_endpoints();
    }

    /**
     * Load module dependencies
     */
    private function load_dependencies()
    {
        $module_dir = dirname(__FILE__);

        // Load helper classes
        require_once $module_dir . '/class-database-manager.php';
        require_once $module_dir . '/class-link-crawler.php';
        require_once $module_dir . '/class-link-tester.php';
        require_once $module_dir . '/class-url-similarity.php';
        require_once $module_dir . '/class-link-analyzer.php';

        // Load new helper classes (v2.0)
        if (file_exists($module_dir . '/class-occurrences-manager.php')) {
            require_once $module_dir . '/class-occurrences-manager.php';
        }
        if (file_exists($module_dir . '/class-fix-plan-manager.php')) {
            require_once $module_dir . '/class-fix-plan-manager.php';
        }
        if (file_exists($module_dir . '/class-history-manager.php')) {
            require_once $module_dir . '/class-history-manager.php';
        }
        if (file_exists($module_dir . '/class-export-manager.php')) {
            require_once $module_dir . '/class-export-manager.php';
        }
    }

    /**
     * Create database tables
     */
    public function create_database_tables()
    {
        global $wpdb;

        error_log('[BROKEN URLS] create_database_tables() called');

        $charset_collate = $wpdb->get_charset_collate();
        $table_history = $wpdb->prefix . 'seoautofix_broken_links_fixes_history';

        // Scans table - UPDATED with new fields
        error_log('[BROKEN URLS] Creating/updating scans table');
        $sql_scans = "CREATE TABLE {$this->table_scans} (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scan_id VARCHAR(50) UNIQUE NOT NULL,
            total_pages_found INT DEFAULT 0,
            total_pages_scanned INT DEFAULT 0,
            total_urls_found INT DEFAULT 0,
            total_urls_tested INT DEFAULT 0,
            total_broken_links INT DEFAULT 0,
            total_4xx_errors INT DEFAULT 0,
            total_5xx_errors INT DEFAULT 0,
            status ENUM('in_progress', 'completed', 'failed', 'paused') DEFAULT 'in_progress',
            current_batch INT DEFAULT 0,
            total_batches INT DEFAULT 0,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            INDEX idx_scan_id (scan_id),
            INDEX idx_status (status)
        ) $charset_collate;";

        // Results table - UPDATED with new fields
        error_log('[BROKEN URLS] Creating/updating results table');
        $sql_results = "CREATE TABLE {$this->table_results} (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scan_id VARCHAR(50) NOT NULL,
            found_on_page_id BIGINT(20) DEFAULT 0,
            found_on_page_title VARCHAR(255) DEFAULT '',
            found_on_url TEXT NOT NULL,
            broken_url TEXT NOT NULL,
            link_location ENUM('header', 'footer', 'content', 'sidebar', 'image') DEFAULT 'content',
            anchor_text TEXT NULL,
            link_context TEXT NULL,
            link_type ENUM('internal', 'external') NOT NULL,
            status_code INT NOT NULL,
            error_type ENUM('4xx', '5xx', 'timeout', 'dns') DEFAULT NULL,
            suggested_url TEXT NULL,
            suggestion_confidence INT DEFAULT 0,
            user_modified_url TEXT NULL,
            fix_type ENUM('replace', 'remove', 'redirect') DEFAULT NULL,
            reason TEXT NOT NULL,
            occurrences_count INT DEFAULT 1,
            is_fixed TINYINT(1) DEFAULT 0,
            is_deleted TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_scan_id (scan_id),
            INDEX idx_found_on_page_id (found_on_page_id),
            INDEX idx_broken_url (broken_url(255)),
            INDEX idx_link_type (link_type),
            INDEX idx_status_code (status_code),
            INDEX idx_error_type (error_type),
            INDEX idx_is_fixed (is_fixed)
        ) $charset_collate;";

        // Fixes history table - NEW for revert functionality
        error_log('[BROKEN URLS] Creating fixes history table');
        $sql_history = "CREATE TABLE {$table_history} (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fix_session_id VARCHAR(50) NOT NULL,
            scan_id VARCHAR(50) NOT NULL,
            page_id BIGINT(20) NOT NULL,
            original_content LONGTEXT NOT NULL,
            modified_content LONGTEXT NOT NULL,
            fixes_applied LONGTEXT NOT NULL,
            total_fixes INT DEFAULT 0,
            is_reverted TINYINT(1) DEFAULT 0,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            reverted_at DATETIME NULL,
            INDEX idx_fix_session_id (fix_session_id),
            INDEX idx_scan_id (scan_id),
            INDEX idx_page_id (page_id),
            INDEX idx_is_reverted (is_reverted)
        ) $charset_collate;";

        // Activity log table - NEW for tracking fix/replace/delete actions
        error_log('[BROKEN URLS] Creating activity log table');
        $table_activity = $wpdb->prefix . 'seoautofix_broken_links_activity';

        // Drop existing table to ensure clean recreation
        $wpdb->query("DROP TABLE IF EXISTS {$table_activity}");
        error_log('[BROKEN URLS] Dropped existing activity log table (if exists)');

        $sql_activity = "CREATE TABLE {$table_activity} (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scan_id VARCHAR(50) NOT NULL,
            entry_id BIGINT(20) NOT NULL,
            broken_url TEXT NOT NULL,
            replacement_url TEXT NULL,
            action_type ENUM('fixed', 'replaced', 'deleted') NOT NULL,
            page_url TEXT NOT NULL,
            page_title VARCHAR(255) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_scan_id (scan_id),
            INDEX idx_entry_id (entry_id),
            INDEX idx_action_type (action_type)
        ) $charset_collate;";

        // Snapshot table - NEW for undo changes functionality
        error_log('[BROKEN URLS] Creating snapshot table');
        $table_snapshot = $wpdb->prefix . 'seoautofix_broken_links_snapshot';

        // Drop existing table to ensure clean recreation
        $wpdb->query("DROP TABLE IF EXISTS {$table_snapshot}");
        error_log('[BROKEN URLS] Dropped existing snapshot table (if exists)');

        $sql_snapshot = "CREATE TABLE {$table_snapshot} (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scan_id VARCHAR(50) NOT NULL,
            page_id BIGINT(20) NOT NULL,
            original_content LONGTEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_scan_id (scan_id),
            INDEX idx_page_id (page_id),
            UNIQUE KEY unique_scan_page (scan_id, page_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_scans);
        dbDelta($sql_results);
        dbDelta($sql_history);
        dbDelta($sql_activity);
        dbDelta($sql_snapshot);

        error_log('[BROKEN URLS] Database tables created/updated successfully');
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'seoautofix-settings',
            __('Broken URL Management', 'seo-autofix-pro'),
            __('Broken URLs', 'seo-autofix-pro'),
            'manage_options',
            'seoautofix-broken-urls',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Render admin page
     */
    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'seo-autofix-pro'));
        }

        // Include view template
        include dirname(__FILE__) . '/views/admin-page.php';
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook)
    {
        // Only load on our admin page
        if ($hook !== 'seo-autofix-pro_page_seoautofix-broken-urls') {
            return;
        }

        $module_url = plugin_dir_url(__FILE__);

        // Enqueue CSS
        wp_enqueue_style(
            'seoautofix-broken-urls',
            $module_url . 'assets/css/broken-url-management.css',
            array(),
            self::VERSION
        );

        // Enqueue redesigned UI CSS
        wp_enqueue_style(
            'seoautofix-broken-urls-redesign',
            $module_url . 'assets/css/broken-url-redesign.css',
            array('seoautofix-broken-urls'),
            self::VERSION
        );

        // Enqueue JavaScript
        wp_enqueue_script(
            'seoautofix-broken-urls',
            $module_url . 'assets/js/broken-url-management.js',
            array('jquery'),
            self::VERSION,
            true
        );

        // Localize script with AJAX data
        wp_localize_script('seoautofix-broken-urls', 'seoautofixBrokenUrls', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('seoautofix_broken_urls_nonce'),
            'homeUrl' => home_url('/'),
            'strings' => array(
                'startingScan' => __('Starting scan...', 'seo-autofix-pro'),
                'scanInProgress' => __('Scan in progress...', 'seo-autofix-pro'),
                'scanComplete' => __('Scan complete!', 'seo-autofix-pro'),
                'error' => __('An error occurred', 'seo-autofix-pro'),
                'confirmDelete' => __('Are you sure you want to delete this entry?', 'seo-autofix-pro'),
                'confirmApplyFixes' => __('Are you sure you want to apply these fixes?', 'seo-autofix-pro'),
            )
        ));
    }

    /**
     * Register AJAX endpoints
     */
    private function register_ajax_endpoints()
    {
        // Start scan
        add_action('wp_ajax_seoautofix_broken_links_start_scan', array($this, 'ajax_start_scan'));

        // Process batch
        add_action('wp_ajax_seoautofix_broken_links_process_batch', array($this, 'ajax_process_batch'));

        // Get scan progress
        add_action('wp_ajax_seoautofix_broken_links_get_progress', array($this, 'ajax_get_progress'));

        // Get results
        add_action('wp_ajax_seoautofix_broken_links_get_results', array($this, 'ajax_get_results'));

        // Update suggestion
        add_action('wp_ajax_seoautofix_broken_links_update_suggestion', array($this, 'ajax_update_suggestion'));

        // Delete entry
        add_action('wp_ajax_seoautofix_broken_links_delete_entry', array($this, 'ajax_delete_entry'));

        // Apply fixes
        add_action('wp_ajax_seoautofix_broken_links_apply_fixes', array($this, 'ajax_apply_fixes'));

        // Bulk delete
        add_action('wp_ajax_seoautofix_broken_links_bulk_delete', array($this, 'ajax_bulk_delete'));

        // NEW v2.0 endpoints - Occurrences
        add_action('wp_ajax_seoautofix_broken_links_get_occurrences', array($this, 'ajax_get_occurrences'));
        add_action('wp_ajax_seoautofix_broken_links_bulk_fix', array($this, 'ajax_bulk_fix'));
        add_action('wp_ajax_seoautofix_broken_links_group_by_url', array($this, 'ajax_group_by_url'));

        // Fix Plan
        add_action('wp_ajax_seoautofix_broken_links_generate_fix_plan', array($this, 'ajax_generate_fix_plan'));
        add_action('wp_ajax_seoautofix_broken_links_update_fix_plan', array($this, 'ajax_update_fix_plan'));
        add_action('wp_ajax_seoautofix_broken_links_apply_fix_plan', array($this, 'ajax_apply_fix_plan'));

        // Revert
        add_action('wp_ajax_seoautofix_broken_links_revert_fixes', array($this, 'ajax_revert_fixes'));
        add_action('wp_ajax_seoautofix_broken_links_get_fix_sessions', array($this, 'ajax_get_fix_sessions'));

        // Export
        add_action('wp_ajax_seoautofix_broken_links_export_csv', array($this, 'ajax_export_csv'));
        add_action('wp_ajax_seoautofix_broken_links_export_pdf', array($this, 'ajax_export_pdf'));
        add_action('wp_ajax_seoautofix_broken_links_email_report', array($this, 'ajax_email_report'));

        // Export activity log (fixed links)
        add_action('wp_ajax_seoautofix_broken_links_export_activity_log', array($this, 'ajax_export_activity_log'));
        add_action('wp_ajax_seoautofix_broken_links_email_activity_log', array($this, 'ajax_email_activity_log'));

        // Snapshot and Undo
        add_action('wp_ajax_seoautofix_broken_links_create_snapshot', array($this, 'ajax_create_snapshot'));
        add_action('wp_ajax_seoautofix_broken_links_undo_changes', array($this, 'ajax_undo_changes'));
        add_action('wp_ajax_seoautofix_broken_links_check_snapshot', array($this, 'ajax_check_snapshot'));

        // Test URL (for custom URL validation)
        add_action('wp_ajax_seoautofix_broken_links_test_url', array($this, 'ajax_test_url'));
    }

    /**
     * AJAX: Start new scan
     */
    public function ajax_start_scan()
    {
        error_log('[BROKEN URLS] ajax_start_scan() called');
        error_log('[BROKEN URLS] Request data: ' . print_r($_POST, true));

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');
        error_log('[BROKEN URLS] Nonce verified');

        if (!current_user_can('manage_options')) {
            error_log('[BROKEN URLS] User lacks manage_options capability');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        error_log('[BROKEN URLS] User authorized, creating crawler');

        try {
            $crawler = new Link_Crawler();
            error_log('[BROKEN URLS] Crawler created, starting scan');

            $scan_id = $crawler->start_scan();
            error_log('[BROKEN URLS] Scan started with ID: ' . $scan_id);

            wp_send_json_success(array(
                'scan_id' => $scan_id,
                'message' => __('Scan started successfully', 'seo-autofix-pro')
            ));
        } catch (\Exception $e) {
            error_log('[BROKEN URLS] Exception in ajax_start_scan: ' . $e->getMessage());
            error_log('[BROKEN URLS] Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }


    /**
     * AJAX: Process batch of URLs
     */
    public function ajax_process_batch()
    {
        error_log('[BROKEN URLS] ajax_process_batch() called');

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            error_log('[BROKEN URLS] User lacks manage_options capability');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $scan_id = isset($_POST['scan_id']) ? sanitize_text_field($_POST['scan_id']) : '';

        if (empty($scan_id)) {
            wp_send_json_error(array('message' => __('Scan ID is required', 'seo-autofix-pro')));
        }

        error_log('[BROKEN URLS] Processing batch for scan_id: ' . $scan_id);

        try {
            $crawler = new Link_Crawler();
            $result = $crawler->process_batch($scan_id, 5); // Process 5 pages per batch

            error_log('[BROKEN URLS] Batch processing result: ' . print_r($result, true));

            wp_send_json_success($result);
        } catch (\Exception $e) {
            error_log('[BROKEN URLS] Exception in ajax_process_batch: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * AJAX: Get scan progress
     */
    public function ajax_get_progress()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $scan_id = isset($_GET['scan_id']) ? sanitize_text_field($_GET['scan_id']) : '';

        if (empty($scan_id)) {
            wp_send_json_error(array('message' => __('Invalid scan ID', 'seo-autofix-pro')));
        }

        $db_manager = new Database_Manager();
        $progress = $db_manager->get_scan_progress($scan_id);

        wp_send_json_success($progress);
    }

    /**
     * AJAX: Get scan results
     */
    public function ajax_get_results()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $scan_id = isset($_GET['scan_id']) ? sanitize_text_field($_GET['scan_id']) : '';
        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;

        // New filter parameters
        $error_type = isset($_GET['error_type']) ? sanitize_text_field($_GET['error_type']) : 'all';
        $page_type = isset($_GET['page_type']) ? sanitize_text_field($_GET['page_type']) : 'all';
        $location = isset($_GET['location']) ? sanitize_text_field($_GET['location']) : 'all';

        if (empty($scan_id)) {
            wp_send_json_error(array('message' => __('Invalid scan ID', 'seo-autofix-pro')));
        }

        $db_manager = new Database_Manager();
        $results = $db_manager->get_scan_results($scan_id, $filter, $search, $page, $per_page, $error_type, $page_type, $location);

        wp_send_json_success($results);
    }

    /**
     * AJAX: Update suggestion
     */
    public function ajax_update_suggestion()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $new_url = isset($_POST['new_url']) ? esc_url_raw($_POST['new_url']) : '';

        if (empty($id) || empty($new_url)) {
            wp_send_json_error(array('message' => __('Invalid parameters', 'seo-autofix-pro')));
        }

        $db_manager = new Database_Manager();
        $success = $db_manager->update_suggestion($id, $new_url);

        if ($success) {
            wp_send_json_success(array('message' => __('Suggestion updated', 'seo-autofix-pro')));
        } else {
            wp_send_json_error(array('message' => __('Failed to update suggestion', 'seo-autofix-pro')));
        }
    }

    /**
     * AJAX: Delete entry - Remove link from WordPress content
     */
    public function ajax_delete_entry()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        error_log('[SEO_AUTOFIX] ajax_delete_entry called for ID: ' . $id);

        if (empty($id)) {
            wp_send_json_error(array('message' => __('Invalid ID', 'seo-autofix-pro')));
        }

        try {
            $db_manager = new Database_Manager();
            $entry = $db_manager->get_entry($id);

            if (!$entry) {
                error_log('[SEO_AUTOFIX] Entry not found: ' . $id);
                wp_send_json_error(array('message' => __('Entry not found', 'seo-autofix-pro')));
            }

            error_log('[SEO_AUTOFIX] Entry data: ' . print_r($entry, true));

            // Remove the link from WordPress content
            $success = $this->remove_link_from_content(
                $entry['found_on_url'],
                $entry['broken_url']
            );

            if ($success) {
                global $wpdb;
                $table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';
                $table_activity = $wpdb->prefix . 'seoautofix_broken_links_activity';

                error_log('[ACTIVITY LOG] Attempting to log deletion activity for ID: ' . $id);
                error_log('[ACTIVITY LOG] Scan ID: ' . $entry['scan_id']);
                error_log('[ACTIVITY LOG] Broken URL: ' . $entry['broken_url']);
                error_log('[ACTIVITY LOG] Page URL: ' . $entry['found_on_url']);
                error_log('[ACTIVITY LOG] Page Title: ' . $entry['found_on_page_title']);

                // Log activity before deleting entry
                $insert_result = $wpdb->insert($table_activity, array(
                    'scan_id' => $entry['scan_id'],
                    'entry_id' => $id,
                    'broken_url' => $entry['broken_url'],
                    'replacement_url' => NULL, // NULL for delete action
                    'action_type' => 'deleted',
                    'page_url' => $entry['found_on_url'],
                    'page_title' => $entry['found_on_page_title']
                ), array('%s', '%d', '%s', '%s', '%s', '%s', '%s'));

                if ($insert_result === false) {
                    error_log('[ACTIVITY LOG ERROR] Failed to insert activity log! wpdb error: ' . $wpdb->last_error);
                    error_log('[ACTIVITY LOG ERROR] wpdb last_query: ' . $wpdb->last_query);
                } else {
                    error_log('[ACTIVITY LOG SUCCESS] Activity log entry created with ID: ' . $wpdb->insert_id);
                }

                // Delete from database (not mark as fixed)
                $wpdb->delete($table_results, array('id' => $id), array('%d'));

                error_log('[SEO_AUTOFIX] Successfully removed link from content and deleted from database');

                wp_send_json_success(array(
                    'message' => __('Link removed from content successfully', 'seo-autofix-pro')
                ));
            } else {
                error_log('[SEO_AUTOFIX] Failed to remove link from content');
                wp_send_json_error(array(
                    'message' => __('Failed to remove link from content. Link may not exist in post.', 'seo-autofix-pro')
                ));
            }
        } catch (\Exception $e) {
            error_log('[SEO_AUTOFIX] Exception in ajax_delete_entry: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Remove link from WordPress post content
     * 
     * @param string $page_url Page where link was found
     * @param string $broken_url Broken URL to remove
     * @return bool Success
     */
    private function remove_link_from_content($page_url, $broken_url)
    {
        error_log('[REMOVE_LINK] Starting. Page URL: ' . $page_url . ', Broken URL: ' . $broken_url);

        // Get post ID from URL
        $post_id = url_to_postid($page_url);
        error_log('[REMOVE_LINK] Post ID: ' . $post_id);

        if (!$post_id) {
            error_log('[REMOVE_LINK] Failed to get post ID');
            return false;
        }

        // Check if this is an Elementor page
        $link_analyzer = new Link_Analyzer();
        $is_elementor = $link_analyzer->is_elementor_page($post_id);
        error_log('[REMOVE_LINK] Is Elementor page: ' . ($is_elementor ? 'YES' : 'NO'));

        if ($is_elementor) {
            error_log('[REMOVE_LINK] Detected Elementor page - routing to Elementor handler');
            return $link_analyzer->remove_link_from_elementor($post_id, $broken_url);
        }

        // Regular WordPress page - continue with post_content removal
        error_log('[REMOVE_LINK] Regular WordPress page - using post_content removal');

        // Get post content
        $post = get_post($post_id);
        if (!$post) {
            error_log('[REMOVE_LINK] Failed to get post');
            return false;
        }

        $content = $post->post_content;
        error_log('[REMOVE_LINK] Original content length: ' . strlen($content));

        // Remove the link tag but keep the anchor text
        // Pattern matches: <a href="broken_url">text</a> and replaces with just "text"
        $patterns = array(
            '/<a\s+[^>]*href=["\']' . preg_quote($broken_url, '/') . '["\'][^>]*>(.*?)<\/a>/is',
            '/<a\s+[^>]*src=["\']' . preg_quote($broken_url, '/') . '["\'][^>]*>(.*?)<\/a>/is',
        );

        $new_content = $content;
        foreach ($patterns as $pattern) {
            $new_content = preg_replace($pattern, '$1', $new_content);
        }

        // Also handle img tags - remove entire img tag if src matches
        $img_pattern = '/<img\s+[^>]*src=["\']' . preg_quote($broken_url, '/') . '["\'][^>]*\/?>/i';
        $new_content = preg_replace($img_pattern, '', $new_content);

        // Check if any changes were made
        if ($new_content === $content) {
            error_log('[REMOVE_LINK] No changes made - link not found in content');
            return false;
        }

        error_log('[REMOVE_LINK] Content modified. New length: ' . strlen($new_content));

        // Update post
        $result = wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $new_content
        ), true);

        error_log('[REMOVE_LINK] wp_update_post result: ' . print_r($result, true));

        return !is_wp_error($result);
    }

    /**
     * AJAX: Apply fixes
     */
    public function ajax_apply_fixes()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $ids = isset($_POST['ids']) ? array_map('intval', (array) $_POST['ids']) : array();
        $custom_url = isset($_POST['custom_url']) ? esc_url_raw($_POST['custom_url']) : '';

        error_log('[SEO_AUTOFIX] ajax_apply_fixes called with IDs: ' . print_r($ids, true));
        error_log('[SEO_AUTOFIX] Custom URL: ' . $custom_url);

        if (empty($ids)) {
            wp_send_json_error(array('message' => __('No entries selected', 'seo-autofix-pro')));
        }

        try {
            $db_manager = new Database_Manager();
            $link_analyzer = new Link_Analyzer();

            $result = $link_analyzer->apply_fixes($ids, $custom_url);

            error_log('[SEO_AUTOFIX] apply_fixes result: ' . print_r($result, true));

            wp_send_json_success($result);
        } catch (\Exception $e) {
            error_log('[SEO_AUTOFIX] Exception in ajax_apply_fixes: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * AJAX: Bulk delete entries
     */
    public function ajax_bulk_delete()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $ids = isset($_POST['ids']) ? array_map('intval', (array) $_POST['ids']) : array();

        error_log('[SEO_AUTOFIX] ajax_bulk_delete called with IDs: ' . print_r($ids, true));

        if (empty($ids)) {
            wp_send_json_error(array('message' => __('No entries selected', 'seo-autofix-pro')));
        }

        try {
            global $wpdb;
            $table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';

            $deleted_count = 0;

            // Actually DELETE the rows from the database (not just mark as deleted)
            foreach ($ids as $id) {
                $result = $wpdb->delete(
                    $table_results,
                    array('id' => $id),
                    array('%d')
                );

                if ($result !== false && $result > 0) {
                    $deleted_count++;
                    error_log('[SEO_AUTOFIX] Deleted entry ID: ' . $id);
                }
            }

            error_log('[SEO_AUTOFIX] Bulk delete completed. Permanently deleted: ' . $deleted_count . ' entries');

            wp_send_json_success(array(
                'deleted_count' => $deleted_count,
                'message' => sprintf(__('Permanently deleted %d link(s)', 'seo-autofix-pro'), $deleted_count)
            ));
        } catch (\Exception $e) {
            error_log('[SEO_AUTOFIX] Exception in ajax_bulk_delete: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    // ========================================
    // NEW v2.0 AJAX HANDLERS
    // ========================================

    /**
     * AJAX: Get occurrences of a broken URL
     */
    public function ajax_get_occurrences()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $broken_url = isset($_GET['broken_url']) ? sanitize_text_field($_GET['broken_url']) : '';
        $scan_id = isset($_GET['scan_id']) ? sanitize_text_field($_GET['scan_id']) : '';

        if (empty($broken_url) || empty($scan_id)) {
            wp_send_json_error(array('message' => __('Missing parameters', 'seo-autofix-pro')));
        }

        $occurrences_manager = new Occurrences_Manager();
        $occurrences = $occurrences_manager->get_occurrences($broken_url, $scan_id);

        wp_send_json_success(array(
            'occurrences' => $occurrences,
            'total' => count($occurrences)
        ));
    }

    /**
     * AJAX: Bulk fix occurrences
     */
    public function ajax_bulk_fix()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $broken_url = isset($_POST['broken_url']) ? sanitize_text_field($_POST['broken_url']) : '';
        $replacement_url = isset($_POST['replacement_url']) ? esc_url_raw($_POST['replacement_url']) : '';
        $scan_id = isset($_POST['scan_id']) ? sanitize_text_field($_POST['scan_id']) : '';
        $occurrence_ids = isset($_POST['occurrence_ids']) ? array_map('intval', (array) $_POST['occurrence_ids']) : array();

        if (empty($broken_url) || empty($scan_id)) {
            wp_send_json_error(array('message' => __('Missing parameters', 'seo-autofix-pro')));
        }

        $occurrences_manager = new Occurrences_Manager();
        $result = $occurrences_manager->bulk_fix_occurrences($broken_url, $replacement_url, $scan_id, $occurrence_ids);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Group broken links by URL
     */
    public function ajax_group_by_url()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $scan_id = isset($_GET['scan_id']) ? sanitize_text_field($_GET['scan_id']) : '';

        if (empty($scan_id)) {
            wp_send_json_error(array('message' => __('Scan ID required', 'seo-autofix-pro')));
        }

        $occurrences_manager = new Occurrences_Manager();
        $grouped = $occurrences_manager->group_by_broken_url($scan_id);
        $stats = $occurrences_manager->get_occurrence_stats($scan_id);

        wp_send_json_success(array(
            'grouped' => $grouped,
            'stats' => $stats
        ));
    }

    /**
     * AJAX: Generate fix plan
     */
    public function ajax_generate_fix_plan()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $entry_ids = isset($_POST['entry_ids']) ? array_map('intval', (array) $_POST['entry_ids']) : array();

        if (empty($entry_ids)) {
            wp_send_json_error(array('message' => __('No entries selected', 'seo-autofix-pro')));
        }

        $fix_plan_manager = new Fix_Plan_Manager();
        $result = $fix_plan_manager->generate_fix_plan($entry_ids);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Update fix plan entry
     */
    public function ajax_update_fix_plan()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $plan_id = isset($_POST['plan_id']) ? sanitize_text_field($_POST['plan_id']) : '';
        $entry_id = isset($_POST['entry_id']) ? intval($_POST['entry_id']) : 0;
        $new_url = isset($_POST['new_url']) ? esc_url_raw($_POST['new_url']) : '';
        $fix_action = isset($_POST['fix_action']) ? sanitize_text_field($_POST['fix_action']) : 'replace';

        if (empty($plan_id) || empty($entry_id)) {
            wp_send_json_error(array('message' => __('Missing parameters', 'seo-autofix-pro')));
        }

        $fix_plan_manager = new Fix_Plan_Manager();
        $result = $fix_plan_manager->update_fix_plan_entry($plan_id, $entry_id, $new_url, $fix_action);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Apply fix plan
     */
    public function ajax_apply_fix_plan()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $plan_id = isset($_POST['plan_id']) ? sanitize_text_field($_POST['plan_id']) : '';
        $selected_entry_ids = isset($_POST['selected_entry_ids']) ? array_map('intval', (array) $_POST['selected_entry_ids']) : array();

        if (empty($plan_id)) {
            wp_send_json_error(array('message' => __('Plan ID required', 'seo-autofix-pro')));
        }

        $fix_plan_manager = new Fix_Plan_Manager();
        $result = $fix_plan_manager->apply_fix_plan($plan_id, $selected_entry_ids);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Revert fixes
     */
    public function ajax_revert_fixes()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $fix_session_id = isset($_POST['fix_session_id']) ? sanitize_text_field($_POST['fix_session_id']) : '';

        if (empty($fix_session_id)) {
            wp_send_json_error(array('message' => __('Session ID required', 'seo-autofix-pro')));
        }

        $history_manager = new History_Manager();
        $result = $history_manager->revert_session($fix_session_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Get fix sessions
     */
    public function ajax_get_fix_sessions()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $scan_id = isset($_GET['scan_id']) ? sanitize_text_field($_GET['scan_id']) : '';

        if (empty($scan_id)) {
            wp_send_json_error(array('message' => __('Scan ID required', 'seo-autofix-pro')));
        }

        $history_manager = new History_Manager();
        $sessions = $history_manager->get_scan_fix_sessions($scan_id);

        wp_send_json_success(array(
            'sessions' => $sessions,
            'total' => count($sessions)
        ));
    }

    /**
     * AJAX: Export to CSV
     */
    public function ajax_export_csv()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'seo-autofix-pro'));
        }

        $scan_id = isset($_GET['scan_id']) ? sanitize_text_field($_GET['scan_id']) : '';
        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';

        if (empty($scan_id)) {
            wp_die(__('Scan ID required', 'seo-autofix-pro'));
        }

        $export_manager = new Export_Manager();
        $export_manager->export_to_csv($scan_id, $filter);
        // Note: export_to_csv() handles output and exit
    }

    /**
     * AJAX: Export to PDF
     */
    public function ajax_export_pdf()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'seo-autofix-pro'));
        }

        $scan_id = isset($_GET['scan_id']) ? sanitize_text_field($_GET['scan_id']) : '';
        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';

        if (empty($scan_id)) {
            wp_die(__('Scan ID required', 'seo-autofix-pro'));
        }

        $export_manager = new Export_Manager();
        $result = $export_manager->export_to_pdf($scan_id, $filter);

        if (!$result) {
            wp_die(__('PDF export failed. TCPDF library may not be available.', 'seo-autofix-pro'));
        }
        // Note: export_to_pdf() handles output and exit
    }

    /**
     * AJAX: Email report
     */
    public function ajax_email_report()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $scan_id = isset($_POST['scan_id']) ? sanitize_text_field($_POST['scan_id']) : '';
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'summary';

        if (empty($scan_id) || empty($email)) {
            wp_send_json_error(array('message' => __('Missing parameters', 'seo-autofix-pro')));
        }

        $export_manager = new Export_Manager();
        $result = $export_manager->email_report($scan_id, $email, $format);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Export activity log to CSV (fixed links only)
     */
    public function ajax_export_activity_log()
    {
        error_log('[AJAX EXPORT ACTIVITY LOG] ========== ENDPOINT CALLED ==========');
        error_log('[AJAX EXPORT ACTIVITY LOG] REQUEST_METHOD: ' . $_SERVER['REQUEST_METHOD']);
        error_log('[AJAX EXPORT ACTIVITY LOG] GET params: ' . print_r($_GET, true));
        error_log('[AJAX EXPORT ACTIVITY LOG] POST params: ' . print_r($_POST, true));

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            error_log('[AJAX EXPORT ACTIVITY LOG] Unauthorized user');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        // Accept scan_id from both GET and POST (direct download links use GET)
        $scan_id = '';
        if (isset($_POST['scan_id'])) {
            $scan_id = sanitize_text_field($_POST['scan_id']);
            error_log('[AJAX EXPORT ACTIVITY LOG] Scan ID from POST: ' . $scan_id);
        } elseif (isset($_GET['scan_id'])) {
            $scan_id = sanitize_text_field($_GET['scan_id']);
            error_log('[AJAX EXPORT ACTIVITY LOG] Scan ID from GET: ' . $scan_id);
        }

        if (empty($scan_id)) {
            error_log('[AJAX EXPORT ACTIVITY LOG] Missing scan ID in both GET and POST');
            wp_send_json_error(array('message' => __('Missing scan ID', 'seo-autofix-pro')));
        }

        error_log('[AJAX EXPORT ACTIVITY LOG] Calling Export_Manager->export_activity_log_csv()');
        $export_manager = new Export_Manager();
        $result = $export_manager->export_activity_log_csv($scan_id);

        // If export returns false (no activities), send error response
        if ($result === false) {
            error_log('[AJAX EXPORT ACTIVITY LOG] Export returned false - no activities found');
            wp_send_json_error(array('message' => __('No fixed links found in activity log', 'seo-autofix-pro')));
        }

        // Note: export_activity_log_csv() exits after sending CSV if successful
        error_log('[AJAX EXPORT ACTIVITY LOG] Export completed (this should not be logged if CSV was sent)');
    }

    /**
     * AJAX: Email activity log (fixed links)
     * Automatically sends to WordPress admin email
     */
    public function ajax_email_activity_log()
    {
        error_log('[AJAX EMAIL ACTIVITY LOG] ========== ENDPOINT CALLED ==========');

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            error_log('[AJAX EMAIL ACTIVITY LOG] Unauthorized user');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        // Accept scan_id from both GET and POST
        $scan_id = '';
        if (isset($_POST['scan_id'])) {
            $scan_id = sanitize_text_field($_POST['scan_id']);
            error_log('[AJAX EMAIL ACTIVITY LOG] Scan ID from POST: ' . $scan_id);
        } elseif (isset($_GET['scan_id'])) {
            $scan_id = sanitize_text_field($_GET['scan_id']);
            error_log('[AJAX EMAIL ACTIVITY LOG] Scan ID from GET: ' . $scan_id);
        }

        if (empty($scan_id)) {
            error_log('[AJAX EMAIL ACTIVITY LOG] Missing scan ID');
            wp_send_json_error(array('message' => __('Missing scan ID', 'seo-autofix-pro')));
        }

        error_log('[AJAX EMAIL ACTIVITY LOG] Calling Export_Manager->email_activity_log()');
        $export_manager = new Export_Manager();
        $result = $export_manager->email_activity_log($scan_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Create snapshot of current scan state
     * Called when scan completes to enable undo functionality
     */
    public function ajax_create_snapshot()
    {
        error_log('[SNAPSHOT] ========== CREATE SNAPSHOT CALLED ==========');

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            error_log('[SNAPSHOT] Unauthorized user');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $scan_id = isset($_POST['scan_id']) ? sanitize_text_field($_POST['scan_id']) : '';

        if (empty($scan_id)) {
            error_log('[SNAPSHOT] Missing scan ID');
            wp_send_json_error(array('message' => __('Missing scan ID', 'seo-autofix-pro')));
        }

        error_log('[SNAPSHOT] Creating snapshot for scan: ' . $scan_id);

        global $wpdb;
        $table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';
        $table_snapshot = $wpdb->prefix . 'seoautofix_broken_links_snapshot';

        // Get all unique pages with broken links in this scan
        $pages = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT found_on_page_id FROM {$table_results} WHERE scan_id = %s AND found_on_page_id > 0",
            $scan_id
        ), ARRAY_A);

        if (empty($pages)) {
            error_log('[SNAPSHOT] No pages found for scan');
            wp_send_json_error(array('message' => __('No pages to snapshot', 'seo-autofix-pro')));
        }

        error_log('[SNAPSHOT] Found ' . count($pages) . ' unique pages');

        // Store original content for each page
        $snapshot_count = 0;
        foreach ($pages as $page) {
            $page_id = intval($page['found_on_page_id']);
            $post = get_post($page_id);

            if (!$post) {
                error_log('[SNAPSHOT] Page ID ' . $page_id . ' not found, skipping');
                continue;
            }

            // Check if snapshot already exists (prevent duplicates)
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_snapshot} WHERE scan_id = %s AND page_id = %d",
                $scan_id,
                $page_id
            ));

            if ($existing) {
                error_log('[SNAPSHOT] Snapshot already exists for page ' . $page_id);
                continue;
            }

            // For Elementor pages, store both post_content AND _elementor_data meta
            $is_elementor = get_post_meta($page_id, '_elementor_edit_mode', true) === 'builder';
            $original_content = $post->post_content;

            if ($is_elementor) {
                $elementor_data = get_post_meta($page_id, '_elementor_data', true);
                error_log('[SNAPSHOT] Page ' . $page_id . ' is Elementor page, storing _elementor_data as well');
                // Store Elementor data as JSON in a special format
                $original_content = json_encode(array(
                    'is_elementor' => true,
                    'post_content' => $post->post_content,
                    'elementor_data' => $elementor_data
                ));
            }

            // Insert snapshot
            $inserted = $wpdb->insert(
                $table_snapshot,
                array(
                    'scan_id' => $scan_id,
                    'page_id' => $page_id,
                    'original_content' => $original_content
                ),
                array('%s', '%d', '%s')
            );

            if ($inserted) {
                $snapshot_count++;
                error_log('[SNAPSHOT] Saved content for page ' . $page_id . ' (post: ' . $post->post_title . ', Elementor: ' . ($is_elementor ? 'yes' : 'no') . ')');
            } else {
                error_log('[SNAPSHOT] Failed to save snapshot for page ' . $page_id . ': ' . $wpdb->last_error);
            }
        }

        error_log('[SNAPSHOT] Created ' . $snapshot_count . ' snapshots');

        wp_send_json_success(array(
            'message' => sprintf(__('Snapshot created for %d pages', 'seo-autofix-pro'), $snapshot_count),
            'snapshot_count' => $snapshot_count
        ));
    }

    /**
     * AJAX: Undo all changes - restore from snapshot
     */
    public function ajax_undo_changes()
    {
        error_log('[UNDO] ========== UNDO CHANGES CALLED ==========');

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            error_log('[UNDO] Unauthorized user');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $scan_id = isset($_POST['scan_id']) ? sanitize_text_field($_POST['scan_id']) : '';

        if (empty($scan_id)) {
            error_log('[UNDO] Missing scan ID');
            wp_send_json_error(array('message' => __('Missing scan ID', 'seo-autofix-pro')));
        }

        error_log('[UNDO] Restoring from snapshot for scan: ' . $scan_id);

        global $wpdb;
        $table_snapshot = $wpdb->prefix . 'seoautofix_broken_links_snapshot';

        // Get all snapshots for this scan
        $snapshots = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_snapshot} WHERE scan_id = %s",
            $scan_id
        ), ARRAY_A);

        if (empty($snapshots)) {
            error_log('[UNDO] No snapshots found');
            wp_send_json_error(array('message' => __('No snapshot found to restore', 'seo-autofix-pro')));
        }

        error_log('[UNDO] Found ' . count($snapshots) . ' snapshots to restore');

        // Restore original content for each page
        $restored_count = 0;
        foreach ($snapshots as $snapshot) {
            $page_id = intval($snapshot['page_id']);
            $original_content = $snapshot['original_content'];

            // Check if this is an Elementor snapshot (stored as JSON)
            $snapshot_data = json_decode($original_content, true);

            if ($snapshot_data && isset($snapshot_data['is_elementor']) && $snapshot_data['is_elementor']) {
                // Elementor page - restore both post_content and _elementor_data
                error_log('[UNDO] Restoring Elementor page ' . $page_id);

                $updated = wp_update_post(array(
                    'ID' => $page_id,
                    'post_content' => $snapshot_data['post_content']
                ), true);

                if (!is_wp_error($updated)) {
                    // Restore Elementor data
                    update_post_meta($page_id, '_elementor_data', wp_slash($snapshot_data['elementor_data']));

                    // Clear Elementor cache
                    if (class_exists('\Elementor\Plugin')) {
                        \Elementor\Plugin::$instance->files_manager->clear_cache();
                        error_log('[UNDO] Cleared Elementor cache for page ' . $page_id);
                    }

                    $restored_count++;
                    error_log('[UNDO] Restored Elementor content for page ' . $page_id);
                } else {
                    error_log('[UNDO] Failed to restore Elementor page ' . $page_id . ': ' . $updated->get_error_message());
                }
            } else {
                // Regular WordPress page - restore post_content only
                $updated = wp_update_post(array(
                    'ID' => $page_id,
                    'post_content' => $original_content
                ), true);

                if (is_wp_error($updated)) {
                    error_log('[UNDO] Failed to restore page ' . $page_id . ': ' . $updated->get_error_message());
                } else {
                    $restored_count++;
                    error_log('[UNDO] Restored content for page ' . $page_id);
                }
            }
        }
        // Delete snapshots after restore
        $deleted = $wpdb->delete($table_snapshot, array('scan_id' => $scan_id), array('%s'));
        error_log('[UNDO] Deleted ' . $deleted . ' snapshot entries');

        // ===== CRITICAL: Clean up database entries so links appear as broken again =====
        $table_activity = $wpdb->prefix . 'seoautofix_broken_links_activity';
        $table_scan_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';

        // Get the page IDs that were restored
        $restored_page_ids = array_column($snapshots, 'page_id');
        error_log('[UNDO] Cleaning up database entries for pages: ' . implode(',', $restored_page_ids));

        // Delete activity log entries for this scan (all fixes/deletes done in this scan)
        $activity_deleted = $wpdb->delete(
            $table_activity,
            array('scan_id' => $scan_id),
            array('%s')
        );
        error_log('[UNDO] Deleted ' . $activity_deleted . ' activity log entries');

        // Mark broken links as unfixed instead of deleting them
        // This way they'll appear again in the results with the Fix button
        if (!empty($restored_page_ids)) {
            $placeholders = implode(',', array_fill(0, count($restored_page_ids), '%d'));
            $results_updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table_scan_results} SET is_fixed = 0 WHERE scan_id = %s AND found_on_page_id IN ($placeholders)",
                array_merge(array($scan_id), $restored_page_ids)
            ));
            error_log('[UNDO] Marked ' . $results_updated . ' entries as unfixed for restored pages');
        }

        wp_send_json_success(array(
            'message' => sprintf(__('Successfully restored %d page(s) to original state', 'seo-autofix-pro'), $restored_count),
            'restored_count' => $restored_count,
            'activity_deleted' => $activity_deleted
        ));
    }

    /**
     * AJAX: Check if snapshot exists for current scan
     */
    public function ajax_check_snapshot()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $scan_id = isset($_POST['scan_id']) ? sanitize_text_field($_POST['scan_id']) : '';

        if (empty($scan_id)) {
            wp_send_json_error(array('message' => __('Missing scan ID', 'seo-autofix-pro')));
        }

        global $wpdb;
        $table_snapshot = $wpdb->prefix . 'seoautofix_broken_links_snapshot';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_snapshot} WHERE scan_id = %s",
            $scan_id
        ));

        wp_send_json_success(array(
            'has_snapshot' => ($count > 0),
            'snapshot_count' => intval($count)
        ));
    }

    /**
     * AJAX: Test URL validity (for custom URL validation)
     */
    public function ajax_test_url()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';

        if (empty($url)) {
            wp_send_json_error(array('message' => __('URL is required', 'seo-autofix-pro')));
        }

        // Test the URL using Link_Tester
        $link_tester = new Link_Tester();
        $result = $link_tester->test_url($url);

        // Return validation result
        wp_send_json_success(array(
            'is_valid' => !$result['is_broken'],
            'status_code' => $result['status_code'],
            'error_type' => $result['error_type'],
            'error' => $result['error'],
            'message' => $result['is_broken']
                ? sprintf(__('URL is broken (Status: %d)', 'seo-autofix-pro'), $result['status_code'])
                : __('URL is valid', 'seo-autofix-pro')
        ));
    }
}

// Module will be auto-instantiated by the WordPress plugin loader

