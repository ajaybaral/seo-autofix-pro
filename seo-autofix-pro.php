<?php
/**
 * Plugin Name: SEO AutoFix Pro
 * Plugin URI: https://seoautofixpro.com
 * Description: AI-powered SEO automation for WordPress. Detects and fixes SEO issues automatically using OpenAI.
 * Version: 1.4.8
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
define('SEOAUTOFIX_PLUGIN_FILE', __FILE__);
define('SEOAUTOFIX_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SEOAUTOFIX_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SEOAUTOFIX_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Read version from plugin header — single source of truth.
if (!function_exists('get_plugin_data')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$_seoautofix_data = get_plugin_data(__FILE__, false, false);
define('SEOAUTOFIX_VERSION', $_seoautofix_data['Version'] ?? '1.4.8');
unset($_seoautofix_data);

// Asset version = file modification time of the main plugin file.
// This changes automatically every time a new zip is uploaded/extracted,
// ensuring browsers always fetch the latest JS/CSS.
define('SEOAUTOFIX_ASSET_VERSION', filemtime(__FILE__) . '-' . SEOAUTOFIX_VERSION);

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
        // Always prevent browser caching of our plugin admin pages and assets.
        // This is the primary defence against stale JS/CSS after a plugin update.
        $this->disable_caching();

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
     * Disable caching for plugin assets and admin pages.
     * Runs unconditionally so a freshly uploaded plugin is always served.
     */
    private function disable_caching()
    {
        // Send no-cache HTTP headers for every plugin admin page.
        add_action('admin_init', function () {
            if (isset($_GET['page']) && strpos($_GET['page'], 'seoautofix') !== false) {
                nocache_headers();
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Cache-Control: post-check=0, pre-check=0', false);
                header('Pragma: no-cache');
                header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
            }
        }, 1);

        // Replace the ?ver= query string on every plugin script/style with the
        // file's actual mtime so the browser fetches a fresh copy after each upload.
        add_filter('script_loader_src', array($this, 'add_cache_buster'), 10, 2);
        add_filter('style_loader_src', array($this, 'add_cache_buster'), 10, 2);
    }

    /**
     * Replace wp_enqueue version strings with per-file mtime for plugin assets.
     * filemtime() gives a unique string any time the file on disk changes,
     * which happens automatically when a new zip is extracted.
     */
    public function add_cache_buster($src, $handle)
    {
        if (strpos($src, 'seo-autofix-pro') === false) {
            return $src;
        }

        // Derive the filesystem path from the URL so we can stat the file.
        $plugin_url  = untrailingslashit(SEOAUTOFIX_PLUGIN_URL);
        $plugin_path = untrailingslashit(SEOAUTOFIX_PLUGIN_DIR);

        // Strip existing ?ver=... query string first.
        $src_no_qs = preg_replace('/[?&]ver=[^&]*/', '', $src);

        // Build filesystem path.
        $file_path = str_replace($plugin_url, $plugin_path, strtok($src_no_qs, '?'));
        $file_path = wp_normalize_path($file_path);

        if (file_exists($file_path)) {
            $mtime = filemtime($file_path);
        } else {
            // Fallback: use the activation-time token stored in the DB.
            $mtime = get_option('seoautofix_asset_version', SEOAUTOFIX_ASSET_VERSION);
        }

        $separator = (strpos($src_no_qs, '?') !== false) ? '&' : '?';
        return $src_no_qs . $separator . 'ver=' . $mtime;
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
        // Clear all stale debug logs on (re)activation so the plugin always
        // starts fresh — no old session entries will remain.
        if (class_exists('SEOAutoFix_Debug_Logger')) {
            \SEOAutoFix_Debug_Logger::clear_all();
        }

        // ------------------------------------------------------------------
        // CACHE BUSTING: store a fresh, unique asset-version token.
        // Every (re)activation writes a new value, so every enqueue call
        // will pick up the new version string and the browser is forced to
        // discard its cached JS/CSS files.
        // ------------------------------------------------------------------
        $asset_version = SEOAUTOFIX_VERSION . '.' . filemtime(__FILE__) . '.' . time();
        update_option('seoautofix_asset_version', $asset_version, false);

        // Clear any WordPress object/transient caches that might hold old
        // script handles or admin page output.
        wp_cache_flush();

        // Delete all plugin-related transients.
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_seoautofix_%'
                OR option_name LIKE '_transient_timeout_seoautofix_%'"
        );

        // Set plugin activation flag
        update_option('seoautofix_activated', true);
        update_option('seoautofix_version', SEOAUTOFIX_VERSION);
        update_option('seoautofix_db_version', SEOAUTOFIX_VERSION);

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
