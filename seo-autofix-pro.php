<?php
/**
 * Plugin Name: SEO AutoFix Pro
 * Plugin URI: https://seoautofixpro.com
 * Description: AI-powered SEO automation for WordPress. Detects and fixes SEO issues automatically using OpenAI.
 * Version: 1.3.1
 * Author: SEO AutoFix Pro Team
 * Author URI: https://seoautofixpro.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: seo-autofix-pro
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SEOAUTOFIX_VERSION', '1.0.0');
define('SEOAUTOFIX_PLUGIN_FILE', __FILE__);
define('SEOAUTOFIX_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SEOAUTOFIX_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SEOAUTOFIX_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Class
 */
class SEO_AutoFix_Pro
{

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Get single instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        // Load plugin files
        $this->includes();

        // Load modules BEFORE activation hooks so they can respond to activation
        $this->load_modules();

        // Initialize plugin
        add_action('plugins_loaded', array($this, 'init'));

        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Include required files
     */
    private function includes()
    {
        // Load debug logger first (works without WP_DEBUG)
        require_once SEOAUTOFIX_PLUGIN_DIR . 'includes/debug-logger.php';
        
        // Load global settings
        require_once SEOAUTOFIX_PLUGIN_DIR . 'settings.php';
    }

    /**
     * Initialize plugin
     */
    public function init()
    {
        // Prevent caching during development/debug mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->disable_caching();
        }

        // Load text domain for translations
        load_plugin_textdomain('seo-autofix-pro', false, dirname(SEOAUTOFIX_PLUGIN_BASENAME) . '/languages');
        
        // Hide admin notices on our plugin pages for cleaner UI
        add_action('admin_notices', array($this, 'hide_admin_notices'), 1);
        
        // Add comprehensive AJAX error logging
        add_action('wp_ajax_nopriv_seoautofix_broken_links_get_grouped_results', array($this, 'log_missing_ajax_endpoint'));
        add_action('wp_ajax_seoautofix_broken_links_get_grouped_results', array($this, 'log_missing_ajax_endpoint'));
        add_action('admin_init', array($this, 'add_ajax_logger'));
    }
    
    /**
     * Log when a missing AJAX endpoint is called
     */
    public function log_missing_ajax_endpoint() {
        \SEOAutoFix_Debug_Logger::log('========================================');
        \SEOAutoFix_Debug_Logger::log('[SEO AUTOFIX] ❌ MISSING AJAX ENDPOINT CALLED');
        \SEOAutoFix_Debug_Logger::log('[SEO AUTOFIX] Action: ' . ($_POST['action'] ?? $_GET['action'] ?? 'UNKNOWN'));
        \SEOAutoFix_Debug_Logger::log('[SEO AUTOFIX] Request Method: ' . $_SERVER['REQUEST_METHOD']);
        \SEOAutoFix_Debug_Logger::log('[SEO AUTOFIX] POST Data: ' . print_r($_POST, true));
        \SEOAutoFix_Debug_Logger::log('[SEO AUTOFIX] GET Data: ' . print_r($_GET, true));
        \SEOAutoFix_Debug_Logger::log('[SEO AUTOFIX] Referer: ' . ($_SERVER['HTTP_REFERER'] ?? 'NONE'));
        \SEOAutoFix_Debug_Logger::log('[SEO AUTOFIX] User Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'NONE'));
        \SEOAutoFix_Debug_Logger::log('========================================');
        
        wp_send_json_error(array(
            'message' => 'This AJAX endpoint does not exist. Check debug.log for details.',
            'endpoint' => $_POST['action'] ?? $_GET['action'] ?? 'UNKNOWN',
            'timestamp' => current_time('mysql')
        ));
    }
    
    /**
     * Add general AJAX logger to catch all broken URL requests
     */
    public function add_ajax_logger() {
        add_action('wp_ajax_' . '*', function() {
            $action = $_REQUEST['action'] ?? '';
            if (strpos($action, 'seoautofix_broken_links') !== false) {
                \SEOAutoFix_Debug_Logger::log('[SEO AUTOFIX] AJAX Request: ' . $action);
                \SEOAutoFix_Debug_Logger::log('[SEO AUTOFIX] Request Data: ' . print_r($_REQUEST, true));
            }
        }, 1);
    }

    /**
     * Disable caching for plugin assets and admin pages during development
     */
    private function disable_caching()
    {
        // Send no-cache headers for plugin admin pages
        add_action('admin_init', function () {
            // Only for our plugin pages
            if (isset($_GET['page']) && strpos($_GET['page'], 'seo-autofix') === 0) {
                nocache_headers();
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Cache-Control: post-check=0, pre-check=0', false);
                header('Pragma: no-cache');
                header('Expires: 0');
            }
        });

        // Add version parameter to all plugin scripts and styles to bust cache
        add_filter('script_loader_src', array($this, 'add_cache_buster'), 10, 2);
        add_filter('style_loader_src', array($this, 'add_cache_buster'), 10, 2);
    }

    /**
     * Add cache buster parameter to plugin assets
     */
    public function add_cache_buster($src, $handle)
    {
        // Only add cache buster to our plugin files
        if (strpos($src, 'seo-autofix-pro') !== false) {
            // Add timestamp as version parameter to force browser reload
            $separator = (strpos($src, '?') !== false) ? '&' : '?';
            return $src . $separator . 'ver=' . time();
        }
        return $src;
    }
    
    /**
     * Hide admin notices on plugin pages for cleaner UI
     */
    public function hide_admin_notices() {
        // Check if we're on one of our plugin pages
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        
        // List of our plugin page IDs
        $our_pages = array(
            'toplevel_page_seoautofix-settings',
            'seo-autofix-pro_page_seoautofix-broken-urls',
            'seo-autofix-pro_page_seoautofix-image-seo'
        );
        
        // If we're on one of our pages, remove all non-essential admin notices
        if (in_array($screen->id, $our_pages) || (isset($_GET['page']) && strpos($_GET['page'], 'seoautofix') !== false)) {
            remove_all_actions('admin_notices');
            remove_all_actions('all_admin_notices');
            
            // Only allow our own notices
            add_action('admin_notices', function() {
                settings_errors('seoautofix_messages');
            });
        }
    }

    /**
     * Auto-load all modules
     */
    private function load_modules()
    {
        $modules_dir = SEOAUTOFIX_PLUGIN_DIR . 'modules/';

        // Check if modules directory exists
        if (!is_dir($modules_dir)) {
            return;
        }

        // Get all module directories
        $modules = glob($modules_dir . '*', GLOB_ONLYDIR);

        if (empty($modules)) {
            return;
        }

        foreach ($modules as $module_path) {
            $module_name = basename($module_path);
            $main_file = $module_path . '/class-' . $module_name . '.php';

            // Check if main module file exists
            if (file_exists($main_file)) {
                require_once $main_file;

                // Initialize module class if it exists
                $class_name = $this->get_module_class_name($module_name);

                if (class_exists($class_name)) {
                    new $class_name();
                }
            }
        }
    }

    /**
     * Convert module folder name to class name
     * Example: image-seo -> SEOAutoFix\ImageSEO\SEOAutoFix_Image_SEO
     */
    private function get_module_class_name($module_name)
    {
        // Convert kebab-case to Title_Case for class name
        $parts = explode('-', $module_name);
        $parts = array_map('ucfirst', $parts);
        $class_name = 'SEOAutoFix_' . implode('_', $parts);

        // Convert kebab-case to CamelCase for namespace
        $namespace_parts = array_map('ucfirst', explode('-', $module_name));
        $namespace = implode('', $namespace_parts);

        // Return fully qualified namespaced class name
        return '\\SEOAutoFix\\' . $namespace . '\\' . $class_name;
    }

    /**
     * Plugin activation
     */
    public function activate()
    {
        // Set plugin activation flag
        update_option('seoautofix_activated', true);
        update_option('seoautofix_version', SEOAUTOFIX_VERSION);

        // Trigger module activation hooks
        do_action('seoautofix_activated');

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate()
    {
        // Trigger module deactivation hooks
        do_action('seoautofix_deactivated');

        // Clear scheduled events
        wp_clear_scheduled_hook('seoautofix_daily_tasks');

        // Flush rewrite rules
        flush_rewrite_rules();
    }
}

/**
 * Initialize the plugin
 */
function seoautofix_pro()
{
    return SEO_AutoFix_Pro::get_instance();
}

// Start the plugin
seoautofix_pro();
