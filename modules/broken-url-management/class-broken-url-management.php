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

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_scans);
        dbDelta($sql_results);
        dbDelta($sql_history);

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
     * AJAX: Delete entry
     */
    public function ajax_delete_entry()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        if (empty($id)) {
            wp_send_json_error(array('message' => __('Invalid ID', 'seo-autofix-pro')));
        }

        $db_manager = new Database_Manager();
        $success = $db_manager->delete_entry($id);

        if ($success) {
            wp_send_json_success(array('message' => __('Entry deleted', 'seo-autofix-pro')));
        } else {
            wp_send_json_error(array('message' => __('Failed to delete entry', 'seo-autofix-pro')));
        }
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

        if (empty($ids)) {
            wp_send_json_error(array('message' => __('No entries selected', 'seo-autofix-pro')));
        }

        try {
            $db_manager = new Database_Manager();
            $link_analyzer = new Link_Analyzer();

            $result = $link_analyzer->apply_fixes($ids);

            wp_send_json_success($result);
        } catch (\Exception $e) {
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
}

// Module will be auto-instantiated by the WordPress plugin loader

