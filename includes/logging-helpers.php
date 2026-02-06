<?php
/**
 * Helper function for logging
 * 
 * Makes it easy to log from anywhere in the plugin
 * 
 * @package SEO_AutoFix_Pro
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log a message to the debug log
 * 
 * Usage:
 * seoautofix_log('IMAGE-SEO', 'ERROR', 'Scan failed', ['post_id' => 123]);
 * seoautofix_log('IMAGE-SEO', 'INFO', 'Scan started');
 * 
 * @param string $module Module name (IMAGE-SEO, BROKEN-URL, etc.)
 * @param string $level Log level (ERROR, WARNING, INFO, DEBUG)
 * @param string $message Log message
 * @param array $context Optional context data
 */
function seoautofix_log($module, $level, $message, $context = [])
{
    static $logger_class_loaded = false;

    // Load logger class if not already loaded
    if (!$logger_class_loaded) {
        $logger_file = plugin_dir_path(__FILE__) . 'class-debug-logger.php';
        if (file_exists($logger_file)) {
            require_once $logger_file;
            $logger_class_loaded = true;
        } else {
            // Fallback to standard error_log if logger class not found
            error_log("[SEO-AutoFix-Pro] [{$level}] [{$module}] {$message}");
            return;
        }
    }

    // Create logger and log message
    $logger = SEO_AutoFix_Debug_Logger::get_logger($module);

    switch (strtoupper($level)) {
        case 'ERROR':
            $logger->error($message, $context);
            break;
        case 'WARNING':
            $logger->warning($message, $context);
            break;
        case 'INFO':
            $logger->info($message, $context);
            break;
        case 'DEBUG':
            $logger->debug($message, $context);
            break;
        default:
            $logger->info($message, $context);
    }
}

/**
 * Quick logging shortcuts
 */
function seoautofix_error($module, $message, $context = [])
{
    seoautofix_log($module, 'ERROR', $message, $context);
}

function seoautofix_warning($module, $message, $context = [])
{
    seoautofix_log($module, 'WARNING', $message, $context);
}

function seoautofix_info($module, $message, $context = [])
{
    seoautofix_log($module, 'INFO', $message, $context);
}

function seoautofix_debug($module, $message, $context = [])
{
    seoautofix_log($module, 'DEBUG', $message, $context);
}
