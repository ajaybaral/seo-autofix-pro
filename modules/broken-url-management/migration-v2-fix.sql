-- SEO AutoFix Pro - Database Migration Fix
-- Adds missing columns for page title and metadata
-- Run this SQL in phpMyAdmin or MySQL command line

-- Add missing columns to scan_results table
ALTER TABLE wp_seoautofix_broken_links_scan_results
ADD COLUMN IF NOT EXISTS found_on_page_id BIGINT(20) DEFAULT 0 AFTER scan_id,
ADD COLUMN IF NOT EXISTS found_on_page_title VARCHAR(255) DEFAULT '' AFTER found_on_page_id,
ADD COLUMN IF NOT EXISTS anchor_text TEXT AFTER broken_url,
ADD COLUMN IF NOT EXISTS location VARCHAR(50) DEFAULT 'content' AFTER anchor_text;

-- Verify columns were added
SHOW COLUMNS FROM wp_seoautofix_broken_links_scan_results;
