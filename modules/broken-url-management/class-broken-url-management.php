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
class SEOAutoFix_Broken_Url_Management {
    
    /**
     * Module version
     */
    const VERSION = '1.0.0';
    
    /**
     * Database table names
     */
    private $table_scans;
    private $table_results;
    
    /**
     * Constructor
     */
    public function __construct() {
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
    private function init() {
        // Create database tables on activation
        add_action('seoautofix_activated', array($this, 'create_database_tables'));
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Enqueue admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Register AJAX endpoints
        $this->register_ajax_endpoints();
        
        // Load required files
        $this->load_dependencies();
    }
    
    /**
     * Load module dependencies
     */
    private function load_dependencies() {
        $module_dir = dirname(__FILE__);
        
        // Load helper classes
        require_once $module_dir . '/class-database-manager.php';
        require_once $module_dir . '/class-link-crawler.php';
        require_once $module_dir . '/class-link-tester.php';
        require_once $module_dir . '/class-url-similarity.php';
        require_once $module_dir . '/class-link-analyzer.php';
    }
    
    /**
     * Create database tables
     */
    public function create_database_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Scans table
        $sql_scans = "CREATE TABLE IF NOT EXISTS {$this->table_scans} (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scan_id VARCHAR(50) UNIQUE NOT NULL,
            total_urls_found INT DEFAULT 0,
            total_urls_tested INT DEFAULT 0,
            total_broken_links INT DEFAULT 0,
            status ENUM('in_progress', 'completed', 'failed') DEFAULT 'in_progress',
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            INDEX idx_scan_id (scan_id),
            INDEX idx_status (status)
        ) $charset_collate;";
        
        // Results table
        $sql_results = "CREATE TABLE IF NOT EXISTS {$this->table_results} (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scan_id VARCHAR(50) NOT NULL,
            found_on_url TEXT NOT NULL,
            broken_url TEXT NOT NULL,
            link_type ENUM('internal', 'external') NOT NULL,
            status_code INT NOT NULL,
            suggested_url TEXT NULL,
            user_modified_url TEXT NULL,
            reason TEXT NOT NULL,
            is_fixed TINYINT(1) DEFAULT 0,
            is_deleted TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_scan_id (scan_id),
            INDEX idx_link_type (link_type),
            INDEX idx_status_code (status_code),
            INDEX idx_is_fixed (is_fixed)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_scans);
        dbDelta($sql_results);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
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
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'seo-autofix-pro'));
        }
        
        // Include view template
        include dirname(__FILE__) . '/views/admin-page.php';
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
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
    private function register_ajax_endpoints() {
        // Start scan
        add_action('wp_ajax_seoautofix_broken_links_start_scan', array($this, 'ajax_start_scan'));
        
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
    }
    
    /**
     * AJAX: Start new scan
     */
    public function ajax_start_scan() {
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
     * AJAX: Get scan progress
     */
    public function ajax_get_progress() {
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
    public function ajax_get_results() {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }
        
        $scan_id = isset($_GET['scan_id']) ? sanitize_text_field($_GET['scan_id']) : '';
        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
        
        if (empty($scan_id)) {
            wp_send_json_error(array('message' => __('Invalid scan ID', 'seo-autofix-pro')));
        }
        
        $db_manager = new Database_Manager();
        $results = $db_manager->get_scan_results($scan_id, $filter, $search, $page, $per_page);
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: Update suggestion
     */
    public function ajax_update_suggestion() {
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
    public function ajax_delete_entry() {
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
    public function ajax_apply_fixes() {
        check_ajax_referer('seoautofix_broken_urls_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'seo-autofix-pro')));
        }
        
        $ids = isset($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : array();
        
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
}

// Module will be auto-instantiated by the WordPress plugin loader

