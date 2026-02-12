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
        require_once $module_dir . '/class-url-testing-proxy.php';

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

        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] create_database_tables() called');

        $charset_collate = $wpdb->get_charset_collate();
        $table_history = $wpdb->prefix . 'seoautofix_broken_links_fixes_history';

        // Scans table - UPDATED with new fields
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Creating/updating scans table');
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
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Creating/updating results table');
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
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Creating fixes history table');
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
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Creating activity log table');
        $table_activity = $wpdb->prefix . 'seoautofix_broken_links_activity';

        // Drop existing table to ensure clean recreation
        $wpdb->query("DROP TABLE IF EXISTS {$table_activity}");
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Dropped existing activity log table (if exists)');

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
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Creating snapshot table');
        $table_snapshot = $wpdb->prefix . 'seoautofix_broken_links_snapshot';

        // Drop existing table to ensure clean recreation
        $wpdb->query("DROP TABLE IF EXISTS {$table_snapshot}");
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Dropped existing snapshot table (if exists)');

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

        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Database tables created/updated successfully');
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

        // NEW: Async URL testing endpoints
        add_action('wp_ajax_seoautofix_broken_links_test_external_url', array($this, 'ajax_test_external_url'));
        add_action('wp_ajax_seoautofix_broken_links_test_external_urls_batch', array($this, 'ajax_test_external_urls_batch'));

        // NEW v3.0: Frontend-driven scanning endpoints
        add_action('wp_ajax_seoautofix_broken_links_get_page_urls_batch', array($this, 'ajax_get_page_urls_batch'));
        add_action('wp_ajax_seoautofix_broken_links_test_url_proxy', array($this, 'ajax_test_url_proxy'));
        add_action('wp_ajax_seoautofix_broken_links_save_broken_links_batch', array($this, 'ajax_save_broken_links_batch'));
    }

    /**
     * AJAX: Start new scan
     */
    public function ajax_start_scan()
    {
        \SEOAutoFix_Debug_Logger::log('[SKU] [START_SCAN] ========== AJAX ENDPOINT CALLED ==========');
        \SEOAutoFix_Debug_Logger::log('[SKU] [START_SCAN] Timestamp: ' . current_time('mysql'));
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] ajax_start_scan() called');
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Request data: ' . print_r($_POST, true));

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');
        \SEOAutoFix_Debug_Logger::log('[SKU] [START_SCAN] ✅ Nonce verified');
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Nonce verified');

        if (!current_user_can('manage_options')) {
            \SEOAutoFix_Debug_Logger::log('[SKU] [START_SCAN] ❌ Unauthorized user');
            \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] User lacks manage_options capability');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[SKU] [START_SCAN] ✅ User authorized, creating crawler');
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] User authorized, creating crawler');

        try {
            $crawler = new Link_Crawler();
            \SEOAutoFix_Debug_Logger::log('[SKU] [START_SCAN] Crawler instance created');
            \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Crawler created, starting scan');

            $scan_id = $crawler->start_scan();
            \SEOAutoFix_Debug_Logger::log('[SKU] [START_SCAN] ✅ Scan started successfully with ID: ' . $scan_id);
            \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Scan started with ID: ' . $scan_id);

            wp_send_json_success(array(
                'scan_id' => $scan_id,
                'message' => __('Scan started successfully', 'seo-autofix-pro')
            ));
        } catch (\Exception $e) {
            \SEOAutoFix_Debug_Logger::log('[SKU] [START_SCAN] ❌ EXCEPTION: ' . $e->getMessage());
            \SEOAutoFix_Debug_Logger::log('[SKU] [START_SCAN] Stack trace: ' . $e->getTraceAsString());
            \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Exception in ajax_start_scan: ' . $e->getMessage());
            \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }


    /**
     * AJAX: Process batch of URLs
     */
    public function ajax_process_batch()
    {
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] ajax_process_batch() called');

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            \SEOAutoFix_Debug_Logger::log('[SKU] [PROCESS_BATCH] ❌ Unauthorized user');
            \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] User lacks manage_options capability');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $scan_id = isset($_POST['scan_id']) ? sanitize_text_field($_POST['scan_id']) : '';

        if (empty($scan_id)) {
            \SEOAutoFix_Debug_Logger::log('[SKU] [PROCESS_BATCH] ❌ Missing scan ID');
            wp_send_json_error(array('message' => __('Scan ID is required', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[SKU] [PROCESS_BATCH] Processing batch for scan: ' . $scan_id);
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Processing batch for scan_id: ' . $scan_id);

        try {
            $crawler = new Link_Crawler();
            $result = $crawler->process_batch($scan_id, 5); // Process 5 pages per batch

            // Log progress details
            $completed = isset($result['completed']) && $result['completed'] ? 'YES' : 'NO';
            $progress = isset($result['progress']) ? $result['progress'] : 0;
            $pages_processed = isset($result['pages_processed']) ? $result['pages_processed'] : 0;
            $total_pages = isset($result['total_pages']) ? $result['total_pages'] : 0;
            $broken_count = isset($result['stats']['total']) ? $result['stats']['total'] : 0;

            \SEOAutoFix_Debug_Logger::log('[SKU] [PROCESS_BATCH] Progress: ' . $progress . '% | Pages: ' . $pages_processed . '/' . $total_pages . ' | Broken: ' . $broken_count . ' | Complete: ' . $completed);
            \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Batch processing result: ' . print_r($result, true));

            if ($completed === 'YES') {
                \SEOAutoFix_Debug_Logger::log('[SKU] [PROCESS_BATCH] ✅ SCAN COMPLETED - Total broken links: ' . $broken_count);
            }

            wp_send_json_success($result);
        } catch (\Exception $e) {
            \SEOAutoFix_Debug_Logger::log('[SKU] [PROCESS_BATCH] ❌ EXCEPTION: ' . $e->getMessage());
            \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Exception in ajax_process_batch: ' . $e->getMessage());
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
            \SEOAutoFix_Debug_Logger::log('[SKU] [GET_PROGRESS] ❌ Unauthorized user');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $scan_id = isset($_GET['scan_id']) ? sanitize_text_field($_GET['scan_id']) : '';

        if (empty($scan_id)) {
            \SEOAutoFix_Debug_Logger::log('[SKU] [GET_PROGRESS] ❌ Invalid scan ID');
            wp_send_json_error(array('message' => __('Invalid scan ID', 'seo-autofix-pro')));
        }

        $db_manager = new Database_Manager();
        $progress = $db_manager->get_scan_progress($scan_id);

        // Log progress details
        $progress_pct = isset($progress['progress']) ? $progress['progress'] : 0;
        $tested = isset($progress['tested_urls']) ? $progress['tested_urls'] : 0;
        $total = isset($progress['total_urls']) ? $progress['total_urls'] : 0;
        $broken = isset($progress['broken_count']) ? $progress['broken_count'] : 0;

        \SEOAutoFix_Debug_Logger::log('[SKU] [GET_PROGRESS] Scan: ' . $scan_id . ' | Progress: ' . $progress_pct . '% | URLs: ' . $tested . '/' . $total . ' | Broken: ' . $broken);

        wp_send_json_success($progress);
    }

    /**
     * AJAX: Get scan results
     */
    public function ajax_get_results()
    {
        \SEOAutoFix_Debug_Logger::log('[SKU] [GET_RESULTS] ========== AJAX ENDPOINT CALLED ==========');
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');
        \SEOAutoFix_Debug_Logger::log('[SKU] [GET_RESULTS] ✅ Nonce verified');

        if (!current_user_can('manage_options')) {
            \SEOAutoFix_Debug_Logger::log('[SKU] [GET_RESULTS] ❌ Unauthorized user');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }
        \SEOAutoFix_Debug_Logger::log('[SKU] [GET_RESULTS] ✅ User authorized');

        $scan_id = isset($_GET['scan_id']) ? sanitize_text_field($_GET['scan_id']) : '';

        if (empty($scan_id)) {
            \SEOAutoFix_Debug_Logger::log('[SKU] [GET_RESULTS] ❌ Invalid scan ID');
            wp_send_json_error(array('message' => __('Invalid scan ID', 'seo-autofix-pro')));
        }

        // Get filters
        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';
        $page_type = isset($_GET['page_type']) ? sanitize_text_field($_GET['page_type']) : 'all';
        $error_type = isset($_GET['error_type']) ? sanitize_text_field($_GET['error_type']) : 'all';
        $location = isset($_GET['location']) ? sanitize_text_field($_GET['location']) : 'all';
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;

        \SEOAutoFix_Debug_Logger::log('[SKU] [GET_RESULTS] Loading results for scan: ' . $scan_id . ' | Page: ' . $page . ' | Per page: ' . $per_page . ' | Filters: ' . $filter . '/' . $error_type);

        $db_manager = new Database_Manager();
        // Parameter order: $scan_id, $filter, $search, $page, $per_page, $error_type, $page_type, $location
        $results = $db_manager->get_scan_results($scan_id, $filter, $search, $page, $per_page, $error_type, $page_type, $location);

        $total_results = isset($results['total']) ? $results['total'] : 0;
        \SEOAutoFix_Debug_Logger::log('[SKU] [GET_RESULTS] ✅ Loaded ' . $total_results . ' results');

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

        \SEOAutoFix_Debug_Logger::log('[SEO_AUTOFIX] ajax_delete_entry called for ID: ' . $id);

        if (empty($id)) {
            wp_send_json_error(array('message' => __('Invalid ID', 'seo-autofix-pro')));
        }

        try {
            $db_manager = new Database_Manager();
            $entry = $db_manager->get_entry($id);

            if (!$entry) {
                \SEOAutoFix_Debug_Logger::log('[SEO_AUTOFIX] Entry not found: ' . $id);
                wp_send_json_error(array('message' => __('Entry not found', 'seo-autofix-pro')));
            }

            \SEOAutoFix_Debug_Logger::log('[SEO_AUTOFIX] Entry data: ' . print_r($entry, true));

            // Remove the link from WordPress content
            $success = $this->remove_link_from_content(
                $entry['found_on_url'],
                $entry['broken_url']
            );

            if ($success) {
                global $wpdb;
                $table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';
                $table_activity = $wpdb->prefix . 'seoautofix_broken_links_activity';

                \SEOAutoFix_Debug_Logger::log('[ACTIVITY LOG] Attempting to log deletion activity for ID: ' . $id);
                \SEOAutoFix_Debug_Logger::log('[ACTIVITY LOG] Scan ID: ' . $entry['scan_id']);
                \SEOAutoFix_Debug_Logger::log('[ACTIVITY LOG] Broken URL: ' . $entry['broken_url']);
                \SEOAutoFix_Debug_Logger::log('[ACTIVITY LOG] Page URL: ' . $entry['found_on_url']);
                \SEOAutoFix_Debug_Logger::log('[ACTIVITY LOG] Page Title: ' . $entry['found_on_page_title']);

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
                    \SEOAutoFix_Debug_Logger::log('[ACTIVITY LOG ERROR] Failed to insert activity log! wpdb error: ' . $wpdb->last_error);
                    \SEOAutoFix_Debug_Logger::log('[ACTIVITY LOG ERROR] wpdb last_query: ' . $wpdb->last_query);
                } else {
                    \SEOAutoFix_Debug_Logger::log('[ACTIVITY LOG SUCCESS] Activity log entry created with ID: ' . $wpdb->insert_id);
                }

                // SOFT DELETE: Mark as deleted (is_deleted = 1) instead of removing from database
                // This allows undo to restore by setting is_deleted = 0
                // Same behavior as FIX (which sets is_fixed = 1)
                $db_manager->delete_entry($id);

                \SEOAutoFix_Debug_Logger::log('[SEO_AUTOFIX] Successfully removed link from content and marked as deleted (soft delete)');

                wp_send_json_success(array(
                    'message' => __('Link removed from content successfully', 'seo-autofix-pro')
                ));
            } else {
                \SEOAutoFix_Debug_Logger::log('[SEO_AUTOFIX] Failed to remove link from content');
                wp_send_json_error(array(
                    'message' => __('Failed to remove link from content. Link may not exist in post.', 'seo-autofix-pro')
                ));
            }
        } catch (\Exception $e) {
            \SEOAutoFix_Debug_Logger::log('[SEO_AUTOFIX] Exception in ajax_delete_entry: ' . $e->getMessage());
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
        \SEOAutoFix_Debug_Logger::log('==================== REMOVE LINK DEBUG ====================');
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Starting removal process');
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Page URL: ' . $page_url);
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Broken URL: ' . $broken_url);

        // Get post ID from URL
        $post_id = url_to_postid($page_url);
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] url_to_postid() returned: ' . $post_id);

        if (!$post_id) {
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ❌ FAILED: Could not convert URL to post ID');
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] This could mean:');
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK]   - URL is homepage or archive');
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK]   - URL is custom post type not supported by url_to_postid()');
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK]   - URL format doesn\'t match WordPress permalink structure');
            return false;
        }

        // Check if this is an Elementor page
        $link_analyzer = new Link_Analyzer();
        $is_elementor = $link_analyzer->is_elementor_page($post_id);
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Is Elementor page: ' . ($is_elementor ? 'YES' : 'NO'));

        if ($is_elementor) {
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Routing to Elementor-specific handler');
            return $link_analyzer->remove_link_from_elementor($post_id, $broken_url);
        }

        // Regular WordPress page - continue with post_content removal
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Processing as regular WordPress page');

        // Get post content
        $post = get_post($post_id);
        if (!$post) {
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ❌ FAILED: get_post() returned null for post ID: ' . $post_id);
            return false;
        }

        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Post retrieved successfully');
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Post ID: ' . $post->ID);
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Post Title: ' . $post->post_title);
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Post Type: ' . $post->post_type);

        $content = $post->post_content;
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Original content length: ' . strlen($content) . ' characters');

        // Log first 500 chars of content to see what we're working with
        $content_preview = substr($content, 0, 500);
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Content preview (first 500 chars): ' . $content_preview);

        // Check if broken URL exists in content AT ALL
        if (strpos($content, $broken_url) === false) {
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ⚠️ WARNING: Broken URL NOT FOUND in post_content');
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Searched for: ' . $broken_url);
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] This could mean:');
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK]   - Link is in a widget, menu, or custom field');
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK]   - Link is in Elementor/page builder data');
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK]   - URL encoding differs (e.g., & vs &amp;)');
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK]   - Link was already removed');

            // Try URL-encoded version
            $encoded_url = htmlspecialchars($broken_url);
            if (strpos($content, $encoded_url) !== false) {
                \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ✅ FOUND URL-ENCODED version: ' . $encoded_url);
                $broken_url = $encoded_url; // Use encoded version for regex
            } else {
                \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ❌ URL-encoded version also not found');
                return false;
            }
        } else {
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ✅ Broken URL found in content');
        }

        // Build regex patterns
        $escaped_url = preg_quote($broken_url, '/');
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Escaped URL for regex: ' . $escaped_url);

        $patterns = array(
            '/<a\s+[^>]*href=["\']' . $escaped_url . '["\'][^>]*>(.*?)<\/a>/is',
            '/<a\s+[^>]*src=["\']' . $escaped_url . '["\'][^>]*>(.*?)<\/a>/is',
        );

        $img_pattern = '/<img\s+[^>]*src=["\']' . $escaped_url . '["\'][^>]*\/?>/i';

        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Testing regex patterns...');
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Pattern 1 (href): ' . $patterns[0]);
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Pattern 2 (src): ' . $patterns[1]);
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Image pattern: ' . $img_pattern);

        // Try each pattern and log results
        $new_content = $content;
        $total_replacements = 0;

        foreach ($patterns as $index => $pattern) {
            $count = 0;
            $new_content = preg_replace($pattern, '$1', $new_content, -1, $count);

            if ($count > 0) {
                \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ✅ Pattern ' . ($index + 1) . ' matched! Replacements: ' . $count);
                $total_replacements += $count;
            } else {
                \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ❌ Pattern ' . ($index + 1) . ' did not match');
            }
        }

        // Try img pattern
        $img_count = 0;
        $new_content = preg_replace($img_pattern, '', $new_content, -1, $img_count);
        if ($img_count > 0) {
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ✅ Image pattern matched! Replacements: ' . $img_count);
            $total_replacements += $img_count;
        } else {
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ❌ Image pattern did not match');
        }

        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Total replacements made: ' . $total_replacements);
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] New content length: ' . strlen($new_content) . ' characters');
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Content size difference: ' . (strlen($content) - strlen($new_content)) . ' characters removed');

        // Check if any changes were made
        if ($new_content === $content) {
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ❌ FAILED: No changes made to content');
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Regex patterns did not match any links');
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Possible reasons:');
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK]   - Link syntax is different than expected');
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK]   - Link has HTML entities or special characters');
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK]   - Link is wrapped in different HTML tags');
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ==================== END DEBUG ====================');
            return false;
        }

        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ✅ Content successfully modified!');
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Updating post...');

        // Update post
        $result = wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $new_content
        ), true);

        if (is_wp_error($result)) {
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ❌ wp_update_post() returned error: ' . $result->get_error_message());
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ==================== END DEBUG ====================');
            return false;
        }

        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ✅ wp_update_post() successful! Post updated.');
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ==================== END DEBUG ====================');
        return true;
    }

    /**
     * AJAX: Apply fixes
     */
    public function ajax_apply_fixes()
    {
        \SEOAutoFix_Debug_Logger::log('========================================');
        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] 🔥 ENDPOINT CALLED 🔥');
        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Timestamp: ' . current_time('mysql'));
        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] REQUEST_METHOD: ' . $_SERVER['REQUEST_METHOD']);
        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] POST data: ' . print_r($_POST, true));
        \SEOAutoFix_Debug_Logger::log('========================================');

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');
        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] ✅ Nonce verified');

        if (!current_user_can('manage_options')) {
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] ❌ Unauthorized - user lacks manage_options capability');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] ✅ User authorized');

        $ids = isset($_POST['ids']) ? array_map('intval', (array) $_POST['ids']) : array();
        $custom_url = isset($_POST['custom_url']) ? esc_url_raw($_POST['custom_url']) : '';

        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Parsed IDs: ' . print_r($ids, true));
        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] IDs count: ' . count($ids));
        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Custom URL: ' . $custom_url);

        if (empty($ids)) {
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] ❌ No entries selected - sending error response');
            wp_send_json_error(array('message' => __('No entries selected', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] ✅ IDs validated, creating Link_Analyzer instance');

        try {
            $db_manager = new Database_Manager();
            $link_analyzer = new Link_Analyzer();

            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Calling link_analyzer->apply_fixes()');
            $result = $link_analyzer->apply_fixes($ids, $custom_url);

            \SEOAutoFix_Debug_Logger::log('========================================');
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] 📥 apply_fixes() returned');
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Result: ' . print_r($result, true));
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Fixed count: ' . $result['fixed_count']);
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Failed count: ' . $result['failed_count']);
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Skipped count: ' . $result['skipped_count']);
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Messages: ' . print_r($result['messages'], true));
            \SEOAutoFix_Debug_Logger::log('========================================');

            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] ✅ Sending success response to frontend');
            wp_send_json_success($result);
        } catch (\Exception $e) {
            \SEOAutoFix_Debug_Logger::log('========================================');
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] ❌ EXCEPTION CAUGHT');
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Exception message: ' . $e->getMessage());
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Exception trace: ' . $e->getTraceAsString());
            \SEOAutoFix_Debug_Logger::log('========================================');
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

        \SEOAutoFix_Debug_Logger::log('[SEO_AUTOFIX] ajax_bulk_delete called with IDs: ' . print_r($ids, true));

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
                    \SEOAutoFix_Debug_Logger::log('[SEO_AUTOFIX] Deleted entry ID: ' . $id);
                }
            }

            \SEOAutoFix_Debug_Logger::log('[SEO_AUTOFIX] Bulk delete completed. Permanently deleted: ' . $deleted_count . ' entries');

            wp_send_json_success(array(
                'deleted_count' => $deleted_count,
                'message' => sprintf(__('Permanently deleted %d link(s)', 'seo-autofix-pro'), $deleted_count)
            ));
        } catch (\Exception $e) {
            \SEOAutoFix_Debug_Logger::log('[SEO_AUTOFIX] Exception in ajax_bulk_delete: ' . $e->getMessage());
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
        $custom_plan_json = isset($_POST['custom_plan']) ? stripslashes($_POST['custom_plan']) : '';

        if (empty($entry_ids)) {
            wp_send_json_error(array('message' => __('No entries selected', 'seo-autofix-pro')));
        }

        $fix_plan_manager = new Fix_Plan_Manager();

        // If custom plan is provided (from Fix All Issues modal), use it
        if (!empty($custom_plan_json)) {
            $custom_plan_data = json_decode($custom_plan_json, true);
            if ($custom_plan_data) {
                $result = $fix_plan_manager->generate_fix_plan_from_custom_data($entry_ids, $custom_plan_data);
            } else {
                // Fallback to regular generation if JSON is invalid
                $result = $fix_plan_manager->generate_fix_plan($entry_ids);
            }
        } else {
            // Regular generation (from Replace Broken Links button)
            $result = $fix_plan_manager->generate_fix_plan($entry_ids);
        }

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
        \SEOAutoFix_Debug_Logger::log('========================================');
        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] 🔥 ENDPOINT CALLED 🔥');
        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] Timestamp: ' . current_time('mysql'));
        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] POST data: ' . print_r($_POST, true));
        \SEOAutoFix_Debug_Logger::log('========================================');

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');
        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] ✅ Nonce verified');

        if (!current_user_can('manage_options')) {
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] ❌ Unauthorized');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] ✅ User authorized');

        $plan_id = isset($_POST['plan_id']) ? sanitize_text_field($_POST['plan_id']) : '';
        $selected_entry_ids = isset($_POST['selected_entry_ids']) ? array_map('intval', (array) $_POST['selected_entry_ids']) : array();

        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] Plan ID: ' . $plan_id);
        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] Selected entry IDs: ' . print_r($selected_entry_ids, true));

        if (empty($plan_id)) {
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] ❌ No plan ID provided');
            wp_send_json_error(array('message' => __('Plan ID required', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] Creating Fix_Plan_Manager instance');
        $fix_plan_manager = new Fix_Plan_Manager();

        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] Calling apply_fix_plan()');
        $result = $fix_plan_manager->apply_fix_plan($plan_id, $selected_entry_ids);

        \SEOAutoFix_Debug_Logger::log('========================================');
        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] 📥 apply_fix_plan() returned');
        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] Result: ' . print_r($result, true));
        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] Success: ' . ($result['success'] ? 'YES' : 'NO'));
        \SEOAutoFix_Debug_Logger::log('========================================');

        if ($result['success']) {
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] ✅ Sending success response');
            wp_send_json_success($result);
        } else {
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIX_PLAN] ❌ Sending error response');
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
        \SEOAutoFix_Debug_Logger::log('[AJAX EXPORT ACTIVITY LOG] ========== ENDPOINT CALLED ==========');
        \SEOAutoFix_Debug_Logger::log('[AJAX EXPORT ACTIVITY LOG] REQUEST_METHOD: ' . $_SERVER['REQUEST_METHOD']);
        \SEOAutoFix_Debug_Logger::log('[AJAX EXPORT ACTIVITY LOG] GET params: ' . print_r($_GET, true));
        \SEOAutoFix_Debug_Logger::log('[AJAX EXPORT ACTIVITY LOG] POST params: ' . print_r($_POST, true));

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            \SEOAutoFix_Debug_Logger::log('[AJAX EXPORT ACTIVITY LOG] Unauthorized user');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        // Accept scan_id from both GET and POST (direct download links use GET)
        $scan_id = '';
        if (isset($_POST['scan_id'])) {
            $scan_id = sanitize_text_field($_POST['scan_id']);
            \SEOAutoFix_Debug_Logger::log('[AJAX EXPORT ACTIVITY LOG] Scan ID from POST: ' . $scan_id);
        } elseif (isset($_GET['scan_id'])) {
            $scan_id = sanitize_text_field($_GET['scan_id']);
            \SEOAutoFix_Debug_Logger::log('[AJAX EXPORT ACTIVITY LOG] Scan ID from GET: ' . $scan_id);
        }

        if (empty($scan_id)) {
            \SEOAutoFix_Debug_Logger::log('[AJAX EXPORT ACTIVITY LOG] Missing scan ID in both GET and POST');
            wp_send_json_error(array('message' => __('Missing scan ID', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[AJAX EXPORT ACTIVITY LOG] Calling Export_Manager->export_activity_log_csv()');
        $export_manager = new Export_Manager();
        $result = $export_manager->export_activity_log_csv($scan_id);

        // If export returns false (no activities), send error response
        if ($result === false) {
            \SEOAutoFix_Debug_Logger::log('[AJAX EXPORT ACTIVITY LOG] Export returned false - no activities found');
            wp_send_json_error(array('message' => __('No fixed links found in activity log', 'seo-autofix-pro')));
        }

        // Note: export_activity_log_csv() exits after sending CSV if successful
        \SEOAutoFix_Debug_Logger::log('[AJAX EXPORT ACTIVITY LOG] Export completed (this should not be logged if CSV was sent)');
    }

    /**
     * AJAX: Email activity log (fixed links)
     * Automatically sends to WordPress admin email
     */
    public function ajax_email_activity_log()
    {
        \SEOAutoFix_Debug_Logger::log('[AJAX EMAIL ACTIVITY LOG] ========== ENDPOINT CALLED ==========');

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            \SEOAutoFix_Debug_Logger::log('[AJAX EMAIL ACTIVITY LOG] Unauthorized user');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        // Accept scan_id from both GET and POST
        $scan_id = '';
        if (isset($_POST['scan_id'])) {
            $scan_id = sanitize_text_field($_POST['scan_id']);
            \SEOAutoFix_Debug_Logger::log('[AJAX EMAIL ACTIVITY LOG] Scan ID from POST: ' . $scan_id);
        } elseif (isset($_GET['scan_id'])) {
            $scan_id = sanitize_text_field($_GET['scan_id']);
            \SEOAutoFix_Debug_Logger::log('[AJAX EMAIL ACTIVITY LOG] Scan ID from GET: ' . $scan_id);
        }

        if (empty($scan_id)) {
            \SEOAutoFix_Debug_Logger::log('[AJAX EMAIL ACTIVITY LOG] Missing scan ID');
            wp_send_json_error(array('message' => __('Missing scan ID', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[AJAX EMAIL ACTIVITY LOG] Calling Export_Manager->email_activity_log()');
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
        \SEOAutoFix_Debug_Logger::log('[SKU] [SNAPSHOT] ========== CREATE SNAPSHOT CALLED ==========');
        \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] ========== CREATE SNAPSHOT CALLED ==========');

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            \SEOAutoFix_Debug_Logger::log('[SKU] [SNAPSHOT] ❌ Unauthorized user');
            \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] Unauthorized user');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $scan_id = isset($_POST['scan_id']) ? sanitize_text_field($_POST['scan_id']) : '';

        if (empty($scan_id)) {
            \SEOAutoFix_Debug_Logger::log('[SKU] [SNAPSHOT] ❌ Missing scan ID');
            \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] Missing scan ID');
            wp_send_json_error(array('message' => __('Missing scan ID', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[SKU] [SNAPSHOT] Creating snapshot for scan: ' . $scan_id);
        \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] Creating snapshot for scan: ' . $scan_id);

        global $wpdb;
        $table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';
        $table_snapshot = $wpdb->prefix . 'seoautofix_broken_links_snapshot';

        // Get all unique pages with broken links in this scan
        $pages = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT found_on_page_id FROM {$table_results} WHERE scan_id = %s AND found_on_page_id > 0",
            $scan_id
        ), ARRAY_A);

        if (empty($pages)) {
            \SEOAutoFix_Debug_Logger::log('[SKU] [SNAPSHOT] ❌ No pages found for scan');
            \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] No pages found for scan');
            wp_send_json_error(array('message' => __('No pages to snapshot', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[SKU] [SNAPSHOT] Found ' . count($pages) . ' unique pages');
        \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] Found ' . count($pages) . ' unique pages');

        // Store original content for each page
        $snapshot_count = 0;
        foreach ($pages as $page) {
            $page_id = intval($page['found_on_page_id']);
            $post = get_post($page_id);

            if (!$post) {
                \SEOAutoFix_Debug_Logger::log('[SKU] [SNAPSHOT] Page ID ' . $page_id . ' not found, skipping');
                \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] Page ID ' . $page_id . ' not found, skipping');
                continue;
            }

            // Check if snapshot already exists (prevent duplicates)
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_snapshot} WHERE scan_id = %s AND page_id = %d",
                $scan_id,
                $page_id
            ));

            if ($existing) {
                \SEOAutoFix_Debug_Logger::log('[SKU] [SNAPSHOT] Snapshot already exists for page ' . $page_id);
                \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] Snapshot already exists for page ' . $page_id);
                continue;
            }

            // For Elementor pages, store both post_content AND _elementor_data meta
            $is_elementor = get_post_meta($page_id, '_elementor_edit_mode', true) === 'builder';
            $original_content = $post->post_content;

            if ($is_elementor) {
                $elementor_data = get_post_meta($page_id, '_elementor_data', true);
                \SEOAutoFix_Debug_Logger::log('[SKU] [SNAPSHOT] Page ' . $page_id . ' is Elementor page, storing _elementor_data as well');
                \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] Page ' . $page_id . ' is Elementor page, storing _elementor_data as well');
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
                \SEOAutoFix_Debug_Logger::log('[SKU] [SNAPSHOT] ✅ Saved content for page ' . $page_id . ' (post: ' . $post->post_title . ', Elementor: ' . ($is_elementor ? 'yes' : 'no') . ')');
                \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] Saved content for page ' . $page_id . ' (post: ' . $post->post_title . ', Elementor: ' . ($is_elementor ? 'yes' : 'no') . ')');
            } else {
                \SEOAutoFix_Debug_Logger::log('[SKU] [SNAPSHOT] ❌ Failed to save snapshot for page ' . $page_id . ': ' . $wpdb->last_error);
                \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] Failed to save snapshot for page ' . $page_id . ': ' . $wpdb->last_error);
            }
        }

        \SEOAutoFix_Debug_Logger::log('[SKU] [SNAPSHOT] ✅ Created ' . $snapshot_count . ' snapshots');
        \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] Created ' . $snapshot_count . ' snapshots');

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
        \SEOAutoFix_Debug_Logger::log('[UNDO] ========== UNDO CHANGES CALLED ==========');

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            \SEOAutoFix_Debug_Logger::log('[UNDO] Unauthorized user');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $scan_id = isset($_POST['scan_id']) ? sanitize_text_field($_POST['scan_id']) : '';

        if (empty($scan_id)) {
            \SEOAutoFix_Debug_Logger::log('[UNDO] Missing scan ID');
            wp_send_json_error(array('message' => __('Missing scan ID', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[UNDO] Restoring from snapshot for scan: ' . $scan_id);

        global $wpdb;
        $table_snapshot = $wpdb->prefix . 'seoautofix_broken_links_snapshot';

        // Get all snapshots for this scan
        $snapshots = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_snapshot} WHERE scan_id = %s",
            $scan_id
        ), ARRAY_A);

        if (empty($snapshots)) {
            \SEOAutoFix_Debug_Logger::log('[UNDO] No snapshots found');
            wp_send_json_error(array('message' => __('No snapshot found to restore', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[UNDO] Found ' . count($snapshots) . ' snapshots to restore');

        // Restore original content for each page
        $restored_count = 0;
        foreach ($snapshots as $snapshot) {
            $page_id = intval($snapshot['page_id']);
            $original_content = $snapshot['original_content'];

            // Check if this is an Elementor snapshot (stored as JSON)
            $snapshot_data = json_decode($original_content, true);

            if ($snapshot_data && isset($snapshot_data['is_elementor']) && $snapshot_data['is_elementor']) {
                // Elementor page - restore both post_content and _elementor_data
                \SEOAutoFix_Debug_Logger::log('[UNDO] Restoring Elementor page ' . $page_id);

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
                        \SEOAutoFix_Debug_Logger::log('[UNDO] Cleared Elementor cache for page ' . $page_id);
                    }

                    $restored_count++;
                    \SEOAutoFix_Debug_Logger::log('[UNDO] Restored Elementor content for page ' . $page_id);
                } else {
                    \SEOAutoFix_Debug_Logger::log('[UNDO] Failed to restore Elementor page ' . $page_id . ': ' . $updated->get_error_message());
                }
            } else {
                // Regular WordPress page - restore post_content only
                $updated = wp_update_post(array(
                    'ID' => $page_id,
                    'post_content' => $original_content
                ), true);

                if (is_wp_error($updated)) {
                    \SEOAutoFix_Debug_Logger::log('[UNDO] Failed to restore page ' . $page_id . ': ' . $updated->get_error_message());
                } else {
                    $restored_count++;
                    \SEOAutoFix_Debug_Logger::log('[UNDO] Restored content for page ' . $page_id);
                }
            }
        }
        //NOTE: DO NOT delete snapshots! They should persist for the entire session
        // so users can undo multiple times if they make more fixes
        // Snapshots are only deleted when:
        // 1. A new scan is started (handled separately)
        // 2. Page is refreshed (session ends)
        // 3. User manually clears old data

        // OLD CODE (WRONG - deleted snapshot after first undo):
        // $deleted = $wpdb->delete($table_snapshot, array('scan_id' => $scan_id), array('%s'));
        // \SEOAutoFix_Debug_Logger::log('[UNDO] Deleted ' . $deleted . ' snapshot entries');

        \SEOAutoFix_Debug_Logger::log('[UNDO] Snapshot preserved for potential future undos');

        // ===== CRITICAL: Clean up database entries so links appear as broken again =====
        $table_activity = $wpdb->prefix . 'seoautofix_broken_links_activity';
        $table_scan_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';

        // Get the page IDs that were restored
        $restored_page_ids = array_column($snapshots, 'page_id');
        \SEOAutoFix_Debug_Logger::log('[UNDO] Cleaning up database entries for pages: ' . implode(',', $restored_page_ids));

        // Delete activity log entries for this scan (all fixes/deletes done in this scan)
        $activity_deleted = $wpdb->delete(
            $table_activity,
            array('scan_id' => $scan_id),
            array('%s')
        );
        \SEOAutoFix_Debug_Logger::log('[UNDO] Deleted ' . $activity_deleted . ' activity log entries');



        // Mark broken links as unfixed AND undeleted instead of deleting them
        // This way they'll appear again in the results with the Fix button
        if (!empty($restored_page_ids)) {
            $placeholders = implode(',', array_fill(0, count($restored_page_ids), '%d'));

            \SEOAutoFix_Debug_Logger::log('[UNDO] About to update entries for page IDs: ' . implode(',', $restored_page_ids));
            \SEOAutoFix_Debug_Logger::log('[UNDO] Scan ID: ' . $scan_id);
            \SEOAutoFix_Debug_Logger::log('[UNDO] Query will be: UPDATE ' . $table_scan_results . ' SET is_fixed = 0, is_deleted = 0 WHERE scan_id = ' . $scan_id . ' AND found_on_page_id IN (' . implode(',', $restored_page_ids) . ')');

            $results_updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table_scan_results} SET is_fixed = 0, is_deleted = 0 WHERE scan_id = %s AND found_on_page_id IN ($placeholders)",
                array_merge(array($scan_id), $restored_page_ids)
            ));

            \SEOAutoFix_Debug_Logger::log('[UNDO] Update query executed. Rows affected: ' . $results_updated);
            \SEOAutoFix_Debug_Logger::log('[UNDO] WPDB last error: ' . $wpdb->last_error);
            \SEOAutoFix_Debug_Logger::log('[UNDO] Marked ' . $results_updated . ' entries as unfixed and undeleted for restored pages');
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

    /**
     * AJAX: Test external URL (proxy for frontend async testing)
     */
    public function ajax_test_external_url()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';

        if (empty($url)) {
            wp_send_json_error(array('message' => __('URL is required', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[ASYNC URL TESTING] Testing external URL: ' . $url);

        // Use the URL testing proxy
        $proxy = new URL_Testing_Proxy();
        $result = $proxy->test_external_url($url);

        \SEOAutoFix_Debug_Logger::log('[ASYNC URL TESTING] Result for ' . $url . ': Status ' . $result['status_code']);

        wp_send_json_success($result);
    }

    /**
     * AJAX: Test multiple external URLs in batch (proxy for frontend async testing)
     */
    public function ajax_test_external_urls_batch()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $urls = isset($_POST['urls']) ? (array) $_POST['urls'] : array();
        $urls = array_map('esc_url_raw', $urls);

        if (empty($urls)) {
            wp_send_json_error(array('message' => __('URLs are required', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[ASYNC URL TESTING] Testing batch of ' . count($urls) . ' external URLs');

        // Use the URL testing proxy
        $proxy = new URL_Testing_Proxy();
        $results = $proxy->test_external_urls_batch($urls, 10); // Test 10 URLs in parallel

        \SEOAutoFix_Debug_Logger::log('[ASYNC URL TESTING] Batch complete. Tested ' . count($results) . ' URLs');

        wp_send_json_success(array(
            'results' => $results,
            'total' => count($results)
        ));
    }

    /**
     * AJAX: Get page URLs batch (Frontend-driven scanning v3.0)
     * Returns next batch of page URLs for frontend to fetch and parse
     */
    public function ajax_get_page_urls_batch()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $scan_id = isset($_POST['scan_id']) ? sanitize_text_field($_POST['scan_id']) : '';
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 10;

        if (empty($scan_id)) {
            wp_send_json_error(array('message' => __('Scan ID is required', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[GET PAGE URLS BATCH] Scan ID: ' . $scan_id . ', Batch size: ' . $batch_size);

        // Get URLs from transient
        $all_urls = get_transient('seoautofix_scan_urls_' . $scan_id);
        if ($all_urls === false) {
            \SEOAutoFix_Debug_Logger::log('[GET PAGE URLS BATCH] No URLs found in transient');
            wp_send_json_success(array(
                'urls' => array(),
                'completed' => true,
                'progress' => 100
            ));
            return;
        }

        // Get current progress
        $progress_index = get_transient('seoautofix_scan_progress_' . $scan_id);
        if ($progress_index === false) {
            $progress_index = 0;
        }

        \SEOAutoFix_Debug_Logger::log('[GET PAGE URLS BATCH] Progress: ' . $progress_index . '/' . count($all_urls));

        // Get batch of URLs
        $batch_urls = array_slice($all_urls, $progress_index, $batch_size);

        if (empty($batch_urls)) {
            \SEOAutoFix_Debug_Logger::log('[GET PAGE URLS BATCH] No more URLs to process, marking as completed');

            // Mark scan as complete
            $db_manager = new Database_Manager();
            $db_manager->update_scan($scan_id, array(
                'status' => 'completed',
                'completed_at' => current_time('mysql')
            ));

            // Clean up transients
            delete_transient('seoautofix_scan_urls_' . $scan_id);
            delete_transient('seoautofix_scan_progress_' . $scan_id);

            wp_send_json_success(array(
                'urls' => array(),
                'completed' => true,
                'progress' => 100
            ));
            return;
        }

        // Update progress
        $new_progress = $progress_index + count($batch_urls);
        set_transient('seoautofix_scan_progress_' . $scan_id, $new_progress, DAY_IN_SECONDS);

        $progress_percent = round(($new_progress / count($all_urls)) * 100, 2);

        \SEOAutoFix_Debug_Logger::log('[GET PAGE URLS BATCH] Returning ' . count($batch_urls) . ' URLs. Progress: ' . $progress_percent . '%');

        wp_send_json_success(array(
            'urls' => $batch_urls,
            'completed' => false,
            'progress' => $progress_percent,
            'pages_processed' => $new_progress,
            'total_pages' => count($all_urls)
        ));
    }

    /**
     * AJAX: Test URL proxy (Frontend-driven scanning v3.0)
     * Proxy endpoint to test URLs from frontend (bypasses CORS)
     */
    public function ajax_test_url_proxy()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';

        if (empty($url)) {
            wp_send_json_error(array('message' => __('URL is required', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[TEST URL PROXY] Testing URL: ' . $url);

        // Test the URL using Link_Tester
        $link_tester = new Link_Tester();
        $result = $link_tester->test_url($url);

        \SEOAutoFix_Debug_Logger::log('[TEST URL PROXY] Result for ' . $url . ': Status ' . $result['status_code'] . ', Broken: ' . ($result['is_broken'] ? 'yes' : 'no'));

        wp_send_json_success($result);
    }

    /**
     * AJAX: Save broken links batch (Frontend-driven scanning v3.0)
     * Saves broken links found by frontend to database
     */
    public function ajax_save_broken_links_batch()
    {
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] ========== ENDPOINT CALLED ==========');
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Timestamp: ' . current_time('mysql'));
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] POST data keys: ' . implode(', ', array_keys($_POST)));

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] ✅ Nonce verified');

        if (!current_user_can('manage_options')) {
            \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] ❌ Unauthorized user');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] ✅ User authorized');

        $scan_id = isset($_POST['scan_id']) ? sanitize_text_field($_POST['scan_id']) : '';
        $broken_links_json = isset($_POST['broken_links']) ? stripslashes($_POST['broken_links']) : '';

        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Scan ID: ' . $scan_id);
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Broken links JSON length: ' . strlen($broken_links_json) . ' characters');
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Broken links JSON preview (first 500 chars): ' . substr($broken_links_json, 0, 500));

        if (empty($scan_id)) {
            \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] ❌ Missing scan ID');
            wp_send_json_error(array('message' => __('Scan ID is required', 'seo-autofix-pro')));
        }

        if (empty($broken_links_json)) {
            \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] ℹ️ No broken links JSON provided');
            // No broken links to save is not an error
            wp_send_json_success(array(
                'saved_count' => 0,
                'message' => 'No broken links to save'
            ));
            return;
        }

        $broken_links = json_decode($broken_links_json, true);
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] JSON decode result type: ' . gettype($broken_links));

        if (!is_array($broken_links)) {
            \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] ❌ JSON decode failed or returned non-array');
            \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] JSON error: ' . json_last_error_msg());
            wp_send_json_error(array('message' => __('Invalid broken links data', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Saving ' . count($broken_links) . ' broken links for scan: ' . $scan_id);

        $db_manager = new Database_Manager();
        $saved_count = 0;
        $saved_broken_links = array(); // ✅ NEW: Track saved links with IDs

        foreach ($broken_links as $index => $link) {
            \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Processing link #' . ($index + 1));
            \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Link data: ' . print_r($link, true));

            // Validate required fields
            if (empty($link['url']) || empty($link['found_on_url'])) {
                \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] ⚠️ Skipping link - missing required fields');
                \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] url present: ' . (isset($link['url']) ? 'yes' : 'no'));
                \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] found_on_url present: ' . (isset($link['found_on_url']) ? 'yes' : 'no'));
                continue;
            }

            // Prepare link data for database
            $link_data = array(
                'broken_url' => $link['url'],
                'status_code' => isset($link['status_code']) ? intval($link['status_code']) : 0,
                'error_type' => isset($link['error_type']) ? sanitize_text_field($link['error_type']) : null,
                'found_on_url' => $link['found_on_url'],
                'found_on_page_id' => isset($link['found_on_page_id']) ? intval($link['found_on_page_id']) : 0,
                'found_on_page_title' => isset($link['found_on_page_title']) ? sanitize_text_field($link['found_on_page_title']) : '',
                'anchor_text' => isset($link['anchor_text']) ? sanitize_text_field($link['anchor_text']) : '',
                'link_type' => isset($link['link_type']) ? sanitize_text_field($link['link_type']) : 'external',
                'location' => isset($link['location']) ? sanitize_text_field($link['location']) : 'content',
                'suggested_url' => isset($link['suggested_url']) ? esc_url_raw($link['suggested_url']) : null
            );

            \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Prepared link_data: ' . print_r($link_data, true));
            \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Calling db_manager->add_broken_link() for: ' . $link_data['broken_url']);

            // Save to database
            $result = $db_manager->add_broken_link($scan_id, $link_data);

            \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] add_broken_link() returned: ' . ($result ? 'TRUE' : 'FALSE'));

            if ($result) {
                global $wpdb;
                $inserted_id = $wpdb->insert_id; // ✅ Get the inserted ID

                // ✅ Add the ID to the link data and store it
                $link['id'] = $inserted_id;
                $saved_broken_links[] = $link;

                $saved_count++;
                \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] ✅ Successfully saved link #' . ($index + 1) . ' with ID: ' . $inserted_id);
            } else {
                \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] ❌ Failed to save link #' . ($index + 1));
            }
        }

        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] ========== SAVE COMPLETE ==========');
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Total links processed: ' . count($broken_links));
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Successfully saved: ' . $saved_count);
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Failed to save: ' . (count($broken_links) - $saved_count));

        // Update scan statistics
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Getting scan progress for statistics...');
        $broken_count = $db_manager->get_scan_progress($scan_id)['broken_count'];
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Current broken count in database: ' . $broken_count);

        // ✅ Return saved broken links with IDs
        wp_send_json_success(array(
            'saved_count' => $saved_count,
            'total_broken' => $broken_count,
            'broken_links' => $saved_broken_links, // ✅ NEW: Include saved links with IDs
            'message' => sprintf(__('Saved %d broken links', 'seo-autofix-pro'), $saved_count)
        ));
    }
}

// Module will be auto-instantiated by the WordPress plugin loader

