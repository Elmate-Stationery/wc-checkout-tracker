<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCT_DB {
    public static function sessions_table() { global $wpdb; return $wpdb->prefix . 'wct_checkout_sessions'; }
    public static function fields_table() { global $wpdb; return $wpdb->prefix . 'wct_checkout_fields'; }
    public static function items_table() { global $wpdb; return $wpdb->prefix . 'wct_checkout_items'; }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sessions = self::sessions_table();
        $fields   = self::fields_table();
        $items    = self::items_table();
        $sql = "CREATE TABLE $sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_key VARCHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'initiated',
            user_id BIGINT UNSIGNED NULL,
            order_id BIGINT UNSIGNED NULL,
            email VARCHAR(320) NULL,
            phone VARCHAR(100) NULL,
            customer_name VARCHAR(255) NULL,
            cart_total DECIMAL(20,6) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NULL,
            item_count INT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME NOT NULL,
            last_activity_at DATETIME NOT NULL,
            converted_at DATETIME NULL,
            ip_hash CHAR(64) NULL,
            user_agent TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            abandoned_notified_at DATETIME NULL,
            abandoned_at DATETIME NULL,
            cart_hash CHAR(32) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY session_key (session_key),
            KEY status (status),
            KEY status_abandoned (status, abandoned_at),
            KEY order_id (order_id),
            KEY email (email(191)),
            KEY last_activity_at (last_activity_at)
        ) $charset;
        CREATE TABLE $fields (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            field_key VARCHAR(191) NOT NULL,
            field_value LONGTEXT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY session_field (session_id, field_key),
            KEY session_id (session_id),
            KEY field_key (field_key)
        ) $charset;
        CREATE TABLE $items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NULL,
            variation_id BIGINT UNSIGNED NULL,
            product_name TEXT NULL,
            sku VARCHAR(191) NULL,
            quantity DECIMAL(20,6) NOT NULL DEFAULT 1,
            unit_price DECIMAL(20,6) NOT NULL DEFAULT 0,
            line_total DECIMAL(20,6) NOT NULL DEFAULT 0,
            variation_json LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY product_id (product_id)
        ) $charset;";
        dbDelta( $sql );
        update_option( 'wct_db_version', WCT_VERSION );
        if ( false === get_option( 'wct_settings' ) ) {
            add_option( 'wct_settings', array( 'enabled' => 1, 'retention_days' => 90, 'abandon_timeout_minutes' => 60, 'email_alerts_enabled' => 0, 'email_alert_recipients' => get_option('admin_email') ) );
        }
    }
    public static function maybe_upgrade() {
        if ( get_option( 'wct_db_version' ) !== WCT_VERSION ) self::install();
    }
}
