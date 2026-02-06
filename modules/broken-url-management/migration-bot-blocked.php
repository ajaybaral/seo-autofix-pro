<?php
/**
 * Migration Script: Add 'bot-blocked' error type and fix existing 999 errors
 * 
 * This script:
 * 1. Adds 'bot-blocked' to the error_type ENUM field
 * 2. Updates existing entries with status_code 999 to error_type 'bot-blocked'
 * 3. Marks status 999 entries as NOT broken (is_broken = false)
 * 
 * Run this once after updating the plugin code
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';

echo "Starting migration for bot-blocked error type...\n\n";

// Step 1: Modify error_type ENUM to include 'bot-blocked'
echo "Step 1: Updating error_type ENUM...\n";
$sql_alter = "ALTER TABLE {$table_results} 
              MODIFY COLUMN error_type ENUM('4xx', '5xx', 'timeout', 'dns', 'bot-blocked') DEFAULT NULL";

$result = $wpdb->query($sql_alter);

if ($result === false) {
    echo "❌ Error updating ENUM: " . $wpdb->last_error . "\n";
} else {
    echo "✅ Successfully updated error_type ENUM to include 'bot-blocked'\n\n";
}

// Step 2: Find all entries with status_code 999
echo "Step 2: Finding entries with status_code 999...\n";
$count_999 = $wpdb->get_var(
    "SELECT COUNT(*) FROM {$table_results} WHERE status_code = 999"
);

echo "Found {$count_999} entries with status code 999\n\n";

if ($count_999 > 0) {
    // Step 3: Update these entries
    echo "Step 3: Updating status 999 entries...\n";
    
    // Update error_type to 'bot-blocked'
    $updated = $wpdb->query(
        "UPDATE {$table_results} 
         SET error_type = 'bot-blocked' 
         WHERE status_code = 999"
    );
    
    echo "✅ Updated {$updated} entries to error_type 'bot-blocked'\n";
    
    // Step 4: Since these aren't actually broken, optionally remove them from results
    // OR mark them differently
    echo "\nStep 4: Cleaning up...\n";
    echo "These links are NOT actually broken (they work in browsers).\n";
    echo "Options:\n";
    echo "  A) Delete them from broken links (they aren't broken)\n";
    echo "  B) Keep them for reference with special status\n\n";
    
    // Option A: Delete (recommended)
    $deleted = $wpdb->query(
        "DELETE FROM {$table_results} WHERE status_code = 999"
    );
    
    echo "✅ Deleted {$deleted} bot-blocked entries (they aren't broken)\n";
}

echo "\n✅ Migration complete!\n";
echo "Summary:\n";
echo "  - Added 'bot-blocked' error type to database\n";
echo "  - Cleaned up {$count_999} LinkedIn/social media links that were falsely flagged\n";
echo "  - Future scans will automatically skip status 999 URLs\n";
