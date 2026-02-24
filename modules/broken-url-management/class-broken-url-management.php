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
     * Internal links cache for suggestion generation
     * Cached to avoid repeated queries during URL testing
     */
    private $internal_links_cache = null;
    private $url_similarity = null;

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
        // Check if plugin was just activated and create tables immediately
        // This must happen in init() because activation hooks fire before modules are loaded
        if (get_option('seoautofix_activated')) {
            // Clear debug logs on activation for a fresh start
            \SEOAutoFix_Debug_Logger::clear('general');

            \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Plugin activation detected, creating tables...');
            $this->create_database_tables();
            // Clear the flag so we don't recreate on every page load
            delete_option('seoautofix_activated');
        }

        // Also listen for future activations
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

        // Load required classes for Link_Analyzer BEFORE loading Link_Analyzer itself
        if (file_exists($module_dir . '/class-universal-replacement-engine.php')) {
            require_once $module_dir . '/class-universal-replacement-engine.php';
        }
        if (file_exists($module_dir . '/class-header-footer-replacer.php')) {
            require_once $module_dir . '/class-header-footer-replacer.php';
        }
        // Builder-aware replacement engine (v2.0)
        if (file_exists($module_dir . '/class-builder-detector.php')) {
            require_once $module_dir . '/class-builder-detector.php';
        }
        if (file_exists($module_dir . '/class-builder-replacement-engine.php')) {
            require_once $module_dir . '/class-builder-replacement-engine.php';
        }

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

        // Define all table names
        $table_scans = $this->table_scans;
        $table_results = $this->table_results;
        $table_history = $wpdb->prefix . 'seoautofix_broken_links_fixes_history';
        $table_activity = $wpdb->prefix . 'seoautofix_broken_links_activity';
        $table_snapshot = $wpdb->prefix . 'seoautofix_broken_links_snapshot';

        // ========================================
        // STEP 1: DROP ALL TABLES IF THEY EXIST
        // ========================================
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] STEP 1: Dropping existing tables...');

        $wpdb->query("DROP TABLE IF EXISTS {$table_scans}");
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] - Dropped scans table (if exists)');

        $wpdb->query("DROP TABLE IF EXISTS {$table_results}");
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] - Dropped results table (if exists)');

        $wpdb->query("DROP TABLE IF EXISTS {$table_history}");
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] - Dropped history table (if exists)');

        $wpdb->query("DROP TABLE IF EXISTS {$table_activity}");
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] - Dropped activity table (if exists)');

        $wpdb->query("DROP TABLE IF EXISTS {$table_snapshot}");
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] - Dropped snapshot table (if exists)');

        // ========================================
        // STEP 2: CREATE ALL TABLES
        // ========================================
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] STEP 2: Creating new tables...');

        // Scans table
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] - Creating scans table');
        $sql_scans = "CREATE TABLE {$table_scans} (
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

        // Results table
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] - Creating results table');
        $sql_results = "CREATE TABLE {$table_results} (
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
            builder_type VARCHAR(20) DEFAULT NULL,
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

        // Fixes history table
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] - Creating fixes history table');
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

        // Activity log table
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] - Creating activity log table');
        $sql_activity = "CREATE TABLE {$table_activity} (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scan_id VARCHAR(50) NOT NULL,
            entry_id BIGINT(20) NOT NULL,
            broken_url TEXT NOT NULL,
            replacement_url TEXT NULL,
            action_type ENUM('fixed', 'replaced', 'deleted', 'undo') NOT NULL,
            page_url TEXT NOT NULL,
            page_title VARCHAR(255) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_scan_id (scan_id),
            INDEX idx_entry_id (entry_id),
            INDEX idx_action_type (action_type)
        ) $charset_collate;";

        // Snapshot table
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] - Creating snapshot table');
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

        // Execute table creation
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_scans);
        dbDelta($sql_results);
        dbDelta($sql_history);
        dbDelta($sql_activity);
        dbDelta($sql_snapshot);

        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] ✅ All 5 database tables created successfully');

        // Store DB version for future migration support
        update_option('seoautofix_broken_links_db_version', '2.0');
        \SEOAutoFix_Debug_Logger::log('[BROKEN URLS] Stored DB version 2.0 in wp_options');
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

        add_action('wp_ajax_seoautofix_broken_links_get_page_urls_batch', array($this, 'ajax_get_page_urls_batch'));
        add_action('wp_ajax_seoautofix_broken_links_test_url_proxy', array($this, 'ajax_test_url_proxy'));
        add_action('wp_ajax_seoautofix_broken_links_save_broken_links_batch', array($this, 'ajax_save_broken_links_batch'));

        // Storage-based link extraction (replaces JS HTML-fetch + DOMParser step)
        add_action('wp_ajax_seoautofix_broken_links_extract_links_from_storage', array($this, 'ajax_extract_links_from_storage'));
    }

    /**
     * AJAX: Start new scan
     */
    public function ajax_start_scan()
    {
        // Clear all previous debug logs so each scan produces fresh, self-contained output.
        // This prevents old entries (e.g. from January) bleeding into new scan logs.
        \SEOAutoFix_Debug_Logger::clear_all();

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
        \SEOAutoFix_Debug_Logger::log('========================================');
        \SEOAutoFix_Debug_Logger::log('[AJAX_UPDATE_SUGGESTION] 🔄 REPLACE ENDPOINT CALLED');
        \SEOAutoFix_Debug_Logger::log('[AJAX_UPDATE_SUGGESTION] Timestamp: ' . current_time('mysql'));
        \SEOAutoFix_Debug_Logger::log('[AJAX_UPDATE_SUGGESTION] POST data: ' . print_r($_POST, true));
        \SEOAutoFix_Debug_Logger::log('========================================');

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');
        \SEOAutoFix_Debug_Logger::log('[AJAX_UPDATE_SUGGESTION] ✅ Nonce verified');

        if (!current_user_can('manage_options')) {
            \SEOAutoFix_Debug_Logger::log('[AJAX_UPDATE_SUGGESTION] ❌ Unauthorized');
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $new_url = isset($_POST['new_url']) ? esc_url_raw($_POST['new_url']) : '';

        \SEOAutoFix_Debug_Logger::log('[AJAX_UPDATE_SUGGESTION] Entry ID  : ' . $id);
        \SEOAutoFix_Debug_Logger::log('[AJAX_UPDATE_SUGGESTION] New URL   : ' . $new_url);

        if (empty($id) || empty($new_url)) {
            \SEOAutoFix_Debug_Logger::log('[AJAX_UPDATE_SUGGESTION] ❌ Invalid parameters — id=' . $id . ' new_url=' . $new_url);
            wp_send_json_error(array('message' => __('Invalid parameters', 'seo-autofix-pro')));
        }

        $db_manager = new Database_Manager();
        \SEOAutoFix_Debug_Logger::log('[AJAX_UPDATE_SUGGESTION] Calling db_manager->update_suggestion()');
        $success = $db_manager->update_suggestion($id, $new_url);

        if ($success) {
            \SEOAutoFix_Debug_Logger::log('[AJAX_UPDATE_SUGGESTION] ✅ Suggestion updated successfully for entry ID: ' . $id);
            wp_send_json_success(array('message' => __('Suggestion updated', 'seo-autofix-pro')));
        } else {
            \SEOAutoFix_Debug_Logger::log('[AJAX_UPDATE_SUGGESTION] ❌ Failed to update suggestion for entry ID: ' . $id);
            wp_send_json_error(array('message' => __('Failed to update suggestion', 'seo-autofix-pro')));
        }
    }

    /**
     * AJAX: Delete entry - Remove link from WordPress content (TRANSACTIONAL)
     */
    public function ajax_delete_entry()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

        \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] ========== START: ID=' . $id . ' | time=' . current_time('mysql') . ' ==========');

        if (empty($id)) {
            \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] ❌ No ID provided — returning error');
            wp_send_json_error(array('message' => __('Invalid ID', 'seo-autofix-pro')));
        }

        $lock_key = null;
        $post_id = null;

        try {
            $db_manager = new Database_Manager();
            $entry = $db_manager->get_entry($id);

            if (!$entry) {
                \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] ❌ Entry ID ' . $id . ' not found in database');
                wp_send_json_error(array('message' => __('Entry not found', 'seo-autofix-pro')));
            }

            \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] Entry found — broken_url="' . $entry['broken_url'] . '" found_on_url="' . $entry['found_on_url'] . '" location="' . ($entry['link_location'] ?? 'N/A') . '" builder="' . ($entry['builder_type'] ?? 'N/A') . '" is_deleted=' . $entry['is_deleted']);

            // Resolve post ID for locking
            $link_analyzer = new Link_Analyzer();
            $post_id = $link_analyzer->get_post_id_from_url_public($entry['found_on_url']);
            $builder_type = isset($entry['builder_type']) ? $entry['builder_type'] : null;
            $location = isset($entry['link_location']) ? $entry['link_location'] : 'content';

            \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] Resolved post_id=' . ($post_id ?: 'NULL') . ' | location=' . $location . ' | builder=' . ($builder_type ?: 'none'));

            // --- Per-post lock ---
            if ($post_id) {
                $lock_key = 'seoautofix_lock_' . $post_id;
                $lock_value = get_transient($lock_key);
                \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] Lock check for post ' . $post_id . ': ' . ($lock_value ? ' LOCKED (set ' . (time() - (int) $lock_value) . 's ago)' : '🔓 FREE'));

                if ($lock_value) {
                    \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] ❌ BLOCKED BY LOCK — returning lock-conflict error for ID=' . $id);
                    wp_send_json_error(array(
                        'message' => __('Another operation is in progress for this page. Please try again.', 'seo-autofix-pro'),
                        'operation' => 'delete',
                        'verified' => false,
                        'attempts' => 0,
                        'builder_used' => $builder_type,
                        'table_updated' => false
                    ));
                }
                set_transient($lock_key, time(), 10);
                \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] 🔒 Lock ACQUIRED for post ' . $post_id . ' (10s TTL)');
            }

            // --- Retry loop with verification ---
            $verified = false;
            $attempts = 0;
            $max_attempts = 2;

            for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
                $attempts = $attempt;

                if ($attempt > 1) {
                    \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] ⏳ Retry #' . ($attempt - 1) . ' after 150ms delay...');
                    usleep(150000);
                }

                // Remove the link from WordPress content (location-aware 4-layer engine)
                $engine_result = $this->remove_link_from_content(
                    $entry['found_on_url'],
                    $entry['broken_url'],
                    $location
                );
                $success = $engine_result['success'] ?? false;
                $last_engine_result = $engine_result;

                \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] Attempt ' . $attempt . ' removal result: ' . ($success ? 'SUCCESS' : 'FAILED') . ' | method=' . ($engine_result['method'] ?? 'N/A') . ' | reason=' . ($engine_result['reason'] ?? 'N/A') . ' | manual_required=' . (!empty($engine_result['manual_required']) ? 'YES' : 'NO'));

                if (!$success) {
                    continue;
                }

                // STRICT VERIFICATION: check both anchor tag removal AND URL absence
                $verified = $this->verify_delete_for_entry($entry['found_on_url'], $entry['broken_url'], $location, $builder_type);

                if ($verified) {
                    \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] ✅ VERIFICATION PASSED on attempt ' . $attempt);
                    break;
                }

                \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] ❌ VERIFICATION FAILED on attempt ' . $attempt);
            }

            // --- TRANSACTIONAL: Only update table AFTER verified success ---
            if ($verified) {
                // Clear caches first
                if ($post_id) {
                    Builder_Replacement_Engine::clear_all_caches($post_id, 'delete');
                }

                global $wpdb;
                $table_activity = $wpdb->prefix . 'seoautofix_broken_links_activity';

                // Log activity
                $wpdb->insert($table_activity, array(
                    'scan_id' => $entry['scan_id'],
                    'entry_id' => $id,
                    'broken_url' => $entry['broken_url'],
                    'replacement_url' => NULL,
                    'action_type' => 'deleted',
                    'page_url' => $entry['found_on_url'],
                    'page_title' => $entry['found_on_page_title']
                ), array('%s', '%d', '%s', '%s', '%s', '%s', '%s'));

                // SOFT DELETE: Mark as deleted only after verified removal
                $db_manager->delete_entry($id);

                \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] ✅ SUCCESS: Transactional delete complete — ID=' . $id . ' verified and table updated');

                // ⚡ Release lock BEFORE sending response so next sequential request is never blocked
                if ($lock_key) {
                    delete_transient($lock_key);
                    \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] 🔓 Lock pre-released (success) for post ' . $post_id);
                    $lock_key = null; // Prevent double-delete in finally
                }

                wp_send_json_success(array(
                    'message' => __('Link removed from content successfully', 'seo-autofix-pro'),
                    'operation' => 'delete',
                    'verified' => true,
                    'attempts' => $attempts,
                    'builder_used' => $builder_type,
                    'table_updated' => true
                ));
            } else {
                // TRANSACTIONAL: Do NOT update table
                \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] ❌ FAILURE: verification failed after ' . $attempts . ' attempt(s) for ID=' . $id . ' | manual_required=' . (!empty($last_engine_result['manual_required']) ? 'YES' : 'NO') . ' | last_reason=' . ($last_engine_result['reason'] ?? 'N/A'));

                $response = [
                    'operation' => 'delete',
                    'verified' => false,
                    'attempts' => $attempts,
                    'builder_used' => $builder_type,
                    'table_updated' => false,
                ];

                // Check if builder engine flagged manual_required on last attempt
                if (!empty($last_engine_result['manual_required'])) {
                    $response['message'] = __('This link appears to be dynamically injected or hardcoded. Please modify manually.', 'seo-autofix-pro');
                    $response['manual_required'] = true;
                    $response['reason'] = $last_engine_result['reason'] ?? '';
                } else {
                    $response['message'] = sprintf(
                        __('Failed to remove link — verification failed after %d attempt(s). Content may not have been modified.', 'seo-autofix-pro'),
                        $attempts
                    );
                }

                \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] ❌ Sending error response: ' . json_encode($response));

                // ⚡ Release lock BEFORE sending response
                if ($lock_key) {
                    delete_transient($lock_key);
                    \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] 🔓 Lock pre-released (failure) for post ' . $post_id);
                    $lock_key = null; // Prevent double-delete in finally
                }

                wp_send_json_error($response);
            }
        } catch (\Exception $e) {
            \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] Exception: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        } finally {
            // ALWAYS release lock
            if ($lock_key) {
                delete_transient($lock_key);
                \SEOAutoFix_Debug_Logger::log('[AJAX_DELETE] 🔓 Lock released for post ' . $post_id);
            }
        }
    }

    /**
     * Remove link from WordPress post content — 4-layer durable engine.
     *
     * @param string $page_url   Page where link was found.
     * @param string $broken_url Broken URL to remove.
     * @param string $location   Link location (content, header, footer, sidebar).
     * @return bool Success.
     */
    private function remove_link_from_content($page_url, $broken_url, $location = 'content')
    {
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ===== remove_link_from_content() START =====');
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Page URL   : ' . $page_url);
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Broken URL : ' . $broken_url);
        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Location   : ' . $location);

        $link_analyzer = new Link_Analyzer();
        $post_id = $link_analyzer->get_post_id_from_url_public($page_url);

        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Resolved post ID: ' . ($post_id ?: 'NOT FOUND'));

        if (!$post_id) {
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ❌ Could not resolve post ID — cannot remove link');
            return false;
        }

        // 4-layer engine — location-aware
        $builder_engine = new Builder_Replacement_Engine();
        $result = $builder_engine->remove_link($post_id, $broken_url, $location);

        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] Engine result: success=' . ($result['success'] ? 'YES' : 'NO')
            . ' method=' . ($result['method'] ?? 'unknown')
            . ' manual_required=' . (!empty($result['manual_required']) ? 'YES' : 'NO'));

        if ($result['success']) {
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ✅ Removal succeeded via ' . ($result['method'] ?? 'unknown'));
        } else {
            \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ❌ All layers returned 0. Reason: ' . ($result['reason'] ?? 'unknown'));
        }

        \SEOAutoFix_Debug_Logger::log('[REMOVE_LINK] ===== remove_link_from_content() END =====');
        return $result;
    }

    /**
     * Verify that a link deletion was actually applied.
     * Delegates to Link_Analyzer's strict delete verification (anchor regex + URL absence).
     *
     * @param string $page_url     Page URL where link was found.
     * @param string $broken_url   Broken URL that was deleted.
     * @param string $location     Location (content, header, footer).
     * @param string $builder_type Stored builder type (avoids re-detection).
     * @return bool True if anchor tag with URL has been removed.
     */
    private function verify_delete_for_entry($page_url, $broken_url, $location = 'content', $builder_type = null)
    {
        \SEOAutoFix_Debug_Logger::log('[VERIFY_DELETE_ENTRY] Verifying deletion for URL: ' . $broken_url);

        $link_analyzer = new Link_Analyzer();
        $post_id = $link_analyzer->get_post_id_from_url_public($page_url);

        if (!$post_id) {
            \SEOAutoFix_Debug_Logger::log('[VERIFY_DELETE_ENTRY] ⚠️ Could not resolve post ID — assuming success');
            return true;
        }

        // Flush cache to get fresh content
        clean_post_cache($post_id);

        $builder = $builder_type ?? Builder_Detector::detect($post_id);
        $post = get_post($post_id);

        if (!$post) {
            \SEOAutoFix_Debug_Logger::log('[VERIFY_DELETE_ENTRY] ⚠️ Could not get post — assuming success');
            return true;
        }

        $content = $post->post_content;
        $escaped_url = preg_quote($broken_url, '/');

        // Check 1: Anchor tag with this URL should not exist
        $anchor_pattern = '/<a[^>]*href=["\']' . $escaped_url . '["\'][^>]*>/i';
        if (preg_match($anchor_pattern, $content)) {
            \SEOAutoFix_Debug_Logger::log('[VERIFY_DELETE_ENTRY] ❌ Anchor tag with URL still exists in post_content');
            return false;
        }

        // Check 2: For Elementor, also check _elementor_data
        if ($builder === 'elementor' || (defined('SEOAutoFix\\BrokenUrlManagement\\Builder_Detector::ELEMENTOR') && $builder === Builder_Detector::ELEMENTOR)) {
            // Force fresh DB read — no object cache
            wp_cache_delete($post_id, 'post_meta');
            $raw = get_post_meta($post_id, '_elementor_data', true);
            \SEOAutoFix_Debug_Logger::log('[VERIFY_DELETE_ENTRY] _elementor_data length=' . strlen((string) $raw));
            if (!empty($raw) && stripos($raw, $broken_url) !== false) {
                // Show exactly where it still appears
                $pos = stripos($raw, $broken_url);
                \SEOAutoFix_Debug_Logger::log('[VERIFY_DELETE_ENTRY] ❌ URL still exists in _elementor_data at position=' . $pos);
                \SEOAutoFix_Debug_Logger::log('[VERIFY_DELETE_ENTRY] Context (pos-100 to pos+100): ' . substr($raw, max(0, $pos - 100), 250));
                return false;
            }
            \SEOAutoFix_Debug_Logger::log('[VERIFY_DELETE_ENTRY] ✅ URL not found in _elementor_data');
        }

        \SEOAutoFix_Debug_Logger::log('[VERIFY_DELETE_ENTRY] ✅ Deletion verified — anchor and URL absent');
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

        \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] ✅ IDs validated, creating instances...');

        try {
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Creating Database_Manager instance...');
            $db_manager = new Database_Manager();
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] ✅ Database_Manager created');

            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Creating Link_Analyzer instance...');
            $link_analyzer = new Link_Analyzer();
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] ✅ Link_Analyzer created successfully');

            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Calling link_analyzer->apply_fixes() with:');
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] - IDs: ' . implode(', ', $ids));
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] - Custom URL: ' . $custom_url);

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
        } catch (\Error $e) {
            \SEOAutoFix_Debug_Logger::log('========================================');
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] ❌ FATAL ERROR CAUGHT');
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Error message: ' . $e->getMessage());
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Error file: ' . $e->getFile() . ' line ' . $e->getLine());
            \SEOAutoFix_Debug_Logger::log('[AJAX_APPLY_FIXES] Error trace: ' . $e->getTraceAsString());
            \SEOAutoFix_Debug_Logger::log('========================================');
            wp_send_json_error(array('message' => 'Fatal error: ' . $e->getMessage()));
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

        // ===================================================================
        // FIX: Also snapshot Elementor header/footer templates for page_id=0
        // These are elementor_library posts used as site-wide header/footer.
        // The original query (found_on_page_id > 0) missed them entirely.
        // ===================================================================
        $header_footer_urls = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT broken_url FROM {$table_results} WHERE scan_id = %s AND found_on_page_id = 0",
            $scan_id
        ));

        if (!empty($header_footer_urls)) {
            \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] Found ' . count($header_footer_urls) . ' unique broken URLs in header/footer entries (page_id=0)');

            // Find all Elementor library templates (header/footer templates)
            $templates = get_posts(array(
                'post_type' => 'elementor_library',
                'posts_per_page' => -1,
                'post_status' => 'publish'
            ));

            foreach ($templates as $template) {
                $elementor_data = get_post_meta($template->ID, '_elementor_data', true);
                if (empty($elementor_data)) {
                    continue;
                }

                // Check if any broken header/footer URL appears in this template
                $relevant = false;
                foreach ($header_footer_urls as $hf_url) {
                    if (stripos($elementor_data, $hf_url) !== false) {
                        $relevant = true;
                        break;
                    }
                }
                if (!$relevant) {
                    continue;
                }

                // Prevent duplicate snapshot for this template
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table_snapshot} WHERE scan_id = %s AND page_id = %d",
                    $scan_id,
                    $template->ID
                ));
                if ($existing) {
                    \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] Template snapshot already exists for ID ' . $template->ID . ' (' . $template->post_title . ')');
                    continue;
                }

                // Store content in same Elementor JSON format as regular pages
                $snap_content = json_encode(array(
                    'is_elementor' => true,
                    'post_content' => $template->post_content,
                    'elementor_data' => $elementor_data
                ));

                $inserted = $wpdb->insert(
                    $table_snapshot,
                    array(
                        'scan_id' => $scan_id,
                        'page_id' => $template->ID,
                        'original_content' => $snap_content
                    ),
                    array('%s', '%d', '%s')
                );

                if ($inserted) {
                    $snapshot_count++;
                    \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] ✅ Snapshotted Elementor header/footer template: ' . $template->post_title . ' (ID: ' . $template->ID . ')');
                } else {
                    \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] ❌ Failed to snapshot template ID ' . $template->ID . ': ' . $wpdb->last_error);
                }
            }
        } else {
            \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] No header/footer (page_id=0) entries in this scan — skipping template snapshot');
        }

        \SEOAutoFix_Debug_Logger::log('[SKU] [SNAPSHOT] ✅ Created ' . $snapshot_count . ' snapshots (including templates)');
        \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] Created ' . $snapshot_count . ' snapshots (including templates)');

        // ===================================================================
        // Detect UNSNAPSHOTTED links: scan entries whose page_id has NO row
        // in the snapshot table.  These links cannot be undone — warn the user.
        // ===================================================================
        $unsnapshotted_links = array();

        // Collect every distinct page_id (> 0) that was scanned
        $scanned_page_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT found_on_page_id FROM {$table_results}
             WHERE scan_id = %s AND found_on_page_id > 0",
            $scan_id
        ));

        // Collect every page_id that WAS snapshotted
        $snapshotted_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT page_id FROM {$table_snapshot} WHERE scan_id = %s",
            $scan_id
        ));
        $snapshotted_ids = array_map('intval', $snapshotted_ids);

        // Find page_ids with at least one broken link that have no snapshot
        $missing_page_ids = array_diff(array_map('intval', $scanned_page_ids), $snapshotted_ids);

        if (!empty($missing_page_ids)) {
            foreach ($missing_page_ids as $missing_id) {
                // Get the broken links on this page that cannot be undone
                $links = $wpdb->get_results($wpdb->prepare(
                    "SELECT broken_url, anchor_text, location FROM {$table_results}
                     WHERE scan_id = %s AND found_on_page_id = %d",
                    $scan_id,
                    $missing_id
                ), ARRAY_A);

                foreach ($links as $link) {
                    $unsnapshotted_links[] = array(
                        'page_id' => $missing_id,
                        'page_title' => get_the_title($missing_id) ?: 'Page #' . $missing_id,
                        'broken_url' => $link['broken_url'],
                        'anchor_text' => $link['anchor_text'],
                        'location' => $link['location']
                    );
                }
            }
            \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] ⚠️ ' . count($unsnapshotted_links) . ' links across ' . count($missing_page_ids) . ' pages could NOT be snapshotted (builder plugin data?)');
        }

        // Also detect any header/footer (page_id=0) links still not covered
        $hf_unsnapped = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT r.broken_url, r.anchor_text, r.location
             FROM {$table_results} r
             WHERE r.scan_id = %s AND r.found_on_page_id = 0",
            $scan_id
        ), ARRAY_A);

        foreach ($hf_unsnapped as $hf_link) {
            // Check if any template snapshot was saved for this URL
            $covered = false;
            foreach ($snapshotted_ids as $snapped_id) {
                $snap_content_raw = $wpdb->get_var($wpdb->prepare(
                    "SELECT original_content FROM {$table_snapshot} WHERE scan_id = %s AND page_id = %d",
                    $scan_id,
                    $snapped_id
                ));
                if ($snap_content_raw && stripos($snap_content_raw, $hf_link['broken_url']) !== false) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $unsnapshotted_links[] = array(
                    'page_id' => 0,
                    'page_title' => 'Header / Footer',
                    'broken_url' => $hf_link['broken_url'],
                    'anchor_text' => $hf_link['anchor_text'],
                    'location' => $hf_link['location']
                );
                \SEOAutoFix_Debug_Logger::log('[SNAPSHOT] ⚠️ Header/footer link not covered by any template snapshot: ' . $hf_link['broken_url']);
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(__('Snapshot created for %d pages/templates', 'seo-autofix-pro'), $snapshot_count),
            'snapshot_count' => $snapshot_count,
            'unsnapshotted_links' => $unsnapshotted_links
        ));
    }

    /**
     * AJAX: Undo all changes - restore from snapshot (TRANSACTIONAL)
     */
    public function ajax_undo_changes()
    {
        \SEOAutoFix_Debug_Logger::log('[UNDO] ========== UNDO CHANGES CALLED (TRANSACTIONAL) ==========');

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

        // --- Restore each page with verification and retry ---
        $restored_count = 0;
        $failed_count = 0;
        $verified_page_ids = array();
        $failed_pages = array();
        $max_attempts = 3; // Up to 2 retries for undo

        foreach ($snapshots as $snapshot) {
            $page_id = intval($snapshot['page_id']);
            $original_content = $snapshot['original_content'];
            $lock_key = 'seoautofix_lock_' . $page_id;

            // --- Per-page lock ---
            if (get_transient($lock_key)) {
                \SEOAutoFix_Debug_Logger::log('[UNDO] ⚠️ Lock active for page ' . $page_id . ' — skipping');
                $failed_count++;
                $failed_pages[] = $page_id;
                continue;
            }

            set_transient($lock_key, time(), 10);

            try {
                // Decode snapshot to check if Elementor
                $snapshot_data = json_decode($original_content, true);
                $is_elementor = $snapshot_data && isset($snapshot_data['is_elementor']) && $snapshot_data['is_elementor'];

                $page_verified = false;
                $page_attempts = 0;

                for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
                    $page_attempts = $attempt;

                    if ($attempt > 1) {
                        \SEOAutoFix_Debug_Logger::log('[UNDO] ⏳ Retry #' . ($attempt - 1) . ' for page ' . $page_id . ' after 150ms delay...');
                        usleep(150000);
                    }

                    // --- Restore content ---
                    if ($is_elementor) {
                        \SEOAutoFix_Debug_Logger::log('[UNDO] Restoring Elementor page ' . $page_id . ' (attempt ' . $attempt . ')');

                        $updated = wp_update_post(array(
                            'ID' => $page_id,
                            'post_content' => $snapshot_data['post_content']
                        ), true);

                        if (!is_wp_error($updated)) {
                            update_post_meta($page_id, '_elementor_data', wp_slash($snapshot_data['elementor_data']));
                        } else {
                            \SEOAutoFix_Debug_Logger::log('[UNDO] ❌ wp_update_post failed: ' . $updated->get_error_message());
                            continue;
                        }
                    } else {
                        \SEOAutoFix_Debug_Logger::log('[UNDO] Restoring regular page ' . $page_id . ' (attempt ' . $attempt . ')');

                        $updated = wp_update_post(array(
                            'ID' => $page_id,
                            'post_content' => $original_content
                        ), true);

                        if (is_wp_error($updated)) {
                            \SEOAutoFix_Debug_Logger::log('[UNDO] ❌ wp_update_post failed: ' . $updated->get_error_message());
                            continue;
                        }
                    }

                    // --- VERIFICATION: re-read and compare ---
                    clean_post_cache($page_id);
                    $restored_post = get_post($page_id);

                    if (!$restored_post) {
                        \SEOAutoFix_Debug_Logger::log('[UNDO] ⚠️ Could not re-read post ' . $page_id . ' for verification');
                        continue;
                    }

                    if ($is_elementor) {
                        // Verify both post_content and _elementor_data
                        $content_match = ($restored_post->post_content === $snapshot_data['post_content']);
                        $elementor_raw = get_post_meta($page_id, '_elementor_data', true);
                        $elementor_match = ($elementor_raw === $snapshot_data['elementor_data']);

                        if ($content_match && $elementor_match) {
                            \SEOAutoFix_Debug_Logger::log('[UNDO] ✅ Elementor page ' . $page_id . ' verified (attempt ' . $attempt . ')');
                            $page_verified = true;
                            break;
                        }
                        \SEOAutoFix_Debug_Logger::log('[UNDO] ❌ Elementor verification failed for page ' . $page_id .
                            ' (content_match: ' . ($content_match ? 'true' : 'false') .
                            ', elementor_match: ' . ($elementor_match ? 'true' : 'false') . ')');
                    } else {
                        // Verify post_content
                        if ($restored_post->post_content === $original_content) {
                            \SEOAutoFix_Debug_Logger::log('[UNDO] ✅ Regular page ' . $page_id . ' verified (attempt ' . $attempt . ')');
                            $page_verified = true;
                            break;
                        }
                        \SEOAutoFix_Debug_Logger::log('[UNDO] ❌ Content verification failed for page ' . $page_id);
                    }
                }

                if ($page_verified) {
                    $restored_count++;
                    $verified_page_ids[] = $page_id;

                    // Clear caches after verified restore
                    Builder_Replacement_Engine::clear_all_caches($page_id, 'undo');

                    \SEOAutoFix_Debug_Logger::log('[UNDO] ✅ Page ' . $page_id . ' restored and verified after ' . $page_attempts . ' attempt(s)');
                } else {
                    $failed_count++;
                    $failed_pages[] = $page_id;
                    \SEOAutoFix_Debug_Logger::log('[UNDO] ❌ Page ' . $page_id . ' FAILED verification after ' . $page_attempts . ' attempts — NOT resetting table state');
                }
            } finally {
                // ALWAYS release lock
                delete_transient($lock_key);
                \SEOAutoFix_Debug_Logger::log('[UNDO] 🔓 Lock released for page ' . $page_id);
            }
        }

        \SEOAutoFix_Debug_Logger::log('[UNDO] Snapshot preserved for potential future undos');

        // ===== TRANSACTIONAL: Only reset table state for VERIFIED pages =====
        $table_activity = $wpdb->prefix . 'seoautofix_broken_links_activity';
        $table_scan_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';

        if (!empty($verified_page_ids)) {
            $placeholders = implode(',', array_fill(0, count($verified_page_ids), '%d'));

            \SEOAutoFix_Debug_Logger::log('[UNDO] Resetting table state for verified pages: ' . implode(',', $verified_page_ids));

            // Mark broken links as unfixed AND undeleted ONLY for verified pages
            $results_updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table_scan_results} SET is_fixed = 0, is_deleted = 0 WHERE scan_id = %s AND found_on_page_id IN ($placeholders)",
                array_merge(array($scan_id), $verified_page_ids)
            ));

            \SEOAutoFix_Debug_Logger::log('[UNDO] Marked ' . $results_updated . ' entries as unfixed and undeleted');

            // --- Fresh undo activity log entries ---
            foreach ($verified_page_ids as $v_page_id) {
                $wpdb->insert($table_activity, array(
                    'scan_id' => $scan_id,
                    'entry_id' => 0,
                    'broken_url' => '',
                    'replacement_url' => NULL,
                    'action_type' => 'undo',
                    'page_url' => get_permalink($v_page_id) ?: '',
                    'page_title' => get_the_title($v_page_id) ?: ''
                ), array('%s', '%d', '%s', '%s', '%s', '%s', '%s'));
            }

            \SEOAutoFix_Debug_Logger::log('[UNDO] Inserted ' . count($verified_page_ids) . ' undo activity log entries');
        }

        // ===================================================================
        // FIX: Also reset header/footer rows (found_on_page_id = 0)
        // These belong to Elementor templates that were restored above.
        // The old code never reset these rows, leaving them as "deleted" forever.
        // ===================================================================
        if ($restored_count > 0) {
            $hf_updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table_scan_results} SET is_fixed = 0, is_deleted = 0 WHERE scan_id = %s AND found_on_page_id = 0",
                $scan_id
            ));
            \SEOAutoFix_Debug_Logger::log('[UNDO] ✅ Reset ' . $hf_updated . ' header/footer entries (page_id=0) to unfixed/undeleted');
        }

        // Build response
        $response = array(
            'message' => sprintf(
                __('Restored %d page(s) to original state. %d failed.', 'seo-autofix-pro'),
                $restored_count,
                $failed_count
            ),
            'operation' => 'undo',
            'restored_count' => $restored_count,
            'failed_count' => $failed_count,
            'verified_pages' => $verified_page_ids,
            'failed_pages' => $failed_pages
        );

        if ($failed_count > 0) {
            \SEOAutoFix_Debug_Logger::log('[UNDO] ⚠️ Partial undo — ' . $failed_count . ' page(s) failed verification');
        }

        wp_send_json_success($response);
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
     * AJAX: Extract links from storage (builder-aware, no HTML fetch)
     * Receives a batch of page objects from the JS scan loop and returns
     * all links extracted from storage, in the same flat shape that the
     * old JS fetchPageHTML / extractLinksFromHTML combination produced.
     *
     * POST params:
     *   nonce  – seoautofix_broken_urls_nonce
     *   pages  – JSON-encoded array of {url, page_id, page_title}
     *   scan_id – current scan ID (used for header/footer singleton tracking)
     */
    public function ajax_extract_links_from_storage()
    {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $pages_json = isset($_POST['pages']) ? wp_unslash($_POST['pages']) : '[]';
        $pages = json_decode($pages_json, true);
        $scan_id = isset($_POST['scan_id']) ? sanitize_text_field($_POST['scan_id']) : '';

        if (!is_array($pages)) {
            wp_send_json_error(array('message' => 'Invalid pages data'));
        }

        \SEOAutoFix_Debug_Logger::log('[EXTRACT_STORAGE] Called for ' . count($pages) . ' pages, scan_id: ' . $scan_id);

        $crawler = new Link_Crawler();
        $site_url = home_url('/');
        $all_links = array();

        // ── Per-page extraction ─────────────────────────────────────────────
        foreach ($pages as $page_data) {
            $page_id = isset($page_data['page_id']) ? intval($page_data['page_id']) : 0;
            $page_url = isset($page_data['url']) ? esc_url_raw($page_data['url']) : '';
            $page_title = isset($page_data['page_title']) ? sanitize_text_field($page_data['page_title']) : '';

            if (empty($page_url)) {
                continue;
            }

            \SEOAutoFix_Debug_Logger::log('[EXTRACT_STORAGE] Processing page: ' . $page_url . ' (ID: ' . $page_id . ')');

            $page_links = $crawler->extract_links_from_storage($page_id, $page_title, $page_url);

            \SEOAutoFix_Debug_Logger::log('[EXTRACT_STORAGE] Page ' . $page_id . ': ' . count($page_links) . ' links extracted');

            $all_links = array_merge($all_links, $page_links);
        }

        // ── Nav menus (site-wide — append once; JS deduplication by url+location handles the rest) ─
        // We use a transient so nav menu links are only injected on the FIRST batch of each scan.
        if (!empty($scan_id)) {
            $nav_done_key = 'seoautofix_nav_extracted_' . $scan_id;
            if (!get_transient($nav_done_key)) {
                $nav_links = $crawler->extract_links_from_nav_menus();
                $all_links = array_merge($all_links, $nav_links);
                \SEOAutoFix_Debug_Logger::log('[EXTRACT_STORAGE] Nav menus added: ' . count($nav_links) . ' links');

                $hf_links = $crawler->extract_links_from_hf_templates();
                $all_links = array_merge($all_links, $hf_links);
                \SEOAutoFix_Debug_Logger::log('[EXTRACT_STORAGE] HF templates added: ' . count($hf_links) . ' links');

                set_transient($nav_done_key, 1, DAY_IN_SECONDS);
            }
        }

        // ── Normalise & deduplicate by (url , location) ─────────────────────
        $seen = array();
        $deduplicated = array();
        foreach ($all_links as $link) {
            // Ensure required keys exist
            $link = array_merge(array(
                'url' => '',
                'found_on_url' => $site_url,
                'found_on_page_id' => 0,
                'found_on_page_title' => '',
                'location' => 'content',
                'anchor_text' => '',
                'builder' => 'classic',
                'dynamic_source' => false,
            ), $link);

            $url = trim($link['url']);
            if (empty($url)) {
                continue;
            }

            // Determine link_type
            $link['link_type'] = (strpos($url, $site_url) === 0 || strpos($url, home_url()) === 0)
                ? 'internal'
                : 'external';

            // Deduplicate within this batch by url+location to prevent DB bloat.
            // (Header/footer global dedup is handled in JS using seenHeaderFooterLinks.)
            $dedup_key = md5($url . '|' . $link['location'] . '|' . $link['found_on_url']);
            if (isset($seen[$dedup_key])) {
                continue;
            }
            $seen[$dedup_key] = true;

            $deduplicated[] = $link;
        }

        \SEOAutoFix_Debug_Logger::log('[EXTRACT_STORAGE] Total links after dedup: ' . count($deduplicated));

        wp_send_json_success(array(
            'links' => $deduplicated,
            'count' => count($deduplicated),
        ));
    }

    /**
     * AJAX: Test URL proxy (Frontend-driven scanning v3.0)
     * Proxy endpoint to test URLs from frontend (bypasses CORS)
     */
    public function ajax_test_url_proxy()
    {
        // Increase PHP execution time for slow external URLs
        @set_time_limit(180); // 3 minutes for slow external services

        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $anchor_text = isset($_POST['anchor_text']) ? sanitize_text_field($_POST['anchor_text']) : '';

        if (empty($url)) {
            wp_send_json_error(array('message' => __('URL is required', 'seo-autofix-pro')));
        }

        \SEOAutoFix_Debug_Logger::log('[TEST URL PROXY] Testing URL: ' . $url);
        if ($anchor_text) {
            \SEOAutoFix_Debug_Logger::log('[TEST URL PROXY] Anchor text: ' . $anchor_text);
        }

        // Test the URL using Link_Tester
        $link_tester = new Link_Tester();
        $result = $link_tester->test_url($url);

        \SEOAutoFix_Debug_Logger::log('[TEST URL PROXY] Result for ' . $url . ': Status ' . $result['status_code'] . ', Broken: ' . ($result['is_broken'] ? 'yes' : 'no'));

        // Generate suggestion if broken and internal
        $suggested_url = null;

        if ($result['is_broken']) {
            // Initialize URL_Similarity if not already done
            if ($this->url_similarity === null) {
                $this->url_similarity = new URL_Similarity();
            }

            // Check if URL is internal
            if ($this->url_similarity->is_internal_url($url)) {
                \SEOAutoFix_Debug_Logger::log('[SUGGESTION] Processing broken internal URL: ' . $url);

                // Get cached internal links
                $internal_links_cache = $this->get_internal_links_cache();
                $valid_urls = array_keys($internal_links_cache);

                \SEOAutoFix_Debug_Logger::log('[SUGGESTION] Using ' . count($valid_urls) . ' valid internal URLs for suggestion matching');

                // Generate suggestion
                $match = $this->url_similarity->find_closest_match(
                    $url,
                    $valid_urls,
                    $anchor_text,
                    $internal_links_cache
                );

                $suggested_url = $match['url'];
                $suggestion_score = isset($match['score']) ? $match['score'] : 0;

                if ($suggested_url) {
                    \SEOAutoFix_Debug_Logger::log('[SUGGESTION] ✅ Generated suggestion: ' . $suggested_url . ' (Score: ' . $suggestion_score . ')');
                } else {
                    \SEOAutoFix_Debug_Logger::log('[SUGGESTION] ❌ No suggestion generated (best score: ' . $suggestion_score . ' < 60% threshold)');
                }
            } else {
                \SEOAutoFix_Debug_Logger::log('[SUGGESTION] Skipping suggestion - external URL');
            }
        }

        // Add suggested_url to response
        $result['suggested_url'] = $suggested_url;

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

            // Auto-detect builder type during scan for deterministic operations later
            $page_id_for_builder = isset($link['found_on_page_id']) ? intval($link['found_on_page_id']) : 0;
            if ($page_id_for_builder > 0) {
                $link_data['builder_type'] = Builder_Detector::detect($page_id_for_builder);
                \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Detected builder_type: ' . ($link_data['builder_type'] ?: 'none'));
            }

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

        // ✅ FIX: Get actual count from database and update the scans table
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Getting actual broken link count from database...');
        global $wpdb;
        $results_table = $wpdb->prefix . 'seoautofix_broken_links_scan_results';
        $actual_broken_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$results_table} WHERE scan_id = %s AND is_fixed = 0",
            $scan_id
        ));
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Actual broken count from COUNT query: ' . $actual_broken_count);

        // ✅ Update the scans table with the correct count
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Updating scans table with broken count: ' . $actual_broken_count);
        $update_result = $db_manager->update_scan($scan_id, array(
            'total_broken_links' => intval($actual_broken_count)
        ));
        \SEOAutoFix_Debug_Logger::log('[SAVE BROKEN LINKS BATCH] Update scans table result: ' . ($update_result ? 'SUCCESS' : 'FAILED'));

        // ✅ Return saved broken links with IDs
        wp_send_json_success(array(
            'saved_count' => $saved_count,
            'total_broken' => intval($actual_broken_count),
            'broken_links' => $saved_broken_links, // ✅ NEW: Include saved links with IDs
            'message' => sprintf(__('Saved %d broken links', 'seo-autofix-pro'), $saved_count)
        ));
    }

    /**
     * Get internal links cache for suggestion generation
     * Caches valid internal URLs with titles to avoid repeated queries
     * 
     * @return array Associative array mapping URLs to page titles
     */
    private function get_internal_links_cache()
    {
        // Return cached data if already loaded
        if ($this->internal_links_cache !== null) {
            \SEOAutoFix_Debug_Logger::log('[SUGGESTION CACHE] Using cached internal links (' . count($this->internal_links_cache) . ' URLs)');
            return $this->internal_links_cache;
        }

        \SEOAutoFix_Debug_Logger::log('[SUGGESTION CACHE] Loading internal valid links from database...');

        // Query all published posts and pages
        $args = array(
            'post_type' => array('post', 'page'),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        $posts = get_posts($args);

        $valid_urls_with_titles = array();

        foreach ($posts as $post_id) {
            $url = get_permalink($post_id);
            $title = get_the_title($post_id);

            if ($url && $title) {
                $valid_urls_with_titles[$url] = $title;
            }
        }

        // Add home page
        $home_url = home_url('/');
        $site_name = get_bloginfo('name');
        $valid_urls_with_titles[$home_url] = $site_name;

        \SEOAutoFix_Debug_Logger::log('[SUGGESTION CACHE] Cached ' . count($valid_urls_with_titles) . ' internal links with titles');

        $this->internal_links_cache = $valid_urls_with_titles;
        return $this->internal_links_cache;
    }
}

// Module will be auto-instantiated by the WordPress plugin loader

