<?php
/**
 * SEO AutoFix Pro - Custom Debug Logger
 * Writes logs directly to plugin directory when server logging is disabled
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOAutoFix_Debug_Logger {
    private static $log_file = null;
    private static $module = 'general';
    
    public static function init($module = 'general') {
        self::$module = $module;
        self::$log_file = SEOAUTOFIX_PLUGIN_DIR . 'debug-' . $module . '.log';
    }
    
    public static function log($message, $module = null) {
        if ($module !== null) {
            self::init($module);
        } elseif (self::$log_file === null) {
            self::init();
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$module}] {$message}\n";
        
        @file_put_contents(self::$log_file, $log_entry, FILE_APPEND);
    }
    
    public static function clear($module = 'general') {
        self::init($module);
        @file_put_contents(self::$log_file, '');
    }
    
    public static function get_log_path($module = 'general') {
        self::init($module);
        return self::$log_file;
    }
    
    public static function get_all_logs() {
        $logs = array();
        $pattern = SEOAUTOFIX_PLUGIN_DIR . 'debug-*.log';
        $files = glob($pattern);
        
        if ($files) {
            foreach ($files as $file) {
                if (file_exists($file) && filesize($file) > 0) {
                    $module = basename($file, '.log');
                    $module = str_replace('debug-', '', $module);
                    $logs[$module] = $file;
                }
            }
        }
        
        return $logs;
    }
}
