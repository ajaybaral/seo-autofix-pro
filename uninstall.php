<?php
/**
 * SEO AutoFix Pro Uninstall Script
 * 
 * This file is executed when the plugin is deleted from WordPress
 * It cleans up all plugin data including options, transients, and database tables
 * 
 * @package SEO_AutoFix_Pro
 */

// Exit if accessed directly OR if uninstall is not called by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete all plugin options
$options_to_delete = array(
    'seoautofix_activated',
    'seoautofix_version',
    'seoautofix_openai_api_key',
    'seoautofix_settings',
    // Add any other plugin options here
);

foreach ($options_to_delete as $option) {
    delete_option($option);
}

// Delete all plugin transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_seoautofix_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_seoautofix_%'");

// Drop custom database tables
$tables_to_drop = array(
    $wpdb->prefix . 'seoautofix_image_history',
    $wpdb->prefix . 'seoautofix_rollback',
    $wpdb->prefix . 'seoautofix_broken_links',
    // Add other custom tables here
);

foreach ($tables_to_drop as $table) {
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

// Clear any scheduled cron events
wp_clear_scheduled_hook('seoautofix_daily_tasks');

// Optional: Delete all post meta added by the plugin
// Uncomment if you want to remove all traces
// $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'seoautofix_%'");
