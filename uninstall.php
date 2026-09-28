<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;
global $wpdb;
$wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'wct_checkout_sessions');
$wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'wct_checkout_fields');
$wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'wct_checkout_items');
delete_option('wct_settings');
delete_option('wct_db_version');
wp_clear_scheduled_hook('wct_daily_cleanup');
