<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCT_Tracker {
    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'wp_ajax_wct_start', array( __CLASS__, 'ajax_start' ) );
        add_action( 'wp_ajax_nopriv_wct_start', array( __CLASS__, 'ajax_start' ) );
        add_action( 'wp_ajax_wct_capture', array( __CLASS__, 'ajax_capture' ) );
        add_action( 'wp_ajax_nopriv_wct_capture', array( __CLASS__, 'ajax_capture' ) );
        add_action( 'wp_ajax_wct_refresh_cart', array( __CLASS__, 'ajax_refresh_cart' ) );
        add_action( 'wp_ajax_nopriv_wct_refresh_cart', array( __CLASS__, 'ajax_refresh_cart' ) );
        add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'order_meta' ), 10, 2 );
        add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'order_processed' ), 10, 3 );
        add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou' ), 10 );
        add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
        add_action( 'wct_maintenance', array( __CLASS__, 'maintenance' ) );
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
    }
    public static function reschedule() {
        wp_clear_scheduled_hook( 'wct_maintenance' );
        self::ensure_schedule();
    }
    public static function deactivate() { wp_clear_scheduled_hook( 'wct_maintenance' ); }
    public static function assets() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) return;
        $s = self::settings(); if ( empty( $s['enabled'] ) ) return;
        wp_enqueue_script( 'wct-checkout', WCT_URL . 'assets/js/checkout.js', array( 'jquery' ), WCT_VERSION, true );
        wp_localize_script( 'wct-checkout', 'WCT', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wct_checkout' ),
            'interval'=> 3000,
        ) );
    }
    private static function valid_nonce() { return isset( $_REQUEST['nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ), 'wct_checkout' ); }
    private static function key() {
        if ( ! empty( $_COOKIE['wct_checkout_key'] ) ) return preg_replace( '/[^a-zA-Z0-9_-]/', '', wp_unslash( $_COOKIE['wct_checkout_key'] ) );
        $key = wp_generate_uuid4();
        setcookie( 'wct_checkout_key', $key, time() + DAY_IN_SECONDS * 30, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), false );
        $_COOKIE['wct_checkout_key'] = $key;
        return $key;
    }
    private static function ip_hash() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        return $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ) : null;
    }
    private static function is_sensitive( $key ) {
        $k = strtolower( (string) $key );
        $bad = array( 'password', 'pass1', 'pass2', 'account_password', 'payment', 'card', 'cc-', 'cvc', 'cvv', 'security_code', 'iban', 'routing', 'token' );
        foreach ( $bad as $x ) if ( strpos( $k, $x ) !== false ) return true;
        return in_array( $k, array( 'woocommerce-process-checkout-nonce', 'terms', 'terms-field', 'createaccount' ), true );
    }
    private static function sanitize_value( $value ) {
        if ( is_array( $value ) ) return wp_json_encode( array_map( 'sanitize_text_field', wp_unslash( $value ) ) );
        $value = wp_unslash( (string) $value );
        return mb_substr( sanitize_textarea_field( $value ), 0, 10000 );
    }
    private static function upsert_session( $key ) {
        global $wpdb;
        $t = WCT_DB::sessions_table(); $now = current_time( 'mysql', true );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE session_key=%s", $key ) );
        if ( $row ) {
            $update = array( 'last_activity_at' => $now, 'updated_at' => $now, 'user_id' => get_current_user_id() ?: $row->user_id );
            // If an abandoned visitor returns to checkout, resume the session.
            if ( 'abandoned' === $row->status ) $update['status'] = 'initiated';
            $wpdb->update( $t, $update, array( 'id' => $row->id ) );
            return (int) $row->id;
        }
        $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
        $wpdb->insert( $t, array(
            'session_key'=>$key, 'status'=>'initiated', 'user_id'=>get_current_user_id() ?: null,
            'cart_total'=>0, 'currency'=>$currency, 'started_at'=>$now, 'last_activity_at'=>$now,
            'ip_hash'=>self::ip_hash(), 'user_agent'=>isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr( sanitize_text_field( wp_unslash($_SERVER['HTTP_USER_AGENT']) ), 0, 1000 ) : null,
            'created_at'=>$now, 'updated_at'=>$now
        ) );
        return (int) $wpdb->insert_id;
    }
    private static function sync_cart( $session_id ) {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;
        global $wpdb; $t = WCT_DB::items_table();
        $wpdb->delete( $t, array( 'session_id' => $session_id ) );
        $count = 0; $total = 0;
        foreach ( WC()->cart->get_cart() as $item ) {
            $product = isset($item['data']) ? $item['data'] : false; if ( ! $product ) continue;
            $qty = (float) $item['quantity']; $line = (float) $item['line_total']; $count += $qty; $total += $line;
            $wpdb->insert( $t, array(
                'session_id'=>$session_id, 'product_id'=>(int) $product->get_id(), 'variation_id'=>isset($item['variation_id']) ? (int)$item['variation_id'] : 0,
                'product_name'=>$product->get_name(), 'sku'=>$product->get_sku(), 'quantity'=>$qty,
                'unit_price'=>(float)$product->get_price(), 'line_total'=>$line,
                'variation_json'=>!empty($item['variation']) ? wp_json_encode($item['variation']) : null
            ) );
        }
        $wpdb->update( WCT_DB::sessions_table(), array( 'cart_total'=>$total, 'item_count'=>$count, 'currency'=>get_woocommerce_currency(), 'last_activity_at'=>current_time('mysql', true), 'updated_at'=>current_time('mysql', true) ), array('id'=>$session_id) );
    }
    public static function ajax_start() {
        if ( ! self::valid_nonce() ) wp_send_json_error( array('message'=>'Invalid request.'), 403 );
        $id = self::upsert_session( self::key() ); self::sync_cart( $id ); wp_send_json_success(array('session_id'=>$id));
    }
    public static function ajax_capture() {
        if ( ! self::valid_nonce() ) wp_send_json_error( array('message'=>'Invalid request.'), 403 );
        $key = self::key(); $id = self::upsert_session( $key );
        $fields = isset($_POST['fields']) && is_array($_POST['fields']) ? wp_unslash($_POST['fields']) : array();
        global $wpdb; $t = WCT_DB::fields_table(); $now = current_time('mysql', true);
        foreach ( $fields as $field => $value ) {
            $field = sanitize_key( $field ); if ( !$field || self::is_sensitive($field) ) continue;
            $value = self::sanitize_value($value); if ($value === '') continue;
            $wpdb->replace( $t, array('session_id'=>$id,'field_key'=>$field,'field_value'=>$value,'updated_at'=>$now) );
            $email = sanitize_email( $value );
            if ( $field === 'billing_email' || $field === 'email' ) $wpdb->update(WCT_DB::sessions_table(),array('email'=>$email),array('id'=>$id));
            if ( $field === 'billing_phone' || $field === 'phone' ) $wpdb->update(WCT_DB::sessions_table(),array('phone'=>sanitize_text_field($value)),array('id'=>$id));
        }
        $first = isset($fields['billing_first_name']) ? sanitize_text_field($fields['billing_first_name']) : '';
        $last  = isset($fields['billing_last_name']) ? sanitize_text_field($fields['billing_last_name']) : '';
        if ($first || $last) $wpdb->update(WCT_DB::sessions_table(),array('customer_name'=>trim($first.' '.$last)),array('id'=>$id));
        self::sync_cart($id); wp_send_json_success(array('session_id'=>$id));
    }
    public static function ajax_refresh_cart() {
        if ( ! self::valid_nonce() ) wp_send_json_error(array('message'=>'Invalid request.'),403);
        $id = self::upsert_session(self::key()); self::sync_cart($id); wp_send_json_success();
    }
    public static function order_meta( $order_id, $posted_data ) {
        $key = self::key(); if (!$key) return; $id = self::find_id($key); if ($id) update_post_meta($order_id,'_wct_checkout_session_id',$id);
    }
    public static function order_processed( $order_id, $posted_data, $order ) {
        $key = self::key(); $id = self::find_id($key); if (!$id) return;
        global $wpdb; $t=WCT_DB::sessions_table(); $now=current_time('mysql',true);
        $email=$order->get_billing_email(); $phone=$order->get_billing_phone(); $name=trim($order->get_billing_first_name().' '.$order->get_billing_last_name());
        $wpdb->update($t,array('status'=>'converted','order_id'=>$order_id,'email'=>$email,'phone'=>$phone,'customer_name'=>$name,'converted_at'=>$now,'last_activity_at'=>$now,'updated_at'=>$now),array('id'=>$id));
        update_post_meta($order_id,'_wct_checkout_session_id',$id);
    }
    public static function thankyou( $order_id ) { if ($order_id) { $order=get_post($order_id); if($order) { /* keep tracking data intact */ } } }
    private static function find_id($key) { global $wpdb; return (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.WCT_DB::sessions_table().' WHERE session_key=%s',$key)); }
    public static function maintenance() {
        self::mark_abandoned();
        self::cleanup();
    }
    public static function mark_abandoned() {
        global $wpdb;
        $s = self::settings();
        if ( empty( $s['enabled'] ) ) return;
        $minutes = min( 10080, max( 5, absint( $s['abandon_timeout_minutes'] ) ) );
        $cut = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * MINUTE_IN_SECONDS ) );
        $st = WCT_DB::sessions_table();
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $st WHERE status='initiated' AND last_activity_at < %s", $cut ) );
        if ( ! $rows ) return;
        foreach ( $rows as $row ) {
            $now = current_time( 'mysql', true );
            $updated = $wpdb->update( $st, array( 'status' => 'abandoned', 'updated_at' => $now, 'abandoned_notified_at' => null ), array( 'id' => (int) $row->id ) );
            if ( $updated !== false ) self::send_abandoned_alert( (int) $row->id );
        }
    }

    private static function send_abandoned_alert( $session_id ) {
        $s = self::settings();
        if ( empty( $s['email_alerts_enabled'] ) ) return;
        $recipients = preg_split( '/[,;\s]+/', (string) $s['email_alert_recipients'], -1, PREG_SPLIT_NO_EMPTY );
        $recipients = array_values( array_filter( array_map( 'sanitize_email', $recipients ) ) );
        if ( ! $recipients ) return;
        global $wpdb;
        $st = WCT_DB::sessions_table(); $ft = WCT_DB::fields_table(); $it = WCT_DB::items_table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $st WHERE id=%d AND status='abandoned'", $session_id ) );
        if ( ! $row || ! empty( $row->abandoned_notified_at ) ) return;
        $fields = $wpdb->get_results( $wpdb->prepare( "SELECT field_key, field_value FROM $ft WHERE session_id=%d ORDER BY field_key", $session_id ) );
        $items = $wpdb->get_results( $wpdb->prepare( "SELECT product_name, sku, quantity, unit_price, line_total, variation_json FROM $it WHERE session_id=%d ORDER BY id", $session_id ) );
        $url = admin_url( 'admin.php?page=wct-checkouts&view=' . absint( $session_id ) );
        $subject = sprintf( '[%s] Abandoned checkout #%d', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $session_id );
        $currency = $row->currency ?: ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' );
        $body = '<h2>Abandoned Checkout Alert</h2>';
        $body .= '<p><strong>Session:</strong> #' . absint( $row->id ) . ' — ' . esc_html( $row->session_key ) . '</p>';
        $body .= '<p><strong>Last activity:</strong> ' . esc_html( $row->last_activity_at ) . ' UTC</p>';
        $body .= '<h3>Customer</h3><table cellpadding=6 cellspacing=0 border=1>'; 
        foreach ( array( 'Name' => $row->customer_name, 'Email' => $row->email, 'Phone' => $row->phone ) as $label => $value ) $body .= '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>' . esc_html( $value ?: '—' ) . '</td></tr>';
        $body .= '</table>';
        if ( $fields ) { $body .= '<h3>Checkout Fields</h3><table cellpadding=6 cellspacing=0 border=1><tr><th>Field</th><th>Value</th></tr>'; foreach ( $fields as $f ) $body .= '<tr><td>' . esc_html( $f->field_key ) . '</td><td>' . nl2br( esc_html( $f->field_value ) ) . '</td></tr>'; $body .= '</table>'; }
        $body .= '<h3>Cart Items</h3><table cellpadding=6 cellspacing=0 border=1><tr><th>Product</th><th>SKU</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr>';
        foreach ( $items as $item ) { $body .= '<tr><td>' . esc_html( $item->product_name ) . '</td><td>' . esc_html( $item->sku ?: '—' ) . '</td><td>' . esc_html( $item->quantity ) . '</td><td>' . esc_html( $currency . ' ' . wc_format_decimal( $item->unit_price, 2 ) ) . '</td><td>' . esc_html( $currency . ' ' . wc_format_decimal( $item->line_total, 2 ) ) . '</td></tr>'; }
        $body .= '<tr><td colspan=4 align=right><strong>Total</strong></td><td><strong>' . esc_html( $currency . ' ' . wc_format_decimal( $row->cart_total, 2 ) ) . '</strong></td></tr></table>';
        $body .= '<p><a href="' . esc_url( $url ) . '">View checkout session in WP Admin</a></p>';
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $sent = wp_mail( $recipients, $subject, $body, $headers );
        if ( $sent ) $wpdb->update( $st, array( 'abandoned_notified_at' => current_time( 'mysql', true ) ), array( 'id' => $session_id ) );
    }

    public static function cleanup() {
        global $wpdb; $s=self::settings(); $days=max(1,absint($s['retention_days'])); $cut=gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS*$days);
        $st=WCT_DB::sessions_table(); $ft=WCT_DB::fields_table(); $it=WCT_DB::items_table();
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM $st WHERE created_at < %s",$cut)); if(!$ids) return;
        foreach($ids as $id){$wpdb->delete($ft,array('session_id'=>(int)$id));$wpdb->delete($it,array('session_id'=>(int)$id));$wpdb->delete($st,array('id'=>(int)$id));}
    }
}
