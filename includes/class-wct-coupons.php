<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Recovery coupons for abandoned checkout sessions. Each is a one-time WooCommerce coupon (WooCommerce does the
// discount maths, order lines and its atomic one-time-use hold) that only works in a browser that opened the
// session's coupon link. This plugin's coupons table is the source of truth for status, expiry and history.
class WCT_Coupons {
    // In this message {cart_items} is one product per line, so the list gets its own paragraph.
    const DEFAULT_TEMPLATE = "Hi {first_name},\n\nYou left these items in your cart at {site_name}:\n{cart_items}\n\nHere is {coupon_discount} off, just for you: {coupon_code}\nValid until {coupon_expires}.\n\nComplete your order here:\n{coupon_restore_url}";
    // Default of 1.7.0-1.7.1 (items inline); saved, unedited copies of it are upgraded to DEFAULT_TEMPLATE.
    const LEGACY_TEMPLATE  = "Hi {first_name},\n\nYou left {cart_items} in your cart at {site_name}.\n\nHere is {coupon_discount} off, just for you: {coupon_code}\nValid until {coupon_expires}.\n\nComplete your order here:\n{coupon_restore_url}";
    const PLACEHOLDERS = array(
        '{coupon_code}'        => 'The coupon code',
        '{coupon_discount}'    => 'e.g. "10%", "10% (up to ৳500)" or "৳200"',
        '{coupon_expires}'     => 'When the coupon expires',
        '{coupon_restore_url}' => 'Secure link that restores the cart and applies the coupon',
    );
    const ACTIVE        = array( 'generated', 'sent', 'applied' );
    const STATUSES      = array( 'generated' => 'Generated', 'sent' => 'Sent', 'applied' => 'Applied', 'used' => 'Used', 'expired' => 'Expired', 'revoked' => 'Revoked' );
    const META          = '_wct_recovery_coupon_id';
    const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I/L: easy to read out and type
    const SESSION_KEY   = 'wct_recovery_coupon';     // WC session: coupon id unlocked by opening its link in this browser
    const DISMISSED_KEY = 'wct_recovery_coupon_off'; // WC session: customer removed it themselves, so no auto re-apply
    const OFFER_KEY     = 'wct_recovery_offer';      // WC session: show the offer popup on the next cart/checkout page
    const NOTICES_KEY   = 'wct_recovery_notices';    // WC session: messages for the Cart/Checkout blocks
    const REMOVED_KEY   = 'wct_recovery_coupon_min'; // WC session: we removed it for the minimum (so "re-applied" is accurate)

    private static $cache = array();
    private static $busy = false;      // re-entrancy guard for our own apply/remove during totals calculation
    private static $removing = false;  // our own removal (below minimum), not the customer's

    public static function init() {
        add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate' ), 10, 3 );
        add_filter( 'woocommerce_coupon_get_amount', array( __CLASS__, 'dynamic_amount' ), 10, 2 );
        add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'enforce_minimum' ), 20 );
        add_action( 'woocommerce_applied_coupon', array( __CLASS__, 'on_applied' ) );
        add_action( 'woocommerce_removed_coupon', array( __CLASS__, 'on_removed' ) );
        // Same order-status moments at which WooCommerce records / releases coupon usage (it runs at priority 10).
        foreach ( array( 'pending', 'processing', 'on-hold', 'completed' ) as $status ) add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'maybe_mark_used' ), 20, 2 );
        foreach ( array( 'cancelled', 'failed' ) as $status ) add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'order_ended' ), 20 );
        add_action( 'woocommerce_trash_order', array( __CLASS__, 'order_ended' ), 20 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'front_assets' ) );
        if ( did_action( 'woocommerce_blocks_loaded' ) ) self::register_store_api_data(); else add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api_data' ) );
        add_action( 'wp_ajax_wct_coupon_create', array( __CLASS__, 'ajax_create' ) );
        add_action( 'wp_ajax_wct_coupon_revoke', array( __CLASS__, 'ajax_revoke' ) );
    }
    public static function enabled() { $s = WCT_Tracker::settings(); return ! empty( $s['coupon_enabled'] ); }
    public static function defaults() {
        $s = WCT_Tracker::settings();
        return array( 'type' => 'fixed' === $s['coupon_type'] ? 'fixed' : 'percent', 'amount' => $s['coupon_amount'], 'min_cart' => $s['coupon_min_cart'], 'max_discount' => $s['coupon_max_discount'], 'validity_hours' => $s['coupon_validity_hours'] );
    }

    // ---------------------------------------------------------------- Records

    private static function table() { return WCT_DB::coupons_table(); }
    public static function get( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! array_key_exists( $id, self::$cache ) ) self::$cache[ $id ] = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d', $id ) ) : null;
        return self::$cache[ $id ];
    }
    private static function forget( $id ) { unset( self::$cache[ (int) $id ] ); }
    public static function active_for_session( $session_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE active_session_id=%d', $session_id ) );
    }
    // Latest coupon per session (active one if any): [ session_id => row ].
    public static function latest_for_sessions( $session_ids ) {
        global $wpdb;
        $session_ids = array_filter( array_map( 'absint', (array) $session_ids ) );
        if ( ! $session_ids ) return array();
        $rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE session_id IN (' . implode( ',', $session_ids ) . ') ORDER BY (active_session_id IS NOT NULL) DESC, id DESC' );
        $out = array();
        foreach ( $rows as $r ) if ( ! isset( $out[ (int) $r->session_id ] ) ) $out[ (int) $r->session_id ] = $r;
        return $out;
    }
    public static function for_session( $session_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE session_id=%d ORDER BY id DESC', $session_id ) );
    }
    private static function id_from_code( $code ) {
        $wc_id = wc_get_coupon_id_by_code( $code );
        return $wc_id ? (int) get_post_meta( $wc_id, self::META, true ) : 0;
    }
    public static function is_expired( $row ) { return strtotime( $row->expires_at . ' UTC' ) <= time(); }
    // Status as shown to admins: an active coupon past its expiry shows Expired even before the scheduled task runs.
    public static function display_status( $row ) {
        return in_array( $row->status, self::ACTIVE, true ) && self::is_expired( $row ) ? 'expired' : $row->status;
    }
    public static function is_usable( $row ) { return $row && in_array( $row->status, self::ACTIVE, true ) && ! self::is_expired( $row ); }
    public static function discount_label( $row ) {
        if ( 'fixed' === $row->discount_type ) return WCT_Recovery::plain_price( $row->amount, get_woocommerce_currency() );
        $pct = rtrim( rtrim( number_format( (float) $row->amount, 2, '.', '' ), '0' ), '.' ) . '%';
        return $row->max_discount ? sprintf( '%s (up to %s)', $pct, WCT_Recovery::plain_price( $row->max_discount, get_woocommerce_currency() ) ) : $pct;
    }
    public static function expires_label( $row ) {
        return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row->expires_at . ' UTC' ) );
    }
    // Coupon placeholders for the coupon WhatsApp template.
    public static function placeholders( $row, $restore_url ) {
        return array( '{coupon_code}' => $row->code, '{coupon_discount}' => self::discount_label( $row ), '{coupon_expires}' => self::expires_label( $row ), '{coupon_restore_url}' => $restore_url );
    }

    // ---------------------------------------------------------------- Create / revoke / expire

    // Validates admin input for one coupon; returns the clean config or WP_Error.
    public static function clean_config( $in ) {
        $type = ( isset( $in['type'] ) && 'fixed' === $in['type'] ) ? 'fixed' : 'percent';
        $num = function ( $key ) use ( $in ) { $v = isset( $in[ $key ] ) ? trim( (string) wp_unslash( $in[ $key ] ) ) : ''; return '' === $v ? null : (float) $v; };
        $amount = $num( 'amount' ); $min = $num( 'min_cart' ); $max = $num( 'max_discount' ); $hours = $num( 'validity_hours' );
        if ( ! $amount || $amount <= 0 ) return new WP_Error( 'amount', 'Enter a discount greater than 0.' );
        if ( 'percent' === $type && $amount > 100 ) return new WP_Error( 'amount', 'A percentage discount cannot be more than 100%.' );
        if ( null !== $min && $min < 0 ) return new WP_Error( 'min', 'The minimum cart value cannot be negative.' );
        if ( null !== $max && $max < 0 ) return new WP_Error( 'max', 'The maximum discount cannot be negative.' );
        if ( ! $hours || $hours < 1 || $hours > 720 || floor( $hours ) != $hours ) return new WP_Error( 'hours', 'Validity must be a whole number of hours between 1 and 720.' );
        $dp = wc_get_price_decimals();
        return array(
            'type' => $type, 'amount' => round( $amount, 'percent' === $type ? 2 : $dp ),
            'min_cart' => $min ? round( $min, $dp ) : null,
            'max_discount' => ( 'percent' === $type && $max ) ? round( $max, $dp ) : null, // a cap only makes sense for percentages
            'validity_hours' => (int) $hours,
        );
    }
    private static function new_code() {
        $code = 'RCV-';
        for ( $i = 0; $i < 8; $i++ ) $code .= self::CODE_ALPHABET[ random_int( 0, strlen( self::CODE_ALPHABET ) - 1 ) ];
        return $code;
    }
    // One active coupon per session is enforced by the unique active_session_id column, so two simultaneous
    // "Generate" clicks can never create two active coupons.
    public static function create( $session_id, $config ) {
        global $wpdb;
        $ct = self::table(); $now = current_time( 'mysql', true );
        $expires = gmdate( 'Y-m-d H:i:s', time() + $config['validity_hours'] * HOUR_IN_SECONDS );
        self::expire_due(); // frees the slot of a coupon that is past its expiry but not yet marked
        $ok = false;
        for ( $try = 0; $try < 5 && ! $ok; $try++ ) {
            $code = self::new_code();
            if ( wc_get_coupon_id_by_code( $code ) ) continue;
            $suppress = $wpdb->suppress_errors( true );
            $ok = $wpdb->insert( $ct, array(
                'session_id' => $session_id, 'active_session_id' => $session_id, 'code' => $code, 'discount_type' => $config['type'], 'amount' => $config['amount'],
                'min_cart' => $config['min_cart'], 'max_discount' => $config['max_discount'], 'validity_hours' => $config['validity_hours'],
                'status' => 'generated', 'created_at' => $now, 'created_by' => get_current_user_id() ?: null, 'expires_at' => $expires,
            ) );
            $error = $wpdb->last_error;
            $wpdb->suppress_errors( $suppress );
            if ( ! $ok && false !== stripos( $error, 'active_session_id' ) ) return new WP_Error( 'active', 'This session already has an active coupon. Revoke it first to create a new one.' );
        }
        if ( ! $ok ) return new WP_Error( 'code', 'Could not create a unique coupon code. Please try again.' );
        $id = (int) $wpdb->insert_id;
        try {
            $capped = 'percent' === $config['type'] && $config['max_discount'];
            $coupon = new WC_Coupon();
            $coupon->set_code( $code );
            // Capped percentages are recalculated on every cart change in dynamic_amount(); the stored amount is the cap.
            $coupon->set_discount_type( 'percent' === $config['type'] && ! $capped ? 'percent' : 'fixed_cart' );
            $coupon->set_amount( $capped ? $config['max_discount'] : $config['amount'] );
            $coupon->set_usage_limit( 1 );
            $coupon->set_individual_use( true ); // never combined with other coupons
            $coupon->set_date_expires( strtotime( $expires . ' UTC' ) );
            $coupon->set_description( sprintf( 'Recovery coupon for checkout session #%d. Created and managed by Checkout Tracker.', $session_id ) );
            $coupon->update_meta_data( self::META, $id );
            $wc_id = $coupon->save();
        } catch ( Exception $e ) {
            $wc_id = 0;
        }
        if ( ! $wc_id ) { $wpdb->delete( $ct, array( 'id' => $id ) ); return new WP_Error( 'wc', 'WooCommerce could not create the coupon.' ); }
        $wpdb->update( $ct, array( 'wc_coupon_id' => $wc_id ), array( 'id' => $id ) );
        WCT_DB::log_event( 'coupon_generated', $session_id, array( 'coupon_id' => $id, 'data' => array( 'code' => $code, 'type' => $config['type'], 'amount' => $config['amount'], 'min_cart' => $config['min_cart'], 'max_discount' => $config['max_discount'], 'validity_hours' => $config['validity_hours'], 'expires_at' => $expires ) ) );
        self::forget( $id );
        return self::get( $id );
    }
    public static function revoke( $id, $reason = '' ) {
        global $wpdb;
        $row = self::get( $id );
        $n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status='revoked', active_session_id=NULL, revoked_at=%s, revoked_by=%d, revoke_reason=%s WHERE id=%d AND status IN ('generated','sent','applied')", current_time( 'mysql', true ), get_current_user_id(), mb_substr( (string) $reason, 0, 255 ), $id ) );
        self::forget( $id );
        if ( 1 !== $n ) return false;
        self::close_wc_coupon( $row );
        WCT_DB::log_event( 'coupon_revoked', $row->session_id, array( 'coupon_id' => $id, 'data' => $reason ? array( 'reason' => $reason ) : array() ) );
        return true;
    }
    // Also expire the WooCommerce coupon itself, so it stays unusable even if this plugin is later deactivated.
    private static function close_wc_coupon( $row ) {
        if ( ! $row || ! $row->wc_coupon_id ) return;
        $coupon = new WC_Coupon( (int) $row->wc_coupon_id );
        if ( $coupon->get_id() ) { $coupon->set_date_expires( time() - MINUTE_IN_SECONDS ); $coupon->save(); }
    }
    // Scheduled: mark coupons whose validity has run out.
    public static function expire_due() {
        global $wpdb;
        $ct = self::table(); $now = current_time( 'mysql', true );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, session_id FROM $ct WHERE status IN ('generated','sent','applied') AND expires_at <= %s LIMIT 200", $now ) );
        foreach ( $rows as $r ) {
            if ( 1 === $wpdb->query( $wpdb->prepare( "UPDATE $ct SET status='expired', expired_at=%s, active_session_id=NULL WHERE id=%d AND status IN ('generated','sent','applied')", $now, $r->id ) ) ) {
                WCT_DB::log_event( 'coupon_expired', $r->session_id, array( 'coupon_id' => $r->id, 'user_id' => null ) );
            }
            self::forget( $r->id );
        }
    }
    // Deactivating the plugin removes the "only via the personal link" check, so close any coupon still open.
    public static function close_all_active() {
        global $wpdb;
        foreach ( $wpdb->get_col( 'SELECT id FROM ' . self::table() . " WHERE status IN ('generated','sent','applied')" ) as $id ) self::revoke( $id, 'Plugin deactivated' );
        // Used coupons whose order was cancelled got their WooCommerce usage back; close those as well.
        foreach ( $wpdb->get_results( 'SELECT * FROM ' . self::table() . " WHERE status='used' AND expires_at > UTC_TIMESTAMP()" ) as $row ) self::close_wc_coupon( $row );
    }
    public static function mark_sent( $row ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status=IF(status='generated','sent',status), sent_at=COALESCE(sent_at,%s) WHERE id=%d", current_time( 'mysql', true ), $row->id ) );
        self::forget( $row->id );
    }

    // ---------------------------------------------------------------- WooCommerce rules

    // Cart value the coupon is measured against: the items subtotal as the customer sees it (current prices).
    private static function cart_basis( $cart = null ) {
        $cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
        return $cart ? (float) $cart->get_displayed_subtotal() : 0.0;
    }
    public static function validate( $valid, $coupon, $discounts = null ) {
        $id = (int) $coupon->get_meta( self::META );
        if ( ! $id ) return $valid;
        $row = self::get( $id );
        $object = ( $discounts && method_exists( $discounts, 'get_object' ) ) ? $discounts->get_object() : null;
        // The order that used it stays valid (e.g. paying a failed order again, or recalculating it in admin).
        if ( $row && 'used' === $row->status && $object instanceof WC_Order && (int) $row->order_id === $object->get_id() ) return $valid;
        if ( $row && 'used' === $row->status ) throw new Exception( 'This coupon has already been used.', 100 );
        if ( ! $row || ! in_array( $row->status, self::ACTIVE, true ) ) throw new Exception( 'This coupon is no longer valid.', 100 );
        if ( self::is_expired( $row ) ) throw new Exception( 'This coupon has expired.', 107 );
        if ( ! $object instanceof WC_Order ) {
            // Session-specific: only a browser that opened this session's coupon link can use the code.
            if ( ! WC()->session || (int) WC()->session->get( self::SESSION_KEY ) !== $id ) throw new Exception( 'This coupon only works through the personal link it was sent with.', 100 );
            if ( $row->min_cart && self::cart_basis( $object instanceof WC_Cart ? $object : null ) < (float) $row->min_cart ) {
                throw new Exception( sprintf( 'Coupon %1$s needs a cart of at least %2$s.', $row->code, WCT_Recovery::plain_price( $row->min_cart, get_woocommerce_currency() ) ), 108 );
            }
        }
        return $valid;
    }
    // Percentage with a maximum: min( % of the current cart, cap ), recalculated on every cart change.
    public static function dynamic_amount( $amount, $coupon ) {
        $id = (int) $coupon->get_meta( self::META );
        if ( ! $id ) return $amount;
        $row = self::get( $id );
        if ( ! $row || 'percent' !== $row->discount_type || ! $row->max_discount ) return $amount;
        if ( 'used' === $row->status && null !== $row->discount_total ) return (float) $row->discount_total; // e.g. order recalculated in admin
        $basis = self::cart_basis();
        if ( $basis <= 0 ) return $amount;
        return (float) wc_format_decimal( min( $basis * (float) $row->amount / 100, (float) $row->max_discount ), wc_get_price_decimals() );
    }
    // Minimum cart value: remove the coupon (and say so) when the cart drops below it; re-apply it (and say so)
    // when the cart qualifies again, unless the customer removed it themselves.
    public static function enforce_minimum( $cart ) {
        if ( self::$busy || ! WC()->session ) return;
        $id = (int) WC()->session->get( self::SESSION_KEY );
        $row = $id ? self::get( $id ) : null;
        if ( ! $row || ! $row->min_cart || ! self::is_usable( $row ) ) return;
        $code = wc_format_coupon_code( $row->code );
        $applied = $cart->has_discount( $code );
        $basis = self::cart_basis( $cart ); $min = (float) $row->min_cart;
        $currency = get_woocommerce_currency();
        self::$busy = true;
        if ( $applied && $basis < $min ) {
            self::$removing = true; $cart->remove_coupon( $code ); self::$removing = false;
            $cart->calculate_totals();
            WC()->session->set( self::REMOVED_KEY, true );
            self::notify( sprintf( 'Your cart is now below %1$s, so coupon %2$s was removed. Add %3$s more and it will be applied again automatically.', WCT_Recovery::plain_price( $min, $currency ), $row->code, WCT_Recovery::plain_price( $min - $basis, $currency ) ) );
            WCT_DB::log_event( 'coupon_removed_min', $row->session_id, array( 'coupon_id' => $id, 'user_id' => null, 'data' => array( 'cart' => $basis, 'min' => $min ) ) );
        } elseif ( ! $applied && $basis >= $min && ! WC()->session->get( self::DISMISSED_KEY ) && ! self::other_coupons( $cart, $code ) ) {
            // (If the customer added a different coupon meanwhile, keep theirs: individual use would silently remove it.)
            $notices = wc_get_notices();
            $ok = $cart->apply_coupon( $code );
            wc_set_notices( $notices ); // replace WooCommerce's generic message with ours below
            if ( $ok ) {
                $cart->calculate_totals();
                $again = (bool) WC()->session->get( self::REMOVED_KEY );
                WC()->session->set( self::REMOVED_KEY, null );
                self::notify( sprintf( $again ? 'Your cart qualifies again, so coupon %s has been re-applied.' : 'Your cart now qualifies, so coupon %s has been applied.', $row->code ) );
                WCT_DB::log_event( 'coupon_reapplied', $row->session_id, array( 'coupon_id' => $id, 'user_id' => null, 'data' => array( 'cart' => $basis, 'first_time' => ! $again ) ) );
            }
        }
        self::$busy = false;
    }
    // Coupons in the cart other than $code.
    private static function other_coupons( $cart, $code ) {
        return array_values( array_filter( $cart->get_applied_coupons(), function ( $c ) use ( $code ) { return ! wc_is_same_coupon( $c, $code ); } ) );
    }
    public static function on_applied( $code ) {
        $id = self::id_from_code( $code );
        if ( ! $id || ! WC()->session ) return;
        WC()->session->set( self::DISMISSED_KEY, null );
        global $wpdb;
        $n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status='applied', applied_at=COALESCE(applied_at,%s) WHERE id=%d AND status IN ('generated','sent')", current_time( 'mysql', true ), $id ) );
        self::forget( $id );
        if ( 1 === $n ) { $row = self::get( $id ); WCT_DB::log_event( 'coupon_applied', $row->session_id, array( 'coupon_id' => $id, 'user_id' => null ) ); }
    }
    public static function on_removed( $code ) {
        if ( self::$removing || ! WC()->session ) return;
        $id = self::id_from_code( $code );
        if ( $id && (int) WC()->session->get( self::SESSION_KEY ) === $id ) WC()->session->set( self::DISMISSED_KEY, true );
    }
    // Messages for the customer: WooCommerce notices on classic pages; for the Cart/Checkout blocks (Store API),
    // which drop informational notices, they travel in the cart response and assets/js/recovery.js shows them.
    private static function notify( $message ) {
        // REST_REQUEST covers both /wp-json/ and ?rest_route= style Store API requests.
        if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || WC()->is_rest_api_request() ) {
            $queue = (array) WC()->session->get( self::NOTICES_KEY, array() );
            $queue[] = $message;
            WC()->session->set( self::NOTICES_KEY, array_slice( $queue, -5 ) );
        } else {
            wc_add_notice( esc_html( $message ), 'notice' );
        }
    }
    public static function register_store_api_data() {
        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema' ) ) return;
        woocommerce_store_api_register_endpoint_data( array(
            'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
            'namespace'       => 'wct',
            'data_callback'   => array( __CLASS__, 'store_api_data' ),
            'schema_callback' => function () { return array( 'notices' => array( 'description' => 'Recovery coupon messages', 'type' => 'array', 'items' => array( 'type' => 'string' ), 'context' => array( 'view', 'edit' ), 'readonly' => true ) ); },
            'schema_type'     => ARRAY_A,
        ) );
    }
    public static function store_api_data() {
        $queue = ( function_exists( 'WC' ) && WC()->session ) ? (array) WC()->session->get( self::NOTICES_KEY, array() ) : array();
        if ( $queue ) WC()->session->set( self::NOTICES_KEY, array() );
        return array( 'notices' => array_values( $queue ) );
    }

    // ---------------------------------------------------------------- Restore link

    // A coupon link restores the cart and then applies the coupon itself; the minimum check stays quiet meanwhile.
    public static function hold( $on ) { self::$busy = (bool) $on; }
    // Called after a coupon link restored the cart: unlock the coupon for this browser and apply it to the
    // current (restored) cart. Returns a short result for the history.
    public static function apply_from_link( $coupon_id, $session_id ) {
        $row = self::get( $coupon_id );
        if ( ! $row || (int) $row->session_id !== (int) $session_id ) { self::$busy = false; return 'mismatch'; }
        if ( ! self::is_usable( $row ) ) {
            wc_add_notice( esc_html( 'Your cart has been restored. The discount offer in this link is no longer available.' ), 'notice' );
            self::$busy = false;
            return 'unavailable';
        }
        WC()->session->set( self::SESSION_KEY, (int) $row->id );
        WC()->session->set( self::DISMISSED_KEY, null );
        WC()->session->set( self::REMOVED_KEY, null );
        $cart = WC()->cart;
        self::$busy = true; // this flow applies the coupon itself; the minimum check must not act (or message) meanwhile
        $cart->calculate_totals();
        $s = WCT_Tracker::settings();
        $show_offer = ! empty( $s['coupon_offer_modal'] );
        $currency = get_woocommerce_currency();
        if ( $row->min_cart && self::cart_basis( $cart ) < (float) $row->min_cart ) {
            wc_add_notice( esc_html( sprintf( 'Add %1$s more to your cart to get %2$s off with coupon %3$s. It will be applied automatically.', WCT_Recovery::plain_price( (float) $row->min_cart - self::cart_basis( $cart ), $currency ), self::discount_label( $row ), $row->code ) ), 'notice' );
            if ( $show_offer ) WC()->session->set( self::OFFER_KEY, true );
            self::$busy = false;
            return 'below_minimum';
        }
        $code = wc_format_coupon_code( $row->code );
        $ok = $cart->has_discount( $code );
        $replaced = $ok ? array() : self::other_coupons( $cart, $code );
        if ( ! $ok ) {
            $notices = wc_get_notices();
            $ok = $cart->apply_coupon( $code );
            wc_set_notices( $notices );
            $cart->calculate_totals();
        }
        self::$busy = false;
        if ( ! $ok ) { wc_add_notice( esc_html( 'Your cart has been restored, but the coupon could not be applied.' ), 'notice' ); return 'failed'; }
        // Individual use: the link's coupon replaced any other coupon in the cart; say so.
        if ( $replaced ) wc_add_notice( esc_html( sprintf( 'Coupon %1$s cannot be combined with other coupons, so %2$s was removed.', $row->code, strtoupper( implode( ', ', $replaced ) ) ) ), 'notice' );
        if ( $show_offer ) WC()->session->set( self::OFFER_KEY, true );
        else wc_add_notice( esc_html( sprintf( 'Coupon %1$s applied: %2$s off.', $row->code, self::discount_label( $row ) ) ), 'success' );
        return 'applied';
    }

    // ---------------------------------------------------------------- Used (permanently)

    public static function maybe_mark_used( $order_id, $order = null ) {
        global $wpdb;
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order || ! $order->get_data_store()->get_recorded_coupon_usage_counts( $order ) ) return; // WooCommerce has not counted it
        $incl = 'incl' === get_option( 'woocommerce_tax_display_cart' );
        foreach ( $order->get_items( 'coupon' ) as $item ) {
            $id = self::id_from_code( $item->get_code() );
            if ( ! $id ) continue;
            $discount = (float) $item->get_discount() + ( $incl ? (float) $item->get_discount_tax() : 0 );
            // Atomic claim: only one order can ever turn a coupon into Used.
            $n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status='used', used_at=%s, order_id=%d, discount_total=%s, active_session_id=NULL WHERE id=%d AND status<>'used'", current_time( 'mysql', true ), $order->get_id(), wc_format_decimal( $discount ), $id ) );
            self::forget( $id );
            if ( 1 !== $n ) continue;
            $row = self::get( $id );
            $wpdb->update( WCT_DB::sessions_table(), array( 'converted_coupon_id' => $id ), array( 'id' => (int) $row->session_id ) );
            WCT_DB::log_event( 'coupon_used', $row->session_id, array( 'coupon_id' => $id, 'order_id' => $order->get_id(), 'user_id' => null, 'data' => array( 'discount' => $discount ) ) );
        }
    }
    // Order cancelled / failed / trashed: the coupon stays Used by that order and is never usable for anything else
    // (validate() refuses it, even though WooCommerce gives its usage count back). A failed order can still be paid.
    public static function order_ended( $order_id ) {
        global $wpdb;
        $order = wc_get_order( $order_id );
        $status = $order ? $order->get_status() : 'trash';
        foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE order_id=%d AND status='used'", $order_id ) ) as $row ) {
            WCT_DB::log_event( 'coupon_order_ended', $row->session_id, array( 'coupon_id' => $row->id, 'order_id' => $order_id, 'user_id' => null, 'data' => array( 'order_status' => $status ) ) );
        }
    }
    // "cancelled" / "payment failed" / "refunded" / "trashed" for a converted session's order, '' when it is fine.
    public static function order_outcome( $order_id ) {
        if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) return '';
        $order = wc_get_order( $order_id );
        if ( ! $order ) return 'deleted';
        $labels = array( 'cancelled' => 'cancelled', 'failed' => 'payment failed', 'refunded' => 'refunded', 'trash' => 'trashed' );
        return $labels[ $order->get_status() ] ?? '';
    }

    // ---------------------------------------------------------------- Offer popup (storefront)

    public static function front_assets() {
        if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || ! ( is_cart() || is_checkout() ) || is_order_received_page() ) return;
        $id = (int) WC()->session->get( self::SESSION_KEY );
        if ( ! $id ) return;
        $offer = null;
        if ( WC()->session->get( self::OFFER_KEY ) ) {
            $offer = self::offer_data( $id );
            WC()->session->set( self::OFFER_KEY, null ); // show once
        }
        wp_enqueue_style( 'wct-recovery', WCT_URL . 'assets/css/recovery.css', array(), wct_asset_ver( 'assets/css/recovery.css' ) );
        wp_enqueue_script( 'wct-recovery', WCT_URL . 'assets/js/recovery.js', array(), wct_asset_ver( 'assets/js/recovery.js' ), true );
        wp_localize_script( 'wct-recovery', 'WCTRecovery', array( 'offer' => $offer, 'context' => is_checkout() ? 'wc/checkout' : 'wc/cart' ) );
    }
    // Always computed from the current cart and WooCommerce's own discount calculation.
    private static function offer_data( $id ) {
        $row = self::get( $id );
        if ( ! self::is_usable( $row ) ) return null;
        $cart = WC()->cart;
        $cart->calculate_totals();
        $currency = get_woocommerce_currency();
        $subtotal = self::cart_basis( $cart );
        $code = wc_format_coupon_code( $row->code );
        $pct = 'percent' === $row->discount_type ? rtrim( rtrim( number_format( (float) $row->amount, 2, '.', '' ), '0' ), '.' ) . '%' : '';
        $data = array( 'code' => $row->code, 'discountLabel' => self::discount_label( $row ), 'discountPct' => $pct, 'expires' => self::expires_label( $row ), 'cart' => WCT_Recovery::plain_price( $subtotal, $currency ) );
        if ( ! $cart->has_discount( $code ) ) {
            $data['locked'] = true;
            $data['needMore'] = $row->min_cart ? WCT_Recovery::plain_price( max( 0, (float) $row->min_cart - $subtotal ), $currency ) : '';
            return $data;
        }
        $discount = (float) $cart->get_coupon_discount_amount( $code, 'incl' !== get_option( 'woocommerce_tax_display_cart' ) );
        $data['discount'] = WCT_Recovery::plain_price( $discount, $currency );
        $data['total'] = WCT_Recovery::plain_price( max( 0, $subtotal - $discount ), $currency );
        return $data;
    }

    // ---------------------------------------------------------------- Admin AJAX

    private static function guard() {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'wct_admin', 'nonce', false ) ) wp_send_json_error( array( 'message' => 'You are not allowed to do this, or your login has expired. Reload the page and try again.' ), 403 );
    }
    private static function session_or_fail() {
        global $wpdb;
        $id = absint( $_POST['id'] ?? 0 );
        $row = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WCT_DB::sessions_table() . ' WHERE id=%d', $id ) ) : null;
        if ( ! $row ) wp_send_json_error( array( 'message' => 'This checkout session no longer exists.' ), 404 );
        return $row;
    }
    public static function ajax_create() {
        self::guard();
        if ( ! self::enabled() ) wp_send_json_error( array( 'message' => 'Recovery coupons are turned off in Checkout Tracker settings.' ), 400 );
        $session = self::session_or_fail();
        if ( 'converted' === $session->status ) wp_send_json_error( array( 'message' => 'This customer already placed an order from this session.' ), 400 );
        $config = self::clean_config( $_POST );
        if ( is_wp_error( $config ) ) wp_send_json_error( array( 'message' => $config->get_error_message() ), 400 );
        $row = self::create( (int) $session->id, $config );
        if ( is_wp_error( $row ) ) wp_send_json_error( array( 'message' => $row->get_error_message() ), 409 );
        wp_send_json_success( WCT_Admin::cells( (int) $session->id ) + array( 'message' => sprintf( 'Coupon %s created.', $row->code ) ) );
    }
    public static function ajax_revoke() {
        self::guard();
        $session = self::session_or_fail();
        $row = self::active_for_session( $session->id );
        if ( ! $row || ! self::revoke( $row->id, 'Revoked by admin' ) ) wp_send_json_error( array( 'message' => 'This session has no active coupon to revoke.' ), 400 );
        wp_send_json_success( WCT_Admin::cells( (int) $session->id ) + array( 'message' => sprintf( 'Coupon %s revoked.', $row->code ) ) );
    }
}
