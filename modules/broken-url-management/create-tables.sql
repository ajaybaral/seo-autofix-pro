-- SEO AutoFix Pro - Broken URL Management Database Tables
-- Run this in phpMyAdmin or MySQL to create the required tables manually

-- Create scans tracking table
CREATE TABLE IF NOT EXISTS `wp_seoautofix_broken_links_scans` (
    `id` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `scan_id` VARCHAR(50) UNIQUE NOT NULL,
    `total_urls_found` INT DEFAULT 0,
    `total_urls_tested` INT DEFAULT 0,
    `total_broken_links` INT DEFAULT 0,
    `status` ENUM('in_progress', 'completed', 'failed') DEFAULT 'in_progress',
    `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    INDEX `idx_scan_id` (`scan_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create scan results table
CREATE TABLE IF NOT EXISTS `wp_seoautofix_broken_links_scan_results` (
    `id` BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `scan_id` VARCHAR(50) NOT NULL,
    `found_on_url` TEXT NOT NULL,
    `broken_url` TEXT NOT NULL,
    `link_type` ENUM('internal', 'external') NOT NULL,
    `status_code` INT NOT NULL,
    `suggested_url` TEXT NULL,
    `user_modified_url` TEXT NULL,
    `reason` TEXT NOT NULL,
    `is_fixed` TINYINT(1) DEFAULT 0,
    `is_deleted` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_scan_id` (`scan_id`),
    INDEX `idx_link_type` (`link_type`),
    INDEX `idx_status_code` (`status_code`),
    INDEX `idx_is_fixed` (`is_fixed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
