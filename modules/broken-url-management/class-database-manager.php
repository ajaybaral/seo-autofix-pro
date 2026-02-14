<?php
/**
 * Database Manager - Handles all database operations
 * 
 * @package SEOAutoFix\BrokenUrlManagement
 */

namespace SEOAutoFix\BrokenUrlManagement;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database Manager Class
 */
class Database_Manager
{

    /**
     * Database table names
     */
    private $table_scans;
    private $table_results;

    /**
     * Constructor
     */
    public function __construct()
    {
        global $wpdb;

        $this->table_scans = $wpdb->prefix . 'seoautofix_broken_links_scans';
        $this->table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';
    }

    /**
     * Ensure database tables exist, create them if they don't
     * This is a safety fallback in case plugin activation didn't work
     * 
     * @return void
     */
    private function ensure_tables_exist()
    {
        global $wpdb;

        // Check if results table exists (main table we need for saving broken links)
        $table = $this->table_results;
        $query = $wpdb->prepare("SHOW TABLES LIKE %s", $table);
        
        if ($wpdb->get_var($query) != $table) {
            \SEOAutoFix_Debug_Logger::log('[DB SAFETY] Table ' . $table . ' does not exist! Creating tables now...');
            
            // Create tables directly - this is a last-resort fallback
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            
            $charset_collate = $wpdb->get_charset_collate();
            
            // Create scans table
            $sql_scans = "CREATE TABLE {$this->table_scans} (
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
            
            // Create results table
            $sql_results = "CREATE TABLE {$this->table_results} (
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
                is_fixed TINYINT(1) DEFAULT 0,
                is_deleted TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                 INDEX idx_scan_id (scan_id),
                INDEX idx_broken_url (broken_url(191)),
                INDEX idx_fixed (is_fixed),
                INDEX idx_deleted (is_deleted)
            ) $charset_collate;";
            
            dbDelta($sql_scans);
            dbDelta($sql_results);
            
            \SEOAutoFix_Debug_Logger::log('[DB SAFETY] ✅ Tables created successfully');
        }
    }

    /**
     * Create new scan entry
     * 
     * @return string Scan ID
     */
    public function create_scan()
    {
        global $wpdb;

        $scan_id = 'scan_' . uniqid() . '_' . time();

        $wpdb->insert(
            $this->table_scans,
            array(
                'scan_id' => $scan_id,
                'status' => 'in_progress',
                'started_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s')
        );

        return $scan_id;
    }

    /**
     * Update scan progress
     * 
     * @param string $scan_id Scan ID
     * @param array $data Update data
     * @return bool Success
     */
    public function update_scan($scan_id, $data)
    {
        global $wpdb;

        $allowed_fields = array(
            'total_urls_found',
            'total_urls_tested',
            'total_broken_links',
            'status',
            'completed_at'
        );

        $update_data = array();
        $format = array();

        foreach ($data as $key => $value) {
            if (in_array($key, $allowed_fields)) {
                $update_data[$key] = $value;

                if (in_array($key, array('total_urls_found', 'total_urls_tested', 'total_broken_links'))) {
                    $format[] = '%d';
                } else {
                    $format[] = '%s';
                }
            }
        }

        if (empty($update_data)) {
            return false;
        }

        return $wpdb->update(
            $this->table_scans,
            $update_data,
            array('scan_id' => $scan_id),
            $format,
            array('%s')
        ) !== false;
    }

    /**
     * Get scan progress
     * 
     * @param string $scan_id Scan ID
     * @return array Progress data
     */
    public function get_scan_progress($scan_id)
    {
        global $wpdb;

        $scan = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_scans} WHERE scan_id = %s",
                $scan_id
            ),
            ARRAY_A
        );

        if (!$scan) {
            return array(
                'status' => 'not_found',
                'progress' => 0,
                'total_urls' => 0,
                'tested_urls' => 0,
                'broken_count' => 0
            );
        }

        $progress = 0;
        if ($scan['total_urls_found'] > 0) {
            $progress = round(($scan['total_urls_tested'] / $scan['total_urls_found']) * 100, 2);
        }

        return array(
            'status' => $scan['status'],
            'progress' => $progress,
            'total_urls' => intval($scan['total_urls_found']),
            'tested_urls' => intval($scan['total_urls_tested']),
            'broken_count' => intval($scan['total_broken_links'])
        );
    }

    /**
     * Add broken link result
     * 
     * @param string $scan_id Scan ID
     * @param array $data Link data
     * @return bool Success
     */
    public function add_broken_link($scan_id, $data)
    {
        global $wpdb;
        
        // SAFETY: Ensure tables exist before trying to insert
        $this->ensure_tables_exist();

        \SEOAutoFix_Debug_Logger::log('[DB ADD_BROKEN_LINK] ========== FUNCTION CALLED ==========');
        \SEOAutoFix_Debug_Logger::log('[DB ADD_BROKEN_LINK] Scan ID: ' . $scan_id);
        \SEOAutoFix_Debug_Logger::log('[DB ADD_BROKEN_LINK] Input data: ' . print_r($data, true));
        \SEOAutoFix_Debug_Logger::log('[DB ADD_BROKEN_LINK] Table name: ' . $this->table_results);
        
        // Prepare insert data
        $insert_data = array(
            'scan_id' => $scan_id,
            'found_on_url' => $data['found_on_url'],
            'found_on_page_id' => isset($data['found_on_page_id']) ? $data['found_on_page_id'] : 0,
            'found_on_page_title' => isset($data['found_on_page_title']) ? $data['found_on_page_title'] : '',
            'broken_url' => $data['broken_url'],
            'link_type' => $data['link_type'],
            'status_code' => $data['status_code'],
            'suggested_url' => isset($data['suggested_url']) ? $data['suggested_url'] : null,
            'reason' => isset($data['reason']) ? $data['reason'] : '',
            'anchor_text' => isset($data['anchor_text']) ? $data['anchor_text'] : '',
            'link_location' => isset($data['location']) ? $data['location'] : 'content',
            'created_at' => current_time('mysql')
        );
        
        \SEOAutoFix_Debug_Logger::log('[DB ADD_BROKEN_LINK] Insert data prepared: ' . print_r($insert_data, true));
        
        $format = array('%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s');
        \SEOAutoFix_Debug_Logger::log('[DB ADD_BROKEN_LINK] Format array: ' . print_r($format, true));
        
        \SEOAutoFix_Debug_Logger::log('[DB ADD_BROKEN_LINK] Calling wpdb->insert()...');
        $result = $wpdb->insert(
            $this->table_results,
            $insert_data,
            $format
        );
        
        \SEOAutoFix_Debug_Logger::log('[DB ADD_BROKEN_LINK] wpdb->insert() returned: ' . var_export($result, true));
        \SEOAutoFix_Debug_Logger::log('[DB ADD_BROKEN_LINK] wpdb->last_error: ' . $wpdb->last_error);
        \SEOAutoFix_Debug_Logger::log('[DB ADD_BROKEN_LINK] wpdb->last_query: ' . $wpdb->last_query);
        \SEOAutoFix_Debug_Logger::log('[DB ADD_BROKEN_LINK] wpdb->insert_id: ' . $wpdb->insert_id);
        \SEOAutoFix_Debug_Logger::log('[DB ADD_BROKEN_LINK] wpdb->rows_affected: ' . $wpdb->rows_affected);
        
        $success = $result !== false;
        \SEOAutoFix_Debug_Logger::log('[DB ADD_BROKEN_LINK] Final return value: ' . ($success ? 'TRUE' : 'FALSE'));
        \SEOAutoFix_Debug_Logger::log('[DB ADD_BROKEN_LINK] ========== FUNCTION END ==========');
        
        return $success;
    }

    /**
     * Get scan results
     * 
     * @param string $scan_id Scan ID
     * @param string $filter Filter type (all, internal, external) - legacy
     * @param string $search Search query
     * @param int $page Page number
     * @param int $per_page Results per page
     * @param string $error_type Error type filter (all, 4xx, 5xx)
     * @param string $page_type Page type filter (all, published, drafts, all-pages)
     * @param string $location Location filter (all, header, footer, content, sidebar)
     * @return array Results data
     */
    public function get_scan_results($scan_id, $filter = 'all', $search = '', $page = 1, $per_page = 25, $error_type = 'all', $page_type = 'all', $location = 'all')
    {
        global $wpdb;

        $where = array();
        $where_values = array($scan_id);

        $where[] = 'scan_id = %s';
        $where[] = 'is_deleted = 0';

        // Apply legacy filter (internal/external)
        if ($filter === 'internal') {
            $where[] = "link_type = 'internal'";
        } elseif ($filter === 'external') {
            $where[] = "link_type = 'external'";
        }

        // Apply error type filter
        if ($error_type === '4xx') {
            $where[] = 'status_code >= 400 AND status_code < 500';
        } elseif ($error_type === '5xx') {
            $where[] = 'status_code >= 500 AND status_code < 600';
        }

        // Apply location filter
        if ($location !== 'all' && !empty($location)) {
            $where[] = 'link_location = %s';
            $where_values[] = strtolower($location); // Convert to lowercase to match database values
        }

        // Note: page_type filter would require additional data we don't currently store
        // This would need the post status from wp_posts table

        // Apply search
        if (!empty($search)) {
            $where[] = '(broken_url LIKE %s OR suggested_url LIKE %s OR reason LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        $where_clause = 'WHERE ' . implode(' AND ', $where);

        // Get total count
        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_results} {$where_clause}",
                $where_values
            )
        );

        // Calculate offset
        $offset = ($page - 1) * $per_page;

        // Get results
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_results} 
                {$where_clause} 
                ORDER BY id ASC 
                LIMIT %d OFFSET %d",
                array_merge($where_values, array($per_page, $offset))
            ),
            ARRAY_A
        );

        return array(
            'results' => $results,
            'total' => intval($total),
            'pages' => ceil($total / $per_page),
            'current_page' => $page,
            'per_page' => $per_page,
            'stats' => $this->get_scan_stats($scan_id)
        );
    }

    /**
     * Get scan statistics by link type
     * 
     * @param string $scan_id Scan ID
     * @return array Statistics data
     */
    public function get_scan_stats($scan_id)
    {
        global $wpdb;

        $stats = array(
            'total' => 0,
            'internal' => 0,
            'external' => 0,
            '4xx' => 0,
            '5xx' => 0
        );

        // Get total count
        $stats['total'] = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_results} 
            WHERE scan_id = %s AND is_deleted = 0",
            $scan_id
        )));

        // Get internal count
        $stats['internal'] = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_results} 
            WHERE scan_id = %s AND is_deleted = 0 AND link_type = 'internal'",
            $scan_id
        )));

        // Get external count
        $stats['external'] = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_results} 
            WHERE scan_id = %s AND is_deleted = 0 AND link_type = 'external'",
            $scan_id
        )));

        // Get 4xx errors count
        $stats['4xx'] = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_results} 
            WHERE scan_id = %s AND is_deleted = 0 AND status_code >= 400 AND status_code < 500",
            $scan_id
        )));

        // Get 5xx errors count
        $stats['5xx'] = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_results} 
            WHERE scan_id = %s AND is_deleted = 0 AND status_code >= 500 AND status_code < 600",
            $scan_id
        )));

        return $stats;
    }

    /**
     * Update suggestion for a broken link
     * 
     * @param int $id Entry ID
     * @param string $new_url New suggested URL
     * @return bool Success
     */
    public function update_suggestion($id, $new_url)
    {
        global $wpdb;

        return $wpdb->update(
            $this->table_results,
            array(
                'user_modified_url' => $new_url,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        ) !== false;
    }

    /**
     * Delete entry (soft delete)
     * 
     * @param int $id Entry ID
     * @return bool Success
     */
    public function delete_entry($id)
    {
        global $wpdb;

        return $wpdb->update(
            $this->table_results,
            array(
                'is_deleted' => 1,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $id),
            array('%d', '%s'),
            array('%d')
        ) !== false;
    }

    /**
     * Mark entry as fixed
     * 
     * @param int $id Entry ID
     * @return bool Success
     */
    public function mark_as_fixed($id)
    {
        global $wpdb;

        \SEOAutoFix_Debug_Logger::log('[MARK_AS_FIXED] Marking entry ID ' . $id . ' as fixed');

        $result = $wpdb->update(
            $this->table_results,
            array(
                'is_fixed' => 1,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $id),
            array('%d', '%s'),
            array('%d')
        );

        \SEOAutoFix_Debug_Logger::log('[MARK_AS_FIXED] Update result: ' . print_r($result, true) . ', wpdb->last_error: ' . $wpdb->last_error);

        return $result !== false;
    }

    /**
     * Get entry by ID
     * 
     * @param int $id Entry ID
     * @return array|null Entry data
     */
    public function get_entry($id)
    {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_results} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );
    }

    /**
     * Get latest scan ID
     * 
     * @return string|null Scan ID
     */
    public function get_latest_scan_id()
    {
        global $wpdb;

        return $wpdb->get_var(
            "SELECT scan_id FROM {$this->table_scans} 
            ORDER BY started_at DESC 
            LIMIT 1"
        );
    }

    /**
     * Get all scans
     * 
     * @param int $limit Number of scans to retrieve
     * @return array Scans
     */
    public function get_scans($limit = 10)
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_scans} 
                ORDER BY started_at DESC 
                LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }

    /**
     * Get list of broken URLs for a scan
     * 
     * @param string $scan_id Scan ID
     * @return array Array of broken URLs
     */
    public function get_broken_urls_for_scan($scan_id)
    {
        global $wpdb;

        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT broken_url FROM {$this->table_results} 
                WHERE scan_id = %s AND is_deleted = 0",
                $scan_id
            )
        );
    }
}
