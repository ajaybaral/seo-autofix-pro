<?php
/**
 * Log Reader for SEO AutoFix Pro
 * 
 * Reads and filters log entries from plugin log files
 * 
 * @package SEO_AutoFix_Pro
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SEO_AutoFix_Log_Reader
{
    /**
     * Get filtered logs
     * 
     * @param array $args Filter arguments
     * @return array Array of log entries
     */
    public function get_logs($args = [])
    {
        $defaults = [
            'level' => 'all',
            'module' => 'all',
            'search' => '',
            'limit' => 100,
            'offset' => 0,
            'date_from' => '',
            'date_to' => ''
        ];

        $args = wp_parse_args($args, $defaults);

        // Read log file
        $logs = $this->read_log_file();

        // Filter logs
        $logs = $this->filter_logs($logs, $args);

        // Count total after filtering
        $total = count($logs);

        // Paginate
        $logs = $this->paginate_logs($logs, $args['limit'], $args['offset']);

        return [
            'logs' => $logs,
            'total' => $total,
            'has_more' => ($args['offset'] + count($logs)) < $total
        ];
    }

    /**
     * Read log file and return array of lines
     * 
     * @return array Log lines
     */
    private function read_log_file()
    {
        $log_file = $this->get_log_file_path();

        if (!file_exists($log_file)) {
            return [];
        }

        // Read file
        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        // Reverse to show most recent first
        return array_reverse($lines);
    }

    /**
     * Filter logs based on criteria
     * 
     * @param array $logs Log entries
     * @param array $args Filter arguments
     * @return array Filtered logs
     */
    private function filter_logs($logs, $args)
    {
        return array_filter($logs, function ($log) use ($args) {
            // Parse log entry
            $parsed = $this->parse_log_entry($log);

            if (!$parsed) {
                return false;
            }

            // Filter by level
            if ($args['level'] !== 'all' && $parsed['level'] !== $args['level']) {
                return false;
            }

            // Filter by module
            if ($args['module'] !== 'all' && $parsed['module'] !== $args['module']) {
                return false;
            }

            // Filter by search term
            if (!empty($args['search'])) {
                $search_text = strtolower($args['search']);
                $log_text = strtolower($log);

                if (stripos($log_text, $search_text) === false) {
                    return false;
                }
            }

            // Filter by date range
            if (!empty($args['date_from'])) {
                $log_time = strtotime($parsed['timestamp']);
                $from_time = strtotime($args['date_from']);

                if ($log_time < $from_time) {
                    return false;
                }
            }

            if (!empty($args['date_to'])) {
                $log_time = strtotime($parsed['timestamp']);
                $to_time = strtotime($args['date_to'] . ' 23:59:59');

                if ($log_time > $to_time) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Parse a single log entry
     * 
     * Handles both WordPress debug.log format and custom format
     * 
     * @param string $log Log line
     * @return array|null Parsed data or null if invalid
     */
    public function parse_log_entry($log)
    {
        // WordPress debug.log format: [06-Feb-2026 18:54:17 UTC] PHP Warning: message
        //  or: [06-Feb-2026 18:54:17 UTC] message
        if (preg_match('/^\[([^\]]+)\]\s+(PHP\s+(Warning|Notice|Error|Fatal error|Parse error)):\s*(.+)/', $log, $matches)) {
            return [
                'timestamp' => $matches[1],
                'level' => 'ERROR', // PHP errors are always treated as ERROR
                'module' => 'PHP',
                'message' => $matches[2] . ': ' . $matches[4],
                'raw' => $log
            ];
        }
        
        // WordPress debug.log generic format: [timestamp] message
        if (preg_match('/^\[([^\]]+)\]\s+(.+)/', $log, $matches)) {
            $message = $matches[2];
            
            // Try to extract module from message if it has [MODULE] prefix
            $module = 'WORDPRESS';
            $level = 'INFO';
            
            // Check for [MODULE] or [LEVEL] patterns
            if (preg_match('/^\[([A-Z\-]+)\]\s*(.+)/', $message, $msg_matches)) {
                $possible_module = $msg_matches[1];
                $remaining = $msg_matches[2];
                
                // Check if it's a level indicator
                if (in_array($possible_module, ['ERROR', 'WARNING', 'INFO', 'DEBUG'])) {
                    $level = $possible_module;
                    
                    // Check for module after level: [ERROR] [MODULE] message
                    if (preg_match('/^\[([A-Z\-]+)\]\s*(.+)/', $remaining, $mod_matches)) {
                        $module = $mod_matches[1];
                        $message = $mod_matches[2];
                    } else {
                        $message = $remaining;
                    }
                } else {
                    // It's a module: [MODULE] message
                    $module = $possible_module;
                    $message = $remaining;
                }
            }
            
            // Detect level from message content if not already set
            if ($level === 'INFO') {
                if (stripos($message, 'error') !== false || stripos($message, 'failed') !== false || stripos($message, 'fatal') !== false) {
                    $level = 'ERROR';
                } elseif (stripos($message, 'warning') !== false || stripos($message, 'notice') !== false) {
                    $level = 'WARNING';
                } elseif (stripos($message, 'debug') !== false) {
                    $level = 'DEBUG';
                }
            }
            
            return [
                'timestamp' => $matches[1],
                'level' => $level,
                'module' => $module,
                'message' => $message,
                'raw' => $log
            ];
        }
        
        // If no pattern matches, return as-is with generic info
        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => 'INFO',
            'module' => 'UNKNOWN',
            'message' => $log,
            'raw' => $log
        ];
    }

    /**
     * Paginate log results
     * 
     * @param array $logs Log entries
     * @param int $limit Limit per page
     * @param int $offset Offset
     * @return array Paginated logs
     */
    private function paginate_logs($logs, $limit, $offset)
    {
        return array_slice($logs, $offset, $limit);
    }

    /**
     * Get log file path
     * 
     * @return string Log file path
     */
    private function get_log_file_path()
    {
        // Read from WordPress debug.log file
        return WP_CONTENT_DIR . '/debug.log';
    }

    /**
     * Get all available modules from logs
     * 
     * @return array Unique module names
     */
    public function get_available_modules()
    {
        $logs = $this->read_log_file();
        $modules = [];

        foreach ($logs as $log) {
            $parsed = $this->parse_log_entry($log);
            if ($parsed && !in_array($parsed['module'], $modules)) {
                $modules[] = $parsed['module'];
            }
        }

        sort($modules);
        return $modules;
    }

    /**
     * Get log statistics
     * 
     * @return array Statistics
     */
    public function get_stats()
    {
        $logs = $this->read_log_file();

        $stats = [
            'total' => count($logs),
            'errors' => 0,
            'warnings' => 0,
            'info' => 0,
            'debug' => 0,
            'by_module' => []
        ];

        foreach ($logs as $log) {
            $parsed = $this->parse_log_entry($log);

            if (!$parsed) {
                continue;
            }

            // Count by level
            $level = strtolower($parsed['level']);
            if (isset($stats[$level])) {
                $stats[$level]++;
            }

            // Count by module
            $module = $parsed['module'];
            if (!isset($stats['by_module'][$module])) {
                $stats['by_module'][$module] = 0;
            }
            $stats['by_module'][$module]++;
        }

        return $stats;
    }

    /**
     * Clear all logs
     * 
     * @return bool Success
     */
    public function clear_logs()
    {
        $log_file = $this->get_log_file_path();

        if (file_exists($log_file)) {
            return file_put_contents($log_file, '') !== false;
        }

        return true;
    }

    /**
     * Get log file size
     * 
     * @return string Formatted file size
     */
    public function get_log_file_size()
    {
        $log_file = $this->get_log_file_path();

        if (!file_exists($log_file)) {
            return '0 KB';
        }

        return size_format(filesize($log_file), 2);
    }

    /**
     * Download log file contents
     * 
     * @return string Log file contents
     */
    public function get_log_content_for_download()
    {
        $log_file = $this->get_log_file_path();

        if (!file_exists($log_file)) {
            return '';
        }

        return file_get_contents($log_file);
    }
}
