<?php
/**
 * Migration Script: Add Elementor Link Detection Support
 * 
 * This script adds columns to track link sources (Elementor, content, etc.)
 * Run this once after updating the plugin code.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$table_results = $wpdb->prefix . 'seoautofix_broken_links_scan_results';

echo "Starting migration for Elementor link detection support...\n\n";

// Check if columns already exist
$columns = $wpdb->get_results("SHOW COLUMNS FROM {$table_results} LIKE 'source_type'");

if (!empty($columns)) {
    echo "⚠️  Columns already exist. Migration may have been run previously.\n";
    echo "Skipping migration.\n";
    exit;
}

// Step 1: Add source_type column
echo "Step 1: Adding source_type column...\n";
$sql_source_type = "ALTER TABLE {$table_results} 
                    ADD COLUMN source_type VARCHAR(50) DEFAULT 'content' AFTER link_type";

$result = $wpdb->query($sql_source_type);

if ($result === false) {
    echo "❌ Error adding source_type column: " . $wpdb->last_error . "\n";
    exit;
} else {
    echo "✅ Successfully added source_type column\n\n";
}

// Step 2: Add source_meta_key column
echo "Step 2: Adding source_meta_key column...\n";
$sql_meta_key = "ALTER TABLE {$table_results} 
                 ADD COLUMN source_meta_key VARCHAR(255) DEFAULT NULL AFTER source_type";

$result = $wpdb->query($sql_meta_key);

if ($result === false) {
    echo "❌ Error adding source_meta_key column: " . $wpdb->last_error . "\n";
    exit;
} else {
    echo "✅ Successfully added source_meta_key column\n\n";
}

// Step 3: Add source_json_path column
echo "Step 3: Adding source_json_path column...\n";
$sql_json_path = "ALTER TABLE {$table_results} 
                  ADD COLUMN source_json_path TEXT DEFAULT NULL AFTER source_meta_key";

$result = $wpdb->query($sql_json_path);

if ($result === false) {
    echo "❌ Error adding source_json_path column: " . $wpdb->last_error . "\n";
    exit;
} else {
    echo "✅ Successfully added source_json_path column\n\n";
}

// Step 4: Add index for better performance
echo "Step 4: Adding index on source_type...\n";
$sql_index = "ALTER TABLE {$table_results} ADD INDEX idx_source_type (source_type)";

$result = $wpdb->query($sql_index);

if ($result === false) {
    echo "❌ Error adding index: " . $wpdb->last_error . "\n";
    // Don't exit - index is optional for functionality
} else {
    echo "✅ Successfully added index on source_type\n\n";
}

// Step 5: Update existing records to have 'content' as source_type
echo "Step 5: Updating existing records...\n";
$count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_results}");

echo "Found {$count} existing records\n";

if ($count > 0) {
    // They should already have 'content' as default, but let's be sure
    $updated = $wpdb->query(
        "UPDATE {$table_results} 
         SET source_type = 'content' 
         WHERE source_type IS NULL OR source_type = ''"
    );
    
    echo "✅ Updated {$updated} existing records to source_type = 'content'\n\n";
}

echo "\n✅ Migration complete!\n";
echo "Summary:\n";
echo "  - Added source_type column (tracks where link was found)\n";
echo "  - Added source_meta_key column (stores meta field name)\n";
echo "  - Added source_json_path column (stores path in Elementor JSON)\n";
echo "  - Added index for performance\n";
echo "  - Updated {$count} existing records\n\n";
echo "✅ System is now ready to detect Elementor links!\n";
