<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;
global $wpdb;
$wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'wct_checkout_sessions');
$wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'wct_checkout_fields');
$wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'wct_checkout_items');
$wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'wct_restore_tokens');
$wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'wct_coupons');
$wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'wct_events');
// Recovery coupons stay in WooCommerce (orders reference them); they are one-time and were closed on deactivation.
// Order link meta lives in postmeta (legacy storage) or wc_orders_meta (HPOS).
$wpdb->delete($wpdb->postmeta, array('meta_key'=>'_wct_checkout_session_id'));
$hpos_meta = $wpdb->prefix.'wc_orders_meta';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $hpos_meta ) ) ) === $hpos_meta ) $wpdb->delete($hpos_meta, array('meta_key'=>'_wct_checkout_session_id'));
delete_option('wct_settings');
delete_option('wct_db_version');
delete_option('wct_purge_cursor');
wp_clear_scheduled_hook('wct_maintenance');
wp_clear_scheduled_hook('wct_purge_sensitive');
