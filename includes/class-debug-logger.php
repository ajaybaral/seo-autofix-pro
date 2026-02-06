<?php
/**
 * Debug Logger for SEO AutoFix Pro
 * 
 * Custom logging system that writes to plugin-specific log files
 * viewable from the WordPress admin panel
 * 
 * @package SEO_AutoFix_Pro
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SEO_AutoFix_Debug_Logger
{
    /**
     * Log file path
     */
    private $log_file;

    /**
     * Module name
     */
    private $module;

    /**
     * Maximum log file size (10MB)
     */
    const MAX_LOG_SIZE = 10485760;

    /**
     * Constructor
     * 
     * @param string $module Module name (IMAGE-SEO, BROKEN-URL, CORE, etc.)
     */
    public function __construct($module = 'CORE')
    {
        $this->module = strtoupper($module);
        $this->log_file = $this->get_log_file_path();
        $this->ensure_log_directory();
        $this->rotate_log_if_needed();
    }

    /**
     * Log an error message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public function error($message, $context = [])
    {
        $this->log('ERROR', $message, $context);
    }

    /**
     * Log a warning message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public function warning($message, $context = [])
    {
        $this->log('WARNING', $message, $context);
    }

    /**
     * Log an info message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public function info($message, $context = [])
    {
        $this->log('INFO', $message, $context);
    }

    /**
     * Log a debug message
     * 
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public function debug($message, $context = [])
    {
        $this->log('DEBUG', $message, $context);
    }

    /**
     * Write log entry to file
     * 
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context data
     */
    private function log($level, $message, $context = [])
    {
        $timestamp = current_time('Y-m-d H:i:s');
        
        // Build context string
        $context_str = '';
        if (!empty($context)) {
            $context_str = ' | Context: ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        // Format: [2026-02-07 00:08:17] [ERROR] [IMAGE-SEO] Message | Context: {...}
        $log_entry = "[{$timestamp}] [{$level}] [{$this->module}] {$message}{$context_str}\n";

        // Write to file with file locking
        if (file_exists($this->log_file)) {
            file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
        } else {
            // Create file if it doesn't exist
            file_put_contents($this->log_file, $log_entry, LOCK_EX);
        }

        // Also log to WordPress debug.log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[SEO-AutoFix-Pro] [{$level}] [{$this->module}] {$message}");
        }
    }

    /**
     * Get log file path
     * 
     * @return string Log file path
     */
    private function get_log_file_path()
    {
        $log_dir = plugin_dir_path(dirname(__FILE__)) . 'logs';
        return $log_dir . '/debug.log';
    }

    /**
     * Ensure log directory exists with security files
     */
    private function ensure_log_directory()
    {
        $log_dir = plugin_dir_path(dirname(__FILE__)) . 'logs';

        // Create directory if it doesn't exist
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        // Create .htaccess to prevent direct access
        $htaccess_file = $log_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            $htaccess_content = "Order deny,allow\nDeny from all\n";
            file_put_contents($htaccess_file, $htaccess_content);
        }

        // Create index.php to prevent directory listing
        $index_file = $log_dir . '/index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden');
        }
    }

    /**
     * Rotate log file if it exceeds max size
     */
    private function rotate_log_if_needed()
    {
        if (!file_exists($this->log_file)) {
            return;
        }

        $file_size = filesize($this->log_file);

        if ($file_size > self::MAX_LOG_SIZE) {
            // Archive current log with timestamp
            $log_dir = dirname($this->log_file);
            $archive_name = 'debug-' . date('Y-m-d-His') . '.log';
            $archive_path = $log_dir . '/' . $archive_name;

            rename($this->log_file, $archive_path);

            // Clean up old archives (keep last 5)
            $this->cleanup_old_archives($log_dir);
        }
    }

    /**
     * Clean up old log archives
     * 
     * @param string $log_dir Log directory path
     */
    private function cleanup_old_archives($log_dir)
    {
        $archives = glob($log_dir . '/debug-*.log');

        if (count($archives) > 5) {
            // Sort by modification time
            usort($archives, function ($a, $b) {
                return filemtime($b) - filemtime($a);
            });

            // Delete oldest archives
            $archives_to_delete = array_slice($archives, 5);
            foreach ($archives_to_delete as $archive) {
                unlink($archive);
            }
        }
    }

    /**
     * Get instance for a specific module
     * 
     * @param string $module Module name
     * @return SEO_AutoFix_Debug_Logger
     */
    public static function get_logger($module = 'CORE')
    {
        return new self($module);
    }
}
