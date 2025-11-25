<?php
/**
 * DataFlair Toplists Uninstall Script
 * 
 * This file is executed when the plugin is deleted via WordPress admin
 */

// Exit if accessed directly or not in uninstall context
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete custom database tables
global $wpdb;
$table_name = $wpdb->prefix . 'dataflair_toplists';
$brands_table_name = $wpdb->prefix . 'dataflair_brands';
$alternative_toplists_table = $wpdb->prefix . 'dataflair_alternative_toplists';
$wpdb->query("DROP TABLE IF EXISTS $table_name");
$wpdb->query("DROP TABLE IF EXISTS $brands_table_name");
$wpdb->query("DROP TABLE IF EXISTS $alternative_toplists_table");

// Delete plugin options
delete_option('dataflair_api_token');
delete_option('dataflair_api_endpoints');
delete_option('dataflair_ribbon_bg_color');
delete_option('dataflair_ribbon_text_color');
delete_option('dataflair_cta_bg_color');
delete_option('dataflair_cta_text_color');
delete_option('dataflair_db_version');

// Clear scheduled cron events
wp_clear_scheduled_hook('dataflair_sync_cron');
wp_clear_scheduled_hook('dataflair_brands_sync_cron');

// Optional: Clear any transients (if we add caching later)
// delete_transient('dataflair_cache_*');