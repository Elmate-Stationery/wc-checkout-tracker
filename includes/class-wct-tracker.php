<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCT_Tracker {
    const COOKIE      = 'wct_checkout_key';
    const MAX_FIELDS  = 200; // per request
    const ALERT_BATCH = 25;  // alert emails per maintenance run

    // Payment credentials and secrets are never stored or emailed. Substrings are matched against the
    // field name with separators removed; tokens must be a whole name segment (split on - _ [ ] etc.).
    const SENSITIVE_SUBSTRINGS = array( 'password', 'passwd', 'passphrase', 'pass1', 'pass2', 'payment', 'card', 'ccnum', 'ccno', 'ccexp', 'cvv', 'cvc', 'cvn', 'securitycode', 'expmonth', 'expyear', 'expdate', 'expiry', 'expiration', 'iban', 'routing', 'accountnumber', 'acctnum', 'acctno', 'sortcode', 'token', 'secret', 'nonce', 'apikey', 'privatekey', 'authcode', 'verificationcode', 'captcha', 'turnstile' );
    const SENSITIVE_TOKENS     = array( 'cc', 'pin', 'cid', 'csc', 'otp', 'totp', 'mfa', '2fa', 'pass', 'pwd' );
    const SENSITIVE_EXACT      = array( 'woocommerce-process-checkout-nonce', 'terms', 'terms-field', 'createaccount', '_wp_http_referer' );
    // The chosen gateway ID (e.g. "stripe") is behaviour, not a credential.
    const SAFE_KEYS            = array( 'payment_method', 'radio-control-wc-payment-method-options' );

    // Classic checkout uses billing_first_name, the Checkout block uses billing-first_name / shipping-first_name.
    const EMAIL_KEYS    = array( 'billing_email', 'billing-email', 'email' );
    const PHONE_KEYS    = array( 'billing_phone', 'billing-phone', 'shipping_phone', 'shipping-phone', 'phone' );
    const NAME_PREFIXES = array( 'billing_', 'billing-', 'shipping_', 'shipping-' );

    public static function init() {
        add_action( 'template_redirect', array( __CLASS__, 'prime_cookie' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'wp_ajax_wct_start', array( __CLASS__, 'ajax_start' ) );
        add_action( 'wp_ajax_nopriv_wct_start', array( __CLASS__, 'ajax_start' ) );
        add_action( 'wp_ajax_wct_capture', array( __CLASS__, 'ajax_capture' ) );
        add_action( 'wp_ajax_nopriv_wct_capture', array( __CLASS__, 'ajax_capture' ) );
        // Classic (shortcode) checkout, and the Checkout block / Store API.
        add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'classic_order_processed' ), 10, 3 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'store_api_order_processed' ) );
        add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
        add_action( 'wct_maintenance', array( __CLASS__, 'maintenance' ) );
        add_action( 'wct_purge_sensitive', array( __CLASS__, 'purge_sensitive' ) );
        self::ensure_schedule();
    }
    private static function settings() { return wp_parse_args( get_option( 'wct_settings', array() ), array( 'enabled' => 1, 'retention_days' => 90, 'abandon_timeout_minutes' => 60, 'email_alerts_enabled' => 0, 'email_alert_recipients' => get_option( 'admin_email' ) ) ); }
    public static function cron_schedules( $schedules ) {
        if ( ! isset( $schedules['wct_15_minutes'] ) ) {
            $schedules['wct_15_minutes'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes (Checkout Tracker)' );
        }
        return $schedules;
    }
    public static function ensure_schedule() {
        if ( ! wp_next_scheduled( 'wct_maintenance' ) ) {
            wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'wct_15_minutes', 'wct_maintenance' );
        }
        if ( false !== get_option( 'wct_purge_cursor' ) && ! wp_next_scheduled( 'wct_purge_sensitive' ) ) {
            wp_schedule_single_event( time(), 'wct_purge_sensitive' );
        }
    }
    public static function reschedule() {
        wp_clear_scheduled_hook( 'wct_maintenance' );
        self::ensure_schedule();
    }
    public static function deactivate() { wp_clear_scheduled_hook( 'wct_maintenance' ); wp_clear_scheduled_hook( 'wct_purge_sensitive' ); }

    private static function is_tracked_page() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || is_checkout_pay_page() ) return false;
        $s = self::settings();
        return ! empty( $s['enabled'] );
    }
    // Set the session cookie with the page response, so every AJAX request from the page shares one key
    // and parallel first requests cannot each create their own session.
    public static function prime_cookie() {
        if ( self::is_tracked_page() ) self::ensure_key();
    }
    public static function assets() {
        if ( ! self::is_tracked_page() ) return;
        wp_enqueue_script( 'wct-checkout', WCT_URL . 'assets/js/checkout.js', array( 'jquery' ), WCT_VERSION, true );
        wp_localize_script( 'wct-checkout', 'WCT', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'wct_checkout' ),
            'heartbeat' => 60000,
            'sensitive' => array( 'substrings' => self::SENSITIVE_SUBSTRINGS, 'tokens' => self::SENSITIVE_TOKENS, 'exact' => self::SENSITIVE_EXACT, 'safe' => self::SAFE_KEYS ),
        ) );
    }
    private static function valid_nonce() { return isset( $_REQUEST['nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ), 'wct_checkout' ); }

    private static function cookie_key() {
        $raw = isset( $_COOKIE[ self::COOKIE ] ) ? wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
        return is_string( $raw ) ? substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', $raw ), 0, 64 ) : '';
    }
    private static function new_key() {
        $key = wp_generate_uuid4();
        if ( ! headers_sent() ) {
            setcookie( self::COOKIE, $key, array( 'expires' => time() + DAY_IN_SECONDS * 30, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN ?: '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
        }
        $_COOKIE[ self::COOKIE ] = $key;
        return $key;
    }
    private static function ensure_key() {
        $key = self::cookie_key();
        return '' !== $key ? $key : self::new_key();
    }
    private static function ip_hash() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        return $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ) : null;
    }

    public static function is_sensitive_key( $key ) {
        $k = strtolower( (string) $key );
        if ( in_array( $k, self::SAFE_KEYS, true ) ) return false;
        if ( in_array( $k, self::SENSITIVE_EXACT, true ) ) return true;
        $compact = preg_replace( '/[^a-z0-9]/', '', $k );
        foreach ( self::SENSITIVE_SUBSTRINGS as $x ) if ( false !== strpos( $compact, $x ) ) return true;
        $parts = preg_split( '/[^a-z0-9]+/', $k, -1, PREG_SPLIT_NO_EMPTY );
        foreach ( self::SENSITIVE_TOKENS as $x ) {
            // "pin_code" is a postal code in India, not a PIN.
            if ( 'pin' === $x && false !== strpos( $compact, 'pincode' ) ) continue;
            if ( in_array( $x, $parts, true ) ) return true;
        }
        return false;
    }
    // Replaces anything that looks like a card number (13-19 digits, optionally grouped by spaces or dashes,
    // passing the Luhn check) so it is caught even when typed into a field with an innocent name.
    public static function redact_card_numbers( $key, $text ) {
        $text = (string) $text;
        // Long phone numbers can pass the Luhn check by chance.
        if ( preg_match( '/phone|mobile|(?:^|[^a-z])tel(?:[^a-z]|$)/', strtolower( (string) $key ) ) ) return $text;
        if ( preg_match_all( '/\d/', $text ) < 13 ) return $text;
        return preg_replace_callback( '/\d+(?:[ -]\d+)*/', function ( $m ) {
            $groups = preg_split( '/[ -]/', $m[0] );
            $n = count( $groups );
            for ( $i = 0; $i < $n; $i++ ) {
                $digits = '';
                for ( $j = $i; $j < $n; $j++ ) {
                    $digits .= $groups[ $j ];
                    $len = strlen( $digits );
                    if ( $len > 19 ) break;
                    if ( $len >= 13 && self::luhn( $digits ) ) return '[redacted]';
                }
            }
            return $m[0];
        }, $text );
    }
    private static function luhn( $digits ) {
        $sum = 0; $double = false;
        for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
            $d = (int) $digits[ $i ];
            if ( $double && ( $d *= 2 ) > 9 ) $d -= 9;
            $sum += $d; $double = ! $double;
        }
        return 0 === $sum % 10;
    }
    private static function sanitize_value( $field, $value ) {
        if ( is_array( $value ) ) {
            $value = array_map( function ( $v ) use ( $field ) {
                return is_scalar( $v ) ? self::redact_card_numbers( $field, sanitize_text_field( (string) $v ) ) : '';
            }, $value );
            return array_filter( $value, 'strlen' ) ? wp_json_encode( $value ) : '';
        }
        if ( ! is_scalar( $value ) ) return '';
        return self::redact_card_numbers( $field, mb_substr( sanitize_textarea_field( (string) $value ), 0, 10000 ) );
    }
    private static function clean_fields( $raw ) {
        $clean = array();
        foreach ( $raw as $field => $value ) {
            if ( count( $clean ) >= self::MAX_FIELDS ) break;
            $field = substr( sanitize_key( $field ), 0, 191 );
            if ( '' === $field || self::is_sensitive_key( $field ) ) continue;
            $value = self::sanitize_value( $field, $value );
            if ( '' !== $value ) $clean[ $field ] = $value;
        }
        return $clean;
    }

    private static function get_session( $key ) {
        global $wpdb;
        $t = WCT_DB::sessions_table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE session_key=%s", $key ) );
    }
    private static function create_session( $key ) {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
        // If a parallel request inserts the same key first, the unique index rejects this insert and both use that row.
        $suppress = $wpdb->suppress_errors( true );
        $wpdb->insert( WCT_DB::sessions_table(), array(
            'session_key'=>$key, 'status'=>'initiated', 'user_id'=>get_current_user_id() ?: null,
            'cart_total'=>0, 'currency'=>$currency, 'started_at'=>$now, 'last_activity_at'=>$now,
            'ip_hash'=>self::ip_hash(), 'user_agent'=>isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr( sanitize_text_field( wp_unslash($_SERVER['HTTP_USER_AGENT']) ), 0, 1000 ) : null,
            'created_at'=>$now, 'updated_at'=>$now
        ) );
        $wpdb->suppress_errors( $suppress );
        return self::get_session( $key );
    }
    private static function current_session( $is_page_load ) {
        $key = self::ensure_key();
        $row = self::get_session( $key );
        // A converted key belongs to its order. A new visit to checkout starts a new session instead of
        // overwriting the order's cart snapshot and link.
        if ( $row && $is_page_load && 'converted' === $row->status ) {
            $key = self::new_key();
            $row = null;
        }
        return $row ? $row : self::create_session( $key );
    }
    private static function activity_update( $row ) {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $update = array( 'last_activity_at' => $now, 'updated_at' => $now );
        $user_id = get_current_user_id();
        if ( $user_id && $user_id !== (int) $row->user_id ) $update['user_id'] = $user_id;
        // If an abandoned visitor returns to checkout, resume the session. Conditional, so it can never undo a
        // conversion that lands at the same moment. abandoned_notified_at is kept: one alert per session.
        if ( 'abandoned' === $row->status ) {
            $wpdb->query( $wpdb->prepare( 'UPDATE ' . WCT_DB::sessions_table() . " SET status='initiated', abandoned_at=NULL WHERE id=%d AND status='abandoned'", $row->id ) );
            $row->status = 'initiated';
        }
        return $update;
    }
    private static function save_session( $row, $update ) {
        global $wpdb;
        if ( $update ) $wpdb->update( WCT_DB::sessions_table(), $update, array( 'id' => (int) $row->id ) );
    }
    private static function store_fields( $session_id, $fields ) {
        global $wpdb;
        $t = WCT_DB::fields_table(); $now = current_time( 'mysql', true );
        $rows = array(); $args = array();
        foreach ( $fields as $key => $value ) {
            $rows[] = '(%d,%s,%s,%s)';
            array_push( $args, $session_id, $key, $value, $now );
        }
        // One statement for the whole batch; unlike REPLACE it updates rows in place.
        $wpdb->query( $wpdb->prepare( "INSERT INTO $t (session_id, field_key, field_value, updated_at) VALUES " . implode( ',', $rows ) . ' ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), updated_at = VALUES(updated_at)', $args ) );
    }
    // Recomputes the email / phone / name columns from stored fields, only when one of their source fields changed.
    private static function summary_update( $session_id, $fields ) {
        global $wpdb;
        $name_keys = array();
        foreach ( self::NAME_PREFIXES as $p ) { $name_keys[] = $p . 'first_name'; $name_keys[] = $p . 'last_name'; }
        $keys = array_merge( self::EMAIL_KEYS, self::PHONE_KEYS, $name_keys );
        if ( ! array_intersect( array_keys( $fields ), $keys ) ) return array();
        $t = WCT_DB::fields_table();
        $in = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        $values = wp_list_pluck( $wpdb->get_results( $wpdb->prepare( "SELECT field_key, field_value FROM $t WHERE session_id=%d AND field_key IN ($in)", array_merge( array( $session_id ), $keys ) ) ), 'field_value', 'field_key' );
        $email = '';
        foreach ( self::EMAIL_KEYS as $k ) {
            if ( ! empty( $values[ $k ] ) && ( $email = sanitize_email( $values[ $k ] ) ) ) break;
        }
        $phone = '';
        foreach ( self::PHONE_KEYS as $k ) {
            if ( ! empty( $values[ $k ] ) ) { $phone = $values[ $k ]; break; }
        }
        $name = '';
        foreach ( self::NAME_PREFIXES as $p ) {
            $first = isset( $values[ $p . 'first_name' ] ) ? $values[ $p . 'first_name' ] : '';
            $last  = isset( $values[ $p . 'last_name' ] ) ? $values[ $p . 'last_name' ] : '';
            if ( '' !== ( $name = trim( $first . ' ' . $last ) ) ) break;
        }
        return array(
            'email'         => $email ? mb_substr( $email, 0, 320 ) : null,
            'phone'         => '' !== $phone ? mb_substr( $phone, 0, 100 ) : null,
            'customer_name' => '' !== $name ? mb_substr( $name, 0, 255 ) : null,
        );
    }
    private static function cart_rows() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return null;
        $rows = array();
        foreach ( WC()->cart->get_cart() as $item ) {
            $product = isset($item['data']) ? $item['data'] : false; if ( ! $product ) continue;
            $rows[] = array(
                'product_id'=>(int) $product->get_id(), 'variation_id'=>isset($item['variation_id']) ? (int)$item['variation_id'] : 0,
                'product_name'=>$product->get_name(), 'sku'=>mb_substr( (string) $product->get_sku(), 0, 191 ), 'quantity'=>(float) $item['quantity'],
                'unit_price'=>(float)$product->get_price(), 'line_total'=>isset($item['line_total']) ? (float) $item['line_total'] : 0.0,
                'variation_json'=>!empty($item['variation']) ? wp_json_encode($item['variation']) : null
            );
        }
        return $rows;
    }
    // Rewrites the item snapshot only when the cart actually changed; returns the session columns to update.
    private static function sync_cart( $row ) {
        $items = self::cart_rows();
        if ( null === $items ) return array();
        $currency = get_woocommerce_currency();
        $hash = md5( wp_json_encode( array( $items, $currency ) ) );
        if ( $hash === $row->cart_hash ) return array();
        global $wpdb; $t = WCT_DB::items_table();
        $wpdb->delete( $t, array( 'session_id' => (int) $row->id ) );
        $count = 0; $total = 0;
        foreach ( $items as $item ) {
            $count += $item['quantity']; $total += $item['line_total'];
            $wpdb->insert( $t, array( 'session_id' => (int) $row->id ) + $item );
        }
        $row->cart_hash = $hash;
        return array( 'cart_total'=>$total, 'item_count'=>$count, 'currency'=>$currency, 'cart_hash'=>$hash );
    }

    public static function ajax_start() {
        if ( ! self::valid_nonce() ) wp_send_json_error( array('message'=>'Invalid request.'), 403 );
        $row = self::current_session( true );
        if ( ! $row ) wp_send_json_error( array('message'=>'Could not start session.'), 500 );
        $update = self::activity_update( $row );
        if ( 'converted' !== $row->status ) $update += self::sync_cart( $row );
        self::save_session( $row, $update );
        wp_send_json_success( array('session_id'=>(int) $row->id) );
    }
    public static function ajax_capture() {
        if ( ! self::valid_nonce() ) wp_send_json_error( array('message'=>'Invalid request.'), 403 );
        $row = self::current_session( false );
        if ( ! $row ) wp_send_json_error( array('message'=>'Could not start session.'), 500 );
        $fields = self::clean_fields( isset($_POST['fields']) && is_array($_POST['fields']) ? wp_unslash($_POST['fields']) : array() );
        $update = self::activity_update( $row );
        if ( $fields ) self::store_fields( (int) $row->id, $fields );
        // A converted session keeps the customer details and cart snapshot of its order.
        if ( 'converted' !== $row->status ) {
            if ( $fields ) $update += self::summary_update( (int) $row->id, $fields );
            $update += self::sync_cart( $row );
        }
        self::save_session( $row, $update );
        wp_send_json_success( array('session_id'=>(int) $row->id) );
    }

    public static function classic_order_processed( $order_id, $posted_data = array(), $order = null ) {
        self::mark_converted( $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );
    }
    public static function store_api_order_processed( $order ) {
        // The same hook fires when a customer pays an existing order via the Store API (/checkout/{id}),
        // which is not a conversion of the current checkout session.
        global $wp;
        $route = isset( $wp->query_vars['rest_route'] ) ? (string) $wp->query_vars['rest_route'] : '';
        if ( preg_match( '#/checkout/\d+/?$#', $route ) ) return;
        self::mark_converted( $order );
    }
    private static function mark_converted( $order ) {
        if ( ! $order instanceof WC_Order ) return;
        $key = self::cookie_key(); if ( '' === $key ) return;
        $row = self::get_session( $key ); if ( ! $row ) return;
        // Never re-point a session that already belongs to a different order.
        if ( 'converted' === $row->status && (int) $row->order_id && (int) $row->order_id !== $order->get_id() ) return;
        $now = current_time( 'mysql', true );
        // Final cart snapshot while the cart still holds the ordered items; it is frozen from here on.
        $update = self::sync_cart( $row ) + array( 'status'=>'converted', 'order_id'=>$order->get_id(), 'abandoned_at'=>null, 'last_activity_at'=>$now, 'updated_at'=>$now );
        // A payment retry re-fires this hook for the same order; keep the first conversion time.
        if ( 'converted' !== $row->status ) $update['converted_at'] = $now;
        $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        if ( $order->get_billing_email() ) $update['email'] = mb_substr( $order->get_billing_email(), 0, 320 );
        if ( $order->get_billing_phone() ) $update['phone'] = mb_substr( $order->get_billing_phone(), 0, 100 );
        if ( '' !== $name ) $update['customer_name'] = mb_substr( $name, 0, 255 );
        self::save_session( $row, $update );
        $order->update_meta_data( '_wct_checkout_session_id', (int) $row->id );
        $order->save();
    }

    public static function maintenance() {
        self::mark_abandoned();
        self::send_pending_alerts();
        self::cleanup();
    }
    public static function mark_abandoned() {
        global $wpdb;
        $s = self::settings();
        if ( empty( $s['enabled'] ) ) return;
        $minutes = min( 10080, max( 5, absint( $s['abandon_timeout_minutes'] ) ) );
        $cut = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * MINUTE_IN_SECONDS ) );
        $now = current_time( 'mysql', true );
        $st = WCT_DB::sessions_table();
        // A single conditional UPDATE, so a session that converts meanwhile is never flipped to abandoned.
        $wpdb->query( $wpdb->prepare( "UPDATE $st SET status='abandoned', abandoned_at=%s, updated_at=%s WHERE status='initiated' AND last_activity_at < %s", $now, $now, $cut ) );
    }
    public static function send_pending_alerts() {
        $s = self::settings();
        if ( empty( $s['enabled'] ) || empty( $s['email_alerts_enabled'] ) ) return;
        $recipients = preg_split( '/[,;\s]+/', (string) $s['email_alert_recipients'], -1, PREG_SPLIT_NO_EMPTY );
        $recipients = array_values( array_filter( array_map( 'sanitize_email', $recipients ) ) );
        if ( ! $recipients ) return;
        global $wpdb;
        $st = WCT_DB::sessions_table(); $now = current_time( 'mysql', true );
        // Only sessions someone can follow up on: contact details and at least one cart item. The 24h window
        // stops sessions abandoned long ago (or before abandoned_at existed) from flooding the inbox.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $st WHERE status='abandoned' AND abandoned_notified_at IS NULL AND abandoned_at >= %s AND item_count > 0 AND (email <> '' OR phone <> '') ORDER BY abandoned_at ASC LIMIT %d",
            gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), self::ALERT_BATCH
        ) );
        foreach ( $ids as $id ) {
            // Claim the session before sending, so overlapping cron runs cannot both alert it.
            $claimed = $wpdb->query( $wpdb->prepare( "UPDATE $st SET abandoned_notified_at=%s WHERE id=%d AND status='abandoned' AND abandoned_notified_at IS NULL", $now, $id ) );
            if ( 1 !== $claimed ) continue;
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $st WHERE id=%d", $id ) );
            // Already bought in another browser or device: nothing to follow up, and the claim keeps it from being re-checked.
            if ( ! $row || self::ordered_since( $row ) ) continue;
            if ( ! self::send_abandoned_alert( $row, $recipients ) ) {
                $wpdb->update( $st, array( 'abandoned_notified_at' => null ), array( 'id' => (int) $id ) ); // retry next run
            }
        }
    }
    private static function ordered_since( $row ) {
        if ( empty( $row->email ) || ! function_exists( 'wc_get_orders' ) ) return false;
        $orders = wc_get_orders( array(
            'billing_email' => $row->email,
            'date_created'  => '>=' . strtotime( $row->started_at . ' UTC' ),
            'status'        => array_merge( wc_get_is_paid_statuses(), array( 'on-hold' ) ),
            'limit'         => 1,
            'return'        => 'ids',
        ) );
        return ! empty( $orders );
    }

    private static function send_abandoned_alert( $row, $recipients ) {
        global $wpdb;
        $session_id = (int) $row->id;
        $ft = WCT_DB::fields_table(); $it = WCT_DB::items_table();
        $fields = $wpdb->get_results( $wpdb->prepare( "SELECT field_key, field_value FROM $ft WHERE session_id=%d ORDER BY field_key", $session_id ) );
        $items = $wpdb->get_results( $wpdb->prepare( "SELECT product_name, sku, quantity, unit_price, line_total, variation_json FROM $it WHERE session_id=%d ORDER BY id", $session_id ) );
        $url = admin_url( 'admin.php?page=wct-checkouts&view=' . $session_id );
        $subject = sprintf( '[%s] Abandoned checkout #%d', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $session_id );
        $currency = $row->currency ?: ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' );
        $body = '<h2>Abandoned Checkout Alert</h2>';
        $body .= '<p><strong>Session:</strong> #' . absint( $row->id ) . ' — ' . esc_html( $row->session_key ) . '</p>';
        $body .= '<p><strong>Last activity:</strong> ' . esc_html( $row->last_activity_at ) . ' UTC</p>';
        $body .= '<h3>Customer</h3><table cellpadding=6 cellspacing=0 border=1>';
        foreach ( array( 'Name' => $row->customer_name, 'Email' => $row->email, 'Phone' => $row->phone ) as $label => $value ) $body .= '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>' . esc_html( $value ?: '—' ) . '</td></tr>';
        $body .= '</table>';
        // Re-checked here as well, so rows stored by older versions never reach an inbox.
        $fields = array_filter( (array) $fields, function ( $f ) { return ! self::is_sensitive_key( $f->field_key ); } );
        if ( $fields ) { $body .= '<h3>Checkout Fields</h3><table cellpadding=6 cellspacing=0 border=1><tr><th>Field</th><th>Value</th></tr>'; foreach ( $fields as $f ) $body .= '<tr><td>' . esc_html( $f->field_key ) . '</td><td>' . nl2br( esc_html( self::redact_card_numbers( $f->field_key, $f->field_value ) ) ) . '</td></tr>'; $body .= '</table>'; }
        $body .= '<h3>Cart Items</h3><table cellpadding=6 cellspacing=0 border=1><tr><th>Product</th><th>SKU</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr>';
        foreach ( $items as $item ) { $body .= '<tr><td>' . esc_html( $item->product_name ) . '</td><td>' . esc_html( $item->sku ?: '—' ) . '</td><td>' . esc_html( $item->quantity ) . '</td><td>' . esc_html( $currency . ' ' . wc_format_decimal( $item->unit_price, 2 ) ) . '</td><td>' . esc_html( $currency . ' ' . wc_format_decimal( $item->line_total, 2 ) ) . '</td></tr>'; }
        $body .= '<tr><td colspan=4 align=right><strong>Total</strong></td><td><strong>' . esc_html( $currency . ' ' . wc_format_decimal( $row->cart_total, 2 ) ) . '</strong></td></tr></table>';
        $body .= '<p><a href="' . esc_url( $url ) . '">View checkout session in WP Admin</a></p>';
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        return (bool) wp_mail( $recipients, $subject, $body, $headers );
    }

    // One-time scan of fields stored by versions before 1.4.0: deletes rows whose name marks them as a credential
    // and redacts card-like numbers in the rest. Works in id order from a saved cursor, so it resumes after a
    // timeout; each run stops after ~20s and schedules the next until the table is done.
    public static function purge_sensitive() {
        $cursor = get_option( 'wct_purge_cursor' );
        if ( false === $cursor ) return;
        global $wpdb;
        $ft = WCT_DB::fields_table(); $cursor = (int) $cursor; $batch = 500; $deadline = time() + 20;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, field_key, field_value FROM $ft WHERE id > %d ORDER BY id LIMIT %d", $cursor, $batch ) );
            foreach ( $rows as $r ) {
                $cursor = (int) $r->id;
                if ( self::is_sensitive_key( $r->field_key ) ) {
                    $wpdb->delete( $ft, array( 'id' => $cursor ) );
                    continue;
                }
                $clean = self::redact_card_numbers( $r->field_key, $r->field_value );
                if ( $clean !== (string) $r->field_value ) $wpdb->update( $ft, array( 'field_value' => $clean ), array( 'id' => $cursor ) );
            }
            update_option( 'wct_purge_cursor', $cursor );
        } while ( count( $rows ) === $batch && time() < $deadline );
        if ( count( $rows ) < $batch ) {
            delete_option( 'wct_purge_cursor' );
            return;
        }
        wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'wct_purge_sensitive' );
    }

    public static function cleanup() {
        global $wpdb; $s=self::settings(); $days=max(1,absint($s['retention_days'])); $cut=gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS*$days);
        $st=WCT_DB::sessions_table(); $ft=WCT_DB::fields_table(); $it=WCT_DB::items_table();
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM $st WHERE created_at < %s",$cut)); if(!$ids) return;
        foreach($ids as $id){$wpdb->delete($ft,array('session_id'=>(int)$id));$wpdb->delete($it,array('session_id'=>(int)$id));$wpdb->delete($st,array('id'=>(int)$id));}
    }
}
