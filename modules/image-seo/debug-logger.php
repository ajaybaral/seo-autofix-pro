<?php
/**
 * Custom Debug Logger
 * Writes logs directly to plugin directory when server logging is disabled
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOAutoFix_Debug_Logger {
    private static $log_file = null;
    
    public static function init() {
        if (self::$log_file === null) {
            self::$log_file = SEOAUTOFIX_PLUGIN_DIR . 'modules/image-seo/scan-debug.log';
            
            // Clear old log on first init
            if (file_exists(self::$log_file)) {
                // Keep only last 500 lines
                $lines = file(self::$log_file);
                if (count($lines) > 500) {
                    file_put_contents(self::$log_file, implode('', array_slice($lines, -500)));
                }
            }
        }
    }
    
    public static function log($message) {
        self::init();
        
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] {$message}\n";
        
        file_put_contents(self::$log_file, $log_entry, FILE_APPEND);
    }
    
    public static function clear() {
        self::init();
        file_put_contents(self::$log_file, '');
    }
    
    public static function get_log_path() {
        self::init();
        return self::$log_file;
    }
}
