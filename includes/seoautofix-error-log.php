<?php
/**
 * Plugin-specific error_log wrapper
 * 
 * Writes to plugin's custom log file, works even when WP_DEBUG is disabled
 * 
 * @package SEO_AutoFix_Pro
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Write to plugin's debug log
 * 
 * Works exactly like error_log() but writes to plugin's log file
 * Works even when WP_DEBUG is disabled
 * 
 * @param string $message Log message
 */
function seoautofix_error_log($message) {
    $log_file = WP_CONTENT_DIR . '/plugins/seo-autofix-pro/logs/debug.log';
    $log_dir = dirname($log_file);
    
    // Create logs directory if it doesn't exist
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
        
        // Create security files
        file_put_contents($log_dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
        file_put_contents($log_dir . '/index.php', '<?php // Silence is golden');
    }
    
    // Format message with timestamp
    $timestamp = current_time('mysql');
    $formatted_message = '[' . $timestamp . '] ' . $message . "\n";
    
    // Write to plugin log file
    @file_put_contents($log_file, $formatted_message, FILE_APPEND | LOCK_EX);
    
    // Also write to WordPress debug.log if WP_DEBUG is enabled
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log($message);
    }
}
