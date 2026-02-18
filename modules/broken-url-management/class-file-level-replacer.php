<?php
/**
 * File-Level Replacer - Last-resort fallback for URLs hardcoded in theme/plugin files
 *
 * Only activated when URL is NOT found in:
 *   - wp_posts.post_content
 *   - wp_postmeta.meta_value
 *   - Navigation menus
 *   - Widgets
 *   - Elementor templates
 *
 * Safety controls:
 *   - Only scans active theme + child theme directories
 *   - NEVER modifies wp-admin, wp-includes, or WordPress core
 *   - Creates backup before every modification
 *   - Max file size: 5MB
 *   - Max files per operation: 500
 *   - Uses preg_quote() for safe regex
 *   - Requires manage_options capability
 *
 * @package SEOAutoFix\BrokenUrlManagement
 */

namespace SEOAutoFix\BrokenUrlManagement;

if (!defined('ABSPATH')) {
    exit;
}

class File_Level_Replacer
{
    /**
     * Max file size to process (5MB)
     */
    const MAX_FILE_SIZE = 5 * 1024 * 1024;

    /**
     * Max files to scan per operation
     */
    const MAX_FILES = 500;

    /**
     * Allowed file extensions
     */
    private $allowed_extensions = ['php', 'html', 'htm', 'twig'];

    /**
     * Backup directory path
     */
    private $backup_dir;

    /**
     * Directories that must NEVER be modified
     */
    private $forbidden_dirs = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $upload_dir = wp_upload_dir();
        $this->backup_dir = $upload_dir['basedir'] . '/seoautofix-backups';

        // Build forbidden directory list
        $this->forbidden_dirs = [
            ABSPATH . 'wp-admin',
            ABSPATH . 'wp-includes',
            ABSPATH . WPINC,
        ];

        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] File_Level_Replacer initialized');
        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] Backup dir: ' . $this->backup_dir);
    }

    /**
     * Replace a URL in theme/child-theme files
     *
     * @param string $old_url URL to find and replace
     * @param string $new_url Replacement URL
     * @return array {success: bool, files_modified: string[], backup_paths: string[], message: string}
     */
    public function replace_url($old_url, $new_url)
    {
        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ===== replace_url() START =====');
        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] Old URL: ' . $old_url);
        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] New URL: ' . $new_url);

        // Security check
        if (!current_user_can('manage_options')) {
            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ Unauthorized — user lacks manage_options');
            return $this->result(false, [], [], 'Unauthorized');
        }

        // Validate inputs
        if (empty($old_url) || empty($new_url)) {
            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ Empty URL parameter');
            return $this->result(false, [], [], 'Invalid parameters');
        }

        // Find files containing the URL
        $candidates = $this->scan_files($old_url);

        if (empty($candidates)) {
            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ URL not found in any theme files');
            return $this->result(false, [], [], 'URL not found in theme files');
        }

        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] Found ' . count($candidates) . ' file(s) containing URL');

        // Ensure backup directory exists
        if (!$this->ensure_backup_dir()) {
            return $this->result(false, [], [], 'Failed to create backup directory');
        }

        // Process each file
        $files_modified = [];
        $backup_paths = [];
        $pattern = '/' . preg_quote($old_url, '/') . '/i';

        foreach ($candidates as $file_path) {
            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] Processing: ' . $file_path);

            $content = file_get_contents($file_path);
            if ($content === false) {
                \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ⚠️ Could not read file: ' . $file_path);
                continue;
            }

            // Create backup BEFORE modification
            $backup_path = $this->create_backup($file_path, $content);
            if (!$backup_path) {
                \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ Backup failed for: ' . $file_path . ' — skipping');
                continue;
            }

            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ✅ Backup created: ' . $backup_path);
            $backup_paths[] = $backup_path;

            // Count replacements for logging
            $count = preg_match_all($pattern, $content);

            // Perform replacement
            $updated_content = preg_replace($pattern, $new_url, $content);

            if ($updated_content === $content) {
                \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ⚠️ No change after replacement in: ' . $file_path);
                continue;
            }

            // Write back
            $write_result = file_put_contents($file_path, $updated_content);

            if ($write_result === false) {
                \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ Failed to write to: ' . $file_path);
                // Try to restore from backup
                $this->restore_file_from_backup($file_path, $backup_path);
                continue;
            }

            $files_modified[] = $file_path;
            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ✅ Modified: ' . $file_path . ' (' . $count . ' replacement(s))');
        }

        $success = !empty($files_modified);
        $message = $success
            ? sprintf('⚠️ Link was hardcoded in theme file(s). %d file(s) modified safely with backup.', count($files_modified))
            : '❌ URL not found in database or theme files. It may be dynamically generated.';

        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] Files modified: ' . count($files_modified));
        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ===== replace_url() END =====');

        return $this->result($success, $files_modified, $backup_paths, $message);
    }

    /**
     * Remove a link (strip <a> tag, keep inner text) from theme/child-theme files
     *
     * @param string $target_url URL whose anchor tags should be stripped
     * @return array {success: bool, files_modified: string[], backup_paths: string[], message: string}
     */
    public function remove_link($target_url)
    {
        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ===== remove_link() START =====');
        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] Target URL: ' . $target_url);

        // Security check
        if (!current_user_can('manage_options')) {
            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ Unauthorized');
            return $this->result(false, [], [], 'Unauthorized');
        }

        if (empty($target_url)) {
            return $this->result(false, [], [], 'Invalid parameters');
        }

        // Find files containing the URL
        $candidates = $this->scan_files($target_url);

        if (empty($candidates)) {
            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ URL not found in any theme files');
            return $this->result(false, [], [], 'URL not found in theme files');
        }

        // Ensure backup directory
        if (!$this->ensure_backup_dir()) {
            return $this->result(false, [], [], 'Failed to create backup directory');
        }

        // Build anchor-strip patterns (same approach as Universal_Replacement_Engine)
        $escaped = preg_quote($target_url, '/');
        $escaped_nots = preg_quote(untrailingslashit($target_url), '/');
        $anchor_patterns = [
            '/<a\s[^>]*href=["\']' . $escaped . '["\'][^>]*>(.*?)<\/a>/is',
            '/<a\s[^>]*href=["\']' . $escaped_nots . '\/?' . '["\'][^>]*>(.*?)<\/a>/is',
        ];

        $files_modified = [];
        $backup_paths = [];

        foreach ($candidates as $file_path) {
            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] Processing for deletion: ' . $file_path);

            $content = file_get_contents($file_path);
            if ($content === false) {
                continue;
            }

            // Create backup
            $backup_path = $this->create_backup($file_path, $content);
            if (!$backup_path) {
                \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ Backup failed — skipping: ' . $file_path);
                continue;
            }

            $backup_paths[] = $backup_path;

            // Strip anchor tags
            $updated_content = $content;
            foreach ($anchor_patterns as $pattern) {
                $updated_content = preg_replace($pattern, '$1', $updated_content);
            }

            if ($updated_content === $content) {
                \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ⚠️ No anchor tags found for URL in: ' . $file_path);
                continue;
            }

            // Write back
            $write_result = file_put_contents($file_path, $updated_content);

            if ($write_result === false) {
                \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ Failed to write: ' . $file_path);
                $this->restore_file_from_backup($file_path, $backup_path);
                continue;
            }

            $files_modified[] = $file_path;
            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ✅ Link removed from: ' . $file_path);
        }

        $success = !empty($files_modified);
        $message = $success
            ? sprintf('⚠️ Link was hardcoded in theme file(s). %d file(s) modified safely with backup.', count($files_modified))
            : '❌ URL not found in database or theme files. It may be dynamically generated.';

        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ===== remove_link() END =====');

        return $this->result($success, $files_modified, $backup_paths, $message);
    }

    /**
     * Scan theme files for a URL (dry-run — no modifications)
     *
     * @param string $url URL to search for
     * @return string[] List of file paths containing the URL
     */
    public function scan_files($url)
    {
        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ===== scan_files() START =====');
        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] Searching for: ' . $url);

        $dirs_to_scan = $this->get_scan_directories();

        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] Directories to scan: ' . implode(', ', $dirs_to_scan));

        $candidates = [];
        $files_scanned = 0;

        foreach ($dirs_to_scan as $dir) {
            if (!is_dir($dir)) {
                \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] Directory does not exist: ' . $dir);
                continue;
            }

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($iterator as $file) {
                    // Hard limit on files scanned
                    if ($files_scanned >= self::MAX_FILES) {
                        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ⚠️ Hit MAX_FILES limit (' . self::MAX_FILES . ') — stopping scan');
                        break 2;
                    }

                    $file_path = $file->getPathname();

                    // Skip non-allowed extensions
                    $ext = strtolower($file->getExtension());
                    if (!in_array($ext, $this->allowed_extensions)) {
                        continue;
                    }

                    // Skip files that are too large
                    if ($file->getSize() > self::MAX_FILE_SIZE) {
                        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ⚠️ Skipping large file: ' . $file_path . ' (' . $file->getSize() . ' bytes)');
                        continue;
                    }

                    // Safety: ensure file is not in a forbidden directory
                    if ($this->is_forbidden_path($file_path)) {
                        continue;
                    }

                    $files_scanned++;

                    // Read and search
                    $content = file_get_contents($file_path);
                    if ($content === false) {
                        continue;
                    }

                    if (stripos($content, $url) !== false) {
                        $candidates[] = $file_path;
                        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] 🎯 Found URL in: ' . $file_path);
                    }
                }
            } catch (\Exception $e) {
                \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ Scan error in ' . $dir . ': ' . $e->getMessage());
            }
        }

        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] Files scanned: ' . $files_scanned);
        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] Files with URL: ' . count($candidates));
        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ===== scan_files() END =====');

        return $candidates;
    }

    /**
     * Restore a file from its backup
     *
     * @param string $original_path Original file path
     * @return bool Success
     */
    public function restore_file($original_path)
    {
        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ===== restore_file() START =====');
        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] Restoring: ' . $original_path);

        // Security: validate path
        if (!$this->is_safe_path($original_path)) {
            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ Unsafe path — refusing to restore');
            return false;
        }

        // Find the most recent backup for this file
        $backup_path = $this->find_latest_backup($original_path);

        if (!$backup_path || !file_exists($backup_path)) {
            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ No backup found for: ' . $original_path);
            return false;
        }

        return $this->restore_file_from_backup($original_path, $backup_path);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Get directories to scan (active theme + parent theme)
     *
     * @return string[]
     */
    private function get_scan_directories()
    {
        $dirs = [];

        // Active theme (could be child theme)
        $stylesheet_dir = get_stylesheet_directory();
        $dirs[] = $stylesheet_dir;

        // Parent theme (if using a child theme)
        $template_dir = get_template_directory();
        if ($template_dir !== $stylesheet_dir) {
            $dirs[] = $template_dir;
        }

        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] Active theme: ' . $stylesheet_dir);
        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] Parent theme: ' . $template_dir);

        return array_unique($dirs);
    }

    /**
     * Create a backup of a file
     *
     * @param string $file_path Original file path
     * @param string $content File content to backup
     * @return string|false Backup path on success, false on failure
     */
    private function create_backup($file_path, $content)
    {
        // Generate unique backup filename: md5(path)_timestamp.bak
        $hash = md5($file_path);
        $timestamp = date('Ymd_His');
        $backup_filename = $hash . '_' . $timestamp . '.bak';
        $backup_path = $this->backup_dir . '/' . $backup_filename;

        // Also store a mapping file so we can find backups by original path
        $map_filename = $hash . '_' . $timestamp . '.map';
        $map_path = $this->backup_dir . '/' . $map_filename;

        // Write backup
        $result = file_put_contents($backup_path, $content);
        if ($result === false) {
            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ Failed to write backup: ' . $backup_path);
            return false;
        }

        // Write mapping (original path → backup path)
        file_put_contents($map_path, $file_path);

        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ✅ Backup created: ' . $backup_path);
        return $backup_path;
    }

    /**
     * Find the most recent backup for a given original file path
     *
     * @param string $original_path Original file path
     * @return string|false Backup path or false
     */
    private function find_latest_backup($original_path)
    {
        if (!is_dir($this->backup_dir)) {
            return false;
        }

        $hash = md5($original_path);
        $pattern = $this->backup_dir . '/' . $hash . '_*.bak';
        $backups = glob($pattern);

        if (empty($backups)) {
            return false;
        }

        // Sort by modification time (newest first)
        usort($backups, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return $backups[0];
    }

    /**
     * Restore a file from a specific backup path
     *
     * @param string $original_path Path to restore to
     * @param string $backup_path Path of backup file
     * @return bool Success
     */
    private function restore_file_from_backup($original_path, $backup_path)
    {
        $backup_content = file_get_contents($backup_path);
        if ($backup_content === false) {
            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ Could not read backup: ' . $backup_path);
            return false;
        }

        $result = file_put_contents($original_path, $backup_content);
        if ($result === false) {
            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ Could not restore file: ' . $original_path);
            return false;
        }

        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ✅ File restored from backup: ' . $original_path);
        return true;
    }

    /**
     * Ensure the backup directory exists
     *
     * @return bool
     */
    private function ensure_backup_dir()
    {
        if (is_dir($this->backup_dir)) {
            return true;
        }

        $created = wp_mkdir_p($this->backup_dir);

        if (!$created) {
            \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ❌ Failed to create backup directory: ' . $this->backup_dir);
            return false;
        }

        // Add .htaccess to prevent direct access
        $htaccess = $this->backup_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        // Add index.php for extra protection
        $index = $this->backup_dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        \SEOAutoFix_Debug_Logger::log('[FILE_REPLACE] ✅ Backup directory created: ' . $this->backup_dir);
        return true;
    }

    /**
     * Check if a path is in a forbidden directory (wp-admin, wp-includes, etc.)
     *
     * @param string $path File path to check
     * @return bool True if forbidden
     */
    private function is_forbidden_path($path)
    {
        $real_path = realpath($path);
        if (!$real_path) {
            return true; // Can't resolve = don't touch
        }

        foreach ($this->forbidden_dirs as $forbidden) {
            $real_forbidden = realpath($forbidden);
            if ($real_forbidden && strpos($real_path, $real_forbidden) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate that a path is safe (within theme directories, no traversal)
     *
     * @param string $path Path to validate
     * @return bool
     */
    private function is_safe_path($path)
    {
        // Prevent path traversal
        if (strpos($path, '..') !== false) {
            return false;
        }

        $real_path = realpath($path);
        if (!$real_path) {
            return false;
        }

        // Must be within allowed scan directories
        $allowed_dirs = $this->get_scan_directories();
        foreach ($allowed_dirs as $dir) {
            $real_dir = realpath($dir);
            if ($real_dir && strpos($real_path, $real_dir) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a standard result array
     *
     * @param bool     $success
     * @param string[] $files_modified
     * @param string[] $backup_paths
     * @param string   $message
     * @return array
     */
    private function result($success, $files_modified, $backup_paths, $message)
    {
        return [
            'success' => $success,
            'files_modified' => $files_modified,
            'backup_paths' => $backup_paths,
            'message' => $message,
        ];
    }
}
