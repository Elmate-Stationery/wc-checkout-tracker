<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCT_DB {
    public static function sessions_table() { global $wpdb; return $wpdb->prefix . 'wct_checkout_sessions'; }
    public static function fields_table() { global $wpdb; return $wpdb->prefix . 'wct_checkout_fields'; }
    public static function items_table() { global $wpdb; return $wpdb->prefix . 'wct_checkout_items'; }
    public static function tokens_table() { global $wpdb; return $wpdb->prefix . 'wct_restore_tokens'; }
    public static function coupons_table() { global $wpdb; return $wpdb->prefix . 'wct_coupons'; }
    public static function events_table() { global $wpdb; return $wpdb->prefix . 'wct_events'; }

    // History of what happened to a session / coupon / restore link. $args: coupon_id, token_id, order_id, user_id, data (array).
    public static function log_event( $type, $session_id, $args = array() ) {
        global $wpdb;
        $wpdb->insert( self::events_table(), array(
            'session_id' => (int) $session_id, 'coupon_id' => isset( $args['coupon_id'] ) ? (int) $args['coupon_id'] : null, 'token_id' => isset( $args['token_id'] ) ? (int) $args['token_id'] : null,
            'type' => $type, 'user_id' => array_key_exists( 'user_id', $args ) ? $args['user_id'] : ( get_current_user_id() ?: null ), 'order_id' => isset( $args['order_id'] ) ? (int) $args['order_id'] : null,
            'data' => ! empty( $args['data'] ) ? wp_json_encode( $args['data'] ) : null, 'created_at' => current_time( 'mysql', true ),
        ) );
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sessions = self::sessions_table();
        $fields   = self::fields_table();
        $items    = self::items_table();
        $tokens   = self::tokens_table();
        $coupons  = self::coupons_table();
        $events   = self::events_table();
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
            whatsapp_contacted_at DATETIME NULL,
            whatsapp_contacted_by BIGINT UNSIGNED NULL,
            whatsapp_contact_count INT UNSIGNED NOT NULL DEFAULT 0,
            restored_at DATETIME NULL,
            whatsapp_coupon_contacted_at DATETIME NULL,
            whatsapp_coupon_contacted_by BIGINT UNSIGNED NULL,
            whatsapp_coupon_contact_count INT UNSIGNED NOT NULL DEFAULT 0,
            converted_coupon_id BIGINT UNSIGNED NULL,
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
        ) $charset;
        CREATE TABLE $tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            coupon_id BIGINT UNSIGNED NULL,
            first_used_at DATETIME NULL,
            last_used_at DATETIME NULL,
            use_count INT UNSIGNED NOT NULL DEFAULT 0,
            expired_open_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_device VARCHAR(100) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            KEY session_id (session_id),
            KEY expires_at (expires_at),
            KEY coupon_id (coupon_id)
        ) $charset;
        CREATE TABLE $coupons (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            active_session_id BIGINT UNSIGNED NULL,
            wc_coupon_id BIGINT UNSIGNED NULL,
            code VARCHAR(32) NOT NULL,
            discount_type VARCHAR(10) NOT NULL,
            amount DECIMAL(20,6) NOT NULL,
            min_cart DECIMAL(20,6) NULL,
            max_discount DECIMAL(20,6) NULL,
            validity_hours INT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'generated',
            created_at DATETIME NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            expires_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            applied_at DATETIME NULL,
            used_at DATETIME NULL,
            order_id BIGINT UNSIGNED NULL,
            discount_total DECIMAL(20,6) NULL,
            expired_at DATETIME NULL,
            revoked_at DATETIME NULL,
            revoked_by BIGINT UNSIGNED NULL,
            revoke_reason VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            UNIQUE KEY active_session_id (active_session_id),
            KEY session_id (session_id),
            KEY status (status),
            KEY order_id (order_id)
        ) $charset;
        CREATE TABLE $events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            coupon_id BIGINT UNSIGNED NULL,
            token_id BIGINT UNSIGNED NULL,
            type VARCHAR(30) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            order_id BIGINT UNSIGNED NULL,
            data LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY coupon_id (coupon_id),
            KEY type (type)
        ) $charset;";
        dbDelta( $sql );
        // Builds before 1.5.0 either used a weaker filter or shipped 1.4.0 without this cleanup; queue a one-time scan
        // that removes sensitive rows they stored. Re-running it on already-clean data is harmless.
        if ( version_compare( (string) get_option( 'wct_db_version', '0' ), '1.5.0', '<' ) && false === get_option( 'wct_purge_cursor' ) ) {
            add_option( 'wct_purge_cursor', 0 );
        }
        update_option( 'wct_db_version', WCT_VERSION );
        if ( false === get_option( 'wct_settings' ) ) {
            add_option( 'wct_settings', WCT_Tracker::defaults() );
        }
    }
    public static function maybe_upgrade() {
        if ( get_option( 'wct_db_version' ) !== WCT_VERSION ) self::install();
    }
}
