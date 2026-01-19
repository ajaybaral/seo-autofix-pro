-- SEO AutoFix Pro - Broken URL Management Database Migration
-- Version 2.0 - Adds missing fields and fixes_history table
-- Run this migration to update existing tables

-- ============================================
-- STEP 1: Update broken_links_scan_results table
-- ============================================

ALTER TABLE wp_seoautofix_broken_links_scan_results
ADD COLUMN found_on_page_id BIGINT(20) AFTER scan_id,
ADD COLUMN found_on_page_title VARCHAR(255) AFTER found_on_page_id,
ADD COLUMN link_location ENUM('header', 'footer', 'content', 'sidebar', 'image') DEFAULT 'content' AFTER broken_url,
ADD COLUMN anchor_text TEXT AFTER link_location,
ADD COLUMN link_context TEXT AFTER anchor_text,
ADD COLUMN error_type ENUM('4xx', '5xx', 'timeout', 'dns') AFTER status_code,
ADD COLUMN suggestion_confidence INT DEFAULT 0 AFTER suggested_url,
ADD COLUMN fix_type ENUM('replace', 'remove', 'redirect') AFTER user_modified_url,
ADD COLUMN occurrences_count INT DEFAULT 1 AFTER fix_type,
ADD INDEX idx_found_on_page_id (found_on_page_id),
ADD INDEX idx_broken_url (broken_url(255)),
ADD INDEX idx_error_type (error_type);

-- ============================================
-- STEP 2: Update broken_links_scans table
-- ============================================

ALTER TABLE wp_seoautofix_broken_links_scans
ADD COLUMN total_pages_found INT DEFAULT 0 AFTER scan_id,
ADD COLUMN total_pages_scanned INT DEFAULT 0 AFTER total_pages_found,
MODIFY COLUMN total_urls_found INT DEFAULT 0 AFTER total_pages_scanned,
ADD COLUMN total_4xx_errors INT DEFAULT 0 AFTER total_broken_links,
ADD COLUMN total_5xx_errors INT DEFAULT 0 AFTER total_4xx_errors,
ADD COLUMN current_batch INT DEFAULT 0 AFTER status,
ADD COLUMN total_batches INT DEFAULT 0 AFTER current_batch,
MODIFY COLUMN status ENUM('in_progress', 'completed', 'failed', 'paused') DEFAULT 'in_progress';

-- ============================================
-- STEP 3: Create broken_links_fixes_history table
-- ============================================

CREATE TABLE IF NOT EXISTS wp_seoautofix_broken_links_fixes_history (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fix_session_id VARCHAR(50) NOT NULL,
    scan_id VARCHAR(50) NOT NULL,
    page_id BIGINT(20) NOT NULL,
    original_content LONGTEXT NOT NULL,
    modified_content LONGTEXT NOT NULL,
    fixes_applied JSON NOT NULL,
    total_fixes INT DEFAULT 0,
    is_reverted TINYINT(1) DEFAULT 0,
    applied_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    reverted_at DATETIME NULL,
    INDEX idx_fix_session_id (fix_session_id),
    INDEX idx_scan_id (scan_id),
    INDEX idx_page_id (page_id),
    INDEX idx_is_reverted (is_reverted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Check if columns were added successfully
SHOW COLUMNS FROM wp_seoautofix_broken_links_scan_results;
SHOW COLUMNS FROM wp_seoautofix_broken_links_scans;
SHOW COLUMNS FROM wp_seoautofix_broken_links_fixes_history;

-- Check indexes
SHOW INDEX FROM wp_seoautofix_broken_links_scan_results;
SHOW INDEX FROM wp_seoautofix_broken_links_scans;
SHOW INDEX FROM wp_seoautofix_broken_links_fixes_history;
