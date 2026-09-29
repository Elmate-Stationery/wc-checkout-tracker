<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// WhatsApp click-to-chat messages for checkout sessions, and the secure {cart_restore_url} links they carry.
class WCT_Recovery {
    const QUERY_VAR        = 'wct-restore';
    const DEFAULT_TEMPLATE = "Hi {first_name},\n\nYou left {cart_items} in your cart at {site_name} ({cart_total}).\n\nContinue your order here:\n{cart_restore_url}";
    const PLACEHOLDERS     = array(
        '{first_name}'       => 'Customer first name ("there" when unknown)',
        '{customer_name}'    => 'Customer full name',
        '{cart_items}'       => 'Items left in the cart, e.g. "2 × T-shirt (M, Blue)"',
        '{cart_total}'       => 'Cart total with currency',
        '{cart_restore_url}' => 'Secure link that restores the cart on any device',
        '{site_name}'        => 'Your store name',
    );
    const MAX_ITEMS_IN_MESSAGE = 5;
    const MAX_MESSAGE_LENGTH   = 2000;
    const COUNTRY_KEYS         = array( 'billing_country', 'billing-country', 'shipping_country', 'shipping-country' );
    const FIRST_NAME_KEYS      = array( 'billing_first_name', 'billing-first_name', 'shipping_first_name', 'shipping-first_name' );
    // Link-preview fetchers (WhatsApp, Facebook, Telegram, Slack, ...) must never restore a cart.
    const PREVIEW_AGENTS       = '/bot|crawl|spider|slurp|preview|facebookexternalhit|facebookcatalog|whatsapp|telegram|slack|discord|skypeuri|linkedin|embedly|pinterest|vkshare|google-pagerenderer|headless/i';

    public static function init() {
        add_action( 'template_redirect', array( __CLASS__, 'maybe_restore' ), 1 );
        add_action( 'wp_ajax_wct_whatsapp_link', array( __CLASS__, 'ajax_whatsapp_link' ) );
        add_action( 'wp_ajax_wct_whatsapp_coupon_link', array( __CLASS__, 'ajax_whatsapp_coupon_link' ) );
    }

    // ---------------------------------------------------------------- WhatsApp message

    // Converts a stored phone (e.g. "01670019801", "+880 1670-019801") into WhatsApp's international digits
    // ("8801670019801"). Numbers without + or 00 get the customer's country code, or the default from settings.
    public static function whatsapp_number( $phone, $country = '' ) {
        $phone  = trim( (string) $phone );
        $digits = preg_replace( '/\D/', '', $phone );
        if ( '' === $digits ) return '';
        if ( 0 === strpos( $phone, '+' ) ) {
            // already international
        } elseif ( 0 === strpos( $digits, '00' ) ) {
            $digits = substr( $digits, 2 );
        } else {
            $cc = self::calling_code( $country );
            $has_cc = '' !== $cc && 0 === strpos( $digits, $cc ) && strlen( $digits ) - strlen( $cc ) >= 8;
            if ( ! $has_cc ) $digits = $cc . ltrim( $digits, '0' );
        }
        $len = strlen( $digits );
        return ( $len >= 8 && $len <= 15 ) ? $digits : '';
    }
    private static function calling_code( $country ) {
        $country = strtoupper( (string) $country );
        if ( preg_match( '/^[A-Z]{2}$/', $country ) && function_exists( 'WC' ) && WC()->countries ) {
            $code = preg_replace( '/\D/', '', (string) WC()->countries->get_country_calling_code( $country ) );
            if ( '' !== $code ) return $code;
        }
        $s = WCT_Tracker::settings();
        return preg_replace( '/\D/', '', (string) $s['whatsapp_country_code'] );
    }
    // Captured values for the given field keys of one or more sessions: [ session_id => [ key => value ] ].
    public static function session_fields( $session_ids, $keys ) {
        global $wpdb;
        $session_ids = array_filter( array_map( 'absint', (array) $session_ids ) );
        if ( ! $session_ids ) return array();
        $ft = WCT_DB::fields_table();
        $sql = "SELECT session_id, field_key, field_value FROM $ft WHERE session_id IN (" . implode( ',', array_fill( 0, count( $session_ids ), '%d' ) ) . ') AND field_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')';
        $out = array();
        foreach ( $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $session_ids, $keys ) ) ) as $r ) $out[ (int) $r->session_id ][ $r->field_key ] = $r->field_value;
        return $out;
    }
    private static function first_value( $values, $keys ) {
        foreach ( $keys as $k ) if ( isset( $values[ $k ] ) && '' !== trim( $values[ $k ] ) ) return trim( $values[ $k ] );
        return '';
    }
    // WhatsApp number for a session row; $fields are its captured country fields (loaded when not given).
    public static function number_for( $row, $fields = null ) {
        if ( null === $fields ) { $all = self::session_fields( array( $row->id ), self::COUNTRY_KEYS ); $fields = isset( $all[ (int) $row->id ] ) ? $all[ (int) $row->id ] : array(); }
        return self::whatsapp_number( $row->phone, self::first_value( $fields, self::COUNTRY_KEYS ) );
    }
    public static function plain_price( $amount, $currency ) {
        $html = wc_price( (float) $amount, array( 'currency' => $currency ) );
        return trim( str_replace( "\xC2\xA0", ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
    }
    private static function item_label( $item ) {
        $opts = array();
        foreach ( (array) json_decode( (string) $item->variation_json, true ) as $attr => $value ) {
            if ( ! is_scalar( $value ) || '' === (string) $value ) continue;
            $tax  = preg_replace( '/^attribute_/', '', (string) $attr );
            $term = taxonomy_exists( $tax ) ? get_term_by( 'slug', (string) $value, $tax ) : false;
            $opts[] = $term ? $term->name : (string) $value;
        }
        $qty = wc_stock_amount( $item->quantity );
        return $qty . ' × ' . $item->product_name . ( $opts ? ' (' . implode( ', ', $opts ) . ')' : '' );
    }
    // Fills the template. Only customer name, cart contents/total, the restore link and the store name are used,
    // never raw captured fields, so nothing sensitive can end up in a message.
    // $template: the normal WhatsApp template unless given (the coupon template); $extra: more placeholders;
    // $item_lines: {cart_items} as one product per line (coupon message) instead of comma-separated.
    public static function message( $row, $restore_url, $template = null, $extra = array(), $item_lines = false ) {
        global $wpdb;
        $s = WCT_Tracker::settings();
        if ( null === $template ) $template = (string) $s['whatsapp_template'];
        if ( '' === trim( $template ) ) $template = self::DEFAULT_TEMPLATE;
        $it = WCT_DB::items_table();
        $items = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, variation_id, product_name, quantity, variation_json FROM $it WHERE session_id=%d ORDER BY id", $row->id ) );
        // List only what the restore link will actually bring back: available items, quantities capped to stock,
        // and a total at current prices. If nothing is available any more, fall back to the saved total.
        $available = array(); $total = 0.0;
        foreach ( $items as $item ) {
            $product = self::available_product( $item );
            $qty = $product ? self::purchasable_qty( $product, wc_stock_amount( $item->quantity ) ) : 0;
            if ( $qty <= 0 ) continue;
            $item->quantity = $qty;
            $available[] = $item;
            $total += (float) wc_get_price_to_display( $product, array( 'qty' => $qty ) );
        }
        $labels = array_map( array( __CLASS__, 'item_label' ), array_slice( $available, 0, self::MAX_ITEMS_IN_MESSAGE ) );
        if ( count( $available ) > self::MAX_ITEMS_IN_MESSAGE ) $labels[] = sprintf( $item_lines ? '+ %d more' : 'and %d more', count( $available ) - self::MAX_ITEMS_IN_MESSAGE );
        $cart_total = $available ? self::plain_price( $total, get_woocommerce_currency() ) : self::plain_price( $row->cart_total, $row->currency ?: get_woocommerce_currency() );
        $fields = self::session_fields( array( $row->id ), self::FIRST_NAME_KEYS );
        $name = trim( WCT_Tracker::redact_card_numbers( 'customer_name', (string) $row->customer_name ) );
        $first = self::first_value( isset( $fields[ (int) $row->id ] ) ? $fields[ (int) $row->id ] : array(), self::FIRST_NAME_KEYS );
        if ( '' === $first && '' !== $name ) $first = strtok( $name, ' ' );
        $message = strtr( $template, $extra + array(
            '{first_name}'       => '' !== $first ? $first : 'there',
            '{customer_name}'    => '' !== $name ? $name : 'there',
            '{cart_items}'       => $labels ? implode( $item_lines ? "\n" : ', ', $labels ) : 'some items',
            '{cart_total}'       => $cart_total,
            '{cart_restore_url}' => $restore_url,
            '{site_name}'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
        ) );
        return mb_substr( $message, 0, self::MAX_MESSAGE_LENGTH );
    }
    public static function contact_label( $row ) {
        if ( empty( $row->whatsapp_contacted_at ) ) return '';
        $user = $row->whatsapp_contacted_by ? get_userdata( $row->whatsapp_contacted_by ) : false;
        $label = sprintf( 'Sent %s ago', human_time_diff( strtotime( $row->whatsapp_contacted_at . ' UTC' ) ) );
        if ( $user ) $label .= ' by ' . $user->display_name;
        if ( (int) $row->whatsapp_contact_count > 1 ) $label .= ' (' . (int) $row->whatsapp_contact_count . '×)';
        return $label;
    }

    // Common checks for both WhatsApp buttons; returns [ session row, WhatsApp number ].
    private static function whatsapp_target() {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'wct_admin', 'nonce', false ) ) wp_send_json_error( array( 'message' => 'You are not allowed to do this, or your login has expired. Reload the page and try again.' ), 403 );
        $s = WCT_Tracker::settings();
        if ( empty( $s['whatsapp_enabled'] ) ) wp_send_json_error( array( 'message' => 'WhatsApp messages are turned off in Checkout Tracker settings.' ), 400 );
        global $wpdb;
        $id = absint( $_POST['id'] ?? 0 );
        $row = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WCT_DB::sessions_table() . ' WHERE id=%d', $id ) ) : null;
        if ( ! $row ) wp_send_json_error( array( 'message' => 'This checkout session no longer exists.' ), 404 );
        if ( 'converted' === $row->status ) wp_send_json_error( array( 'message' => 'This customer already placed an order from this session.' ), 400 );
        $number = self::number_for( $row );
        if ( '' === $number ) wp_send_json_error( array( 'message' => 'This session has no valid phone number for WhatsApp.' ), 400 );
        return array( $row, $number );
    }
    // [WhatsApp]: the normal template and normal restore link; never includes coupon information.
    public static function ajax_whatsapp_link() {
        list( $row, $number ) = self::whatsapp_target();
        global $wpdb;
        $s = WCT_Tracker::settings();
        $restore = ''; $token_id = null;
        if ( false !== strpos( (string) $s['whatsapp_template'], '{cart_restore_url}' ) ) {
            if ( (int) $row->item_count > 0 ) { $restore = self::restore_url( self::create_token( $row->id ) ); $token_id = self::$last_token_id; }
            else $restore = wc_get_page_permalink( 'shop' );
        }
        $url = 'https://wa.me/' . $number . '?text=' . rawurlencode( self::message( $row, $restore ) );
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . WCT_DB::sessions_table() . ' SET whatsapp_contacted_at=%s, whatsapp_contacted_by=%d, whatsapp_contact_count=whatsapp_contact_count+1 WHERE id=%d', current_time( 'mysql', true ), get_current_user_id(), $row->id ) );
        WCT_DB::log_event( 'whatsapp_sent', $row->id, array( 'token_id' => $token_id, 'data' => array( 'kind' => 'cart' ) ) );
        wp_send_json_success( array( 'url' => $url ) + WCT_Admin::cells( (int) $row->id ) );
    }
    // [WhatsApp + Coupon]: the separate coupon template, with a restore link bound to this session's active coupon.
    public static function ajax_whatsapp_coupon_link() {
        list( $row, $number ) = self::whatsapp_target();
        if ( ! WCT_Coupons::enabled() ) wp_send_json_error( array( 'message' => 'Recovery coupons are turned off in Checkout Tracker settings.' ), 400 );
        $coupon = WCT_Coupons::active_for_session( $row->id );
        if ( ! WCT_Coupons::is_usable( $coupon ) ) wp_send_json_error( array( 'message' => 'This session has no active coupon. Add a coupon first.' ), 400 );
        global $wpdb;
        $s = WCT_Tracker::settings();
        $restore = self::restore_url( self::create_token( $row->id, $coupon->id ) );
        $token_id = self::$last_token_id;
        $message = self::message( $row, $restore, (string) $s['coupon_template'] ?: WCT_Coupons::DEFAULT_TEMPLATE, WCT_Coupons::placeholders( $coupon, $restore ), true );
        $url = 'https://wa.me/' . $number . '?text=' . rawurlencode( $message );
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . WCT_DB::sessions_table() . ' SET whatsapp_coupon_contacted_at=%s, whatsapp_coupon_contacted_by=%d, whatsapp_coupon_contact_count=whatsapp_coupon_contact_count+1 WHERE id=%d', current_time( 'mysql', true ), get_current_user_id(), $row->id ) );
        WCT_Coupons::mark_sent( $coupon );
        WCT_DB::log_event( 'whatsapp_sent', $row->id, array( 'coupon_id' => $coupon->id, 'token_id' => $token_id, 'data' => array( 'kind' => 'coupon' ) ) );
        wp_send_json_success( array( 'url' => $url ) + WCT_Admin::cells( (int) $row->id ) );
    }

    // ---------------------------------------------------------------- Link-open tracking

    // Short device description for the history, e.g. "Android · Chrome". No full user agent is kept.
    public static function device_label( $agent ) {
        $agent = (string) $agent;
        $os = 'Unknown device';
        foreach ( array( 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Macintosh' => 'Mac', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux' ) as $needle => $label ) {
            if ( false !== stripos( $agent, $needle ) ) { $os = $label; break; }
        }
        $browser = '';
        foreach ( array( 'Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox/' => 'Firefox', 'FxiOS' => 'Firefox', 'CriOS' => 'Chrome', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari' ) as $needle => $label ) {
            if ( false !== stripos( $agent, $needle ) ) { $browser = $label; break; }
        }
        return $browser ? $os . ' · ' . $browser : $os;
    }
    // Per session and link kind ('cart' / 'coupon'): links sent, links opened, total opens, last open, opens after expiry.
    public static function link_stats( $session_ids ) {
        global $wpdb;
        $session_ids = array_filter( array_map( 'absint', (array) $session_ids ) );
        if ( ! $session_ids ) return array();
        $rows = $wpdb->get_results( 'SELECT session_id, IF(coupon_id IS NULL, \'cart\', \'coupon\') AS kind, COUNT(*) AS sent, SUM(use_count > 0) AS opened_links, SUM(use_count) AS opens, MIN(first_used_at) AS first_open, MAX(last_used_at) AS last_open, SUM(expired_open_count) AS expired_opens FROM ' . WCT_DB::tokens_table() . ' WHERE session_id IN (' . implode( ',', $session_ids ) . ') GROUP BY session_id, kind' );
        $out = array();
        foreach ( $rows as $r ) $out[ (int) $r->session_id ][ $r->kind ] = $r;
        return $out;
    }
    public static function open_label( $stat ) {
        if ( ! $stat ) return '';
        if ( ! (int) $stat->opens ) return 'Link not opened yet' . ( (int) $stat->expired_opens ? sprintf( ' (tried %d× after it expired)', $stat->expired_opens ) : '' );
        return sprintf( 'Link opened %d× · last %s ago', $stat->opens, human_time_diff( strtotime( $stat->last_open . ' UTC' ) ) );
    }

    // ---------------------------------------------------------------- Restore tokens

    // 256-bit random token; only its SHA-256 hash is stored, so the database never holds a usable link.
    public static $last_token_id = null;
    // A coupon link ($coupon_id) is bound to that coupon server-side; the URL itself only carries the random token.
    public static function create_token( $session_id, $coupon_id = null ) {
        global $wpdb;
        $s = WCT_Tracker::settings();
        $days = min( 30, max( 1, absint( $s['restore_link_days'] ) ) );
        $token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
        $wpdb->insert( WCT_DB::tokens_table(), array(
            'session_id' => (int) $session_id, 'coupon_id' => $coupon_id ? (int) $coupon_id : null, 'token_hash' => hash( 'sha256', $token ), 'created_at' => current_time( 'mysql', true ),
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ), 'created_by' => get_current_user_id() ?: null,
        ) );
        self::$last_token_id = (int) $wpdb->insert_id;
        return $token;
    }
    public static function restore_url( $token ) {
        return add_query_arg( self::QUERY_VAR, $token, home_url( '/' ) );
    }
    // Found even when expired, so opens after expiry can be tracked; the caller checks token_expires.
    private static function token_record( $token ) {
        global $wpdb;
        if ( ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) ) return null;
        $tt = WCT_DB::tokens_table(); $st = WCT_DB::sessions_table();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT t.id AS token_id, t.coupon_id AS token_coupon_id, t.expires_at AS token_expires, s.* FROM $tt t INNER JOIN $st s ON s.id = t.session_id WHERE t.token_hash=%s",
            hash( 'sha256', $token )
        ) );
    }
    private static function is_preview_request() {
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) return true;
        $agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
        return '' === $agent || (bool) preg_match( self::PREVIEW_AGENTS, $agent );
    }
    private static function go( $url ) {
        wp_safe_redirect( $url );
        exit;
    }

    // ---------------------------------------------------------------- Cart restore

    // ?wct-restore=<token>: restore the saved cart into this browser and redirect straight to the admin-chosen page.
    public static function maybe_restore() {
        if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) return;
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        nocache_headers();
        if ( ! headers_sent() ) header( 'X-Robots-Tag: noindex, nofollow' );
        $s = WCT_Tracker::settings();
        // Only the admin setting decides the destination; nothing in the URL can change it.
        $destination = 'cart' === $s['restore_destination'] ? wc_get_cart_url() : wc_get_checkout_url();
        if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) self::go( home_url( '/' ) );
        if ( self::is_preview_request() ) self::go( wc_get_cart_url() ); // link previews: no restore, no tracking

        $row = self::token_record( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
        // Guests on a new device have no WooCommerce session yet; start one so the restored cart persists.
        if ( ! WC()->session->has_session() ) WC()->session->set_customer_session_cookie( true );
        if ( ! $row ) {
            wc_add_notice( 'This cart link is invalid or has expired.', 'notice' );
            self::go( wc_get_cart_url() );
        }
        global $wpdb; $now = current_time( 'mysql', true ); $tt = WCT_DB::tokens_table();
        $agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
        $device = self::device_label( $agent );
        $kind = $row->token_coupon_id ? 'coupon' : 'cart';
        // Staff testing a link get the cart, but it is not counted as the customer opening it, and their
        // browser is not attached to the customer's session.
        $staff = current_user_can( 'manage_woocommerce' );
        $event = array( 'token_id' => $row->token_id, 'coupon_id' => $row->token_coupon_id, 'data' => array( 'kind' => $kind, 'device' => $device ) );
        if ( strtotime( $row->token_expires . ' UTC' ) <= time() ) {
            if ( ! $staff ) $wpdb->query( $wpdb->prepare( "UPDATE $tt SET expired_open_count=expired_open_count+1, last_device=%s WHERE id=%d", $device, $row->token_id ) );
            WCT_DB::log_event( $staff ? 'link_tested_by_staff' : 'link_opened_expired', $row->id, $event );
            wc_add_notice( 'This cart link is invalid or has expired.', 'notice' );
            self::go( wc_get_cart_url() );
        }
        if ( ! $staff ) $wpdb->query( $wpdb->prepare( "UPDATE $tt SET use_count=use_count+1, first_used_at=COALESCE(first_used_at,%s), last_used_at=%s, last_device=%s WHERE id=%d", $now, $now, $device, $row->token_id ) );
        if ( 'converted' === $row->status ) {
            $event['data']['result'] = 'already_ordered';
            WCT_DB::log_event( $staff ? 'link_tested_by_staff' : 'link_opened', $row->id, $event );
            // A converted session never restores (and never re-applies its coupon), even if its order was cancelled.
            $outcome = WCT_Coupons::order_outcome( $row->order_id );
            $message = in_array( $outcome, array( 'cancelled', 'trashed' ), true ) ? 'This order was cancelled.'
                : ( 'payment failed' === $outcome ? 'The payment for this order did not go through. Please use the payment link in your order email, or contact us.' : 'This order has already been placed. Thank you!' );
            wc_add_notice( $message, 'notice' );
            self::go( wc_get_cart_url() );
        }

        if ( $row->token_coupon_id ) WCT_Coupons::hold( true );
        $result = self::restore_items( (int) $row->id );
        if ( ! $staff ) {
            WCT_Tracker::adopt_session( $row->session_key );
            $wpdb->update( WCT_DB::sessions_table(), array( 'restored_at' => $now ), array( 'id' => (int) $row->id ) );
        }
        $event['data'] += array( 'restored' => $result['restored'], 'skipped' => count( $result['skipped'] ) );
        // Coupon link: apply this session's coupon to the cart as it is now (current prices and stock).
        if ( $row->token_coupon_id && $result['restored'] ) $event['data']['coupon'] = WCT_Coupons::apply_from_link( (int) $row->token_coupon_id, (int) $row->id );
        WCT_Coupons::hold( false );
        WCT_DB::log_event( $staff ? 'link_tested_by_staff' : 'link_opened', $row->id, $event );
        if ( $result['skipped'] ) {
            $message = $result['restored']
                ? sprintf( 'Some items from your saved cart are no longer available and were not added: %s.', implode( ', ', $result['skipped'] ) )
                : 'Sorry, the items from your saved cart are no longer available.';
            wc_add_notice( esc_html( $message ), $result['restored'] ? 'notice' : 'error' );
        }
        if ( $result['reduced'] ) wc_add_notice( esc_html( sprintf( 'Quantities were reduced to what is in stock for: %s.', implode( ', ', $result['reduced'] ) ) ), 'notice' );
        // With nothing restorable, checkout would bounce to an empty cart anyway; go there with the notice.
        self::go( $result['restored'] ? $destination : wc_get_cart_url() );
    }

    // Current product for a saved cart item, or null if it can no longer be bought: deleted, unpublished (or its
    // parent product), not purchasable, out of stock, or a variable product without a chosen variation.
    // Shared by the WhatsApp message and the restore, so both agree on what is available.
    private static function available_product( $item ) {
        $product = wc_get_product( (int) $item->variation_id ?: (int) $item->product_id );
        if ( ! $product || $product->is_type( 'variable' ) ) return null;
        if ( $product->is_type( 'variation' ) ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( ! $parent || 'publish' !== $parent->get_status() ) return null;
        }
        if ( 'publish' !== $product->get_status() || ! $product->is_purchasable() || ! $product->is_in_stock() ) return null;
        return $product;
    }
    // $qty capped to what can be bought at once (stock, sold individually); -1 from WooCommerce means unlimited.
    private static function purchasable_qty( $product, $qty ) {
        $max = $product->get_max_purchase_quantity();
        return $max > 0 ? min( $qty, $max ) : $qty;
    }

    // Adds the saved items to the current cart using current products, prices and stock.
    // Merge rule: an item already in this cart ends with max(current quantity, saved quantity), so nothing in an
    // existing cart is removed or reduced, and opening the link again never inflates quantities.
    public static function restore_items( $session_id ) {
        global $wpdb;
        $it = WCT_DB::items_table();
        $items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $it WHERE session_id=%d ORDER BY id", $session_id ) );
        $cart = WC()->cart;
        $result = array( 'restored' => 0, 'skipped' => array(), 'reduced' => array() );
        foreach ( $items as $item ) {
            $name = (string) $item->product_name;
            $qty = wc_stock_amount( $item->quantity );
            $product = self::available_product( $item );
            if ( ! $product || $qty <= 0 ) { $result['skipped'][] = $name; continue; }
            $variation_id = 0; $attributes = array(); $parent_id = $product->get_id();
            if ( $product->is_type( 'variation' ) ) {
                $variation_id = $product->get_id(); $parent_id = $product->get_parent_id();
                foreach ( (array) json_decode( (string) $item->variation_json, true ) as $k => $v ) {
                    if ( 0 === strpos( (string) $k, 'attribute_' ) && is_scalar( $v ) ) $attributes[ $k ] = (string) $v;
                }
            }

            $key = $cart->find_product_in_cart( $cart->generate_cart_id( $parent_id, $variation_id, $attributes ) );
            $current = $key ? wc_stock_amount( $cart->get_cart_item( $key )['quantity'] ) : 0;
            $target = max( $current, $qty );
            $capped = self::purchasable_qty( $product, $target );
            if ( $capped < $target ) { $target = max( $current, $capped ); $result['reduced'][] = $name; }
            if ( $target <= $current ) { if ( $current > 0 ) $result['restored']++; else $result['skipped'][] = $name; continue; }
            $add = $target - $current;
            if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $parent_id, $add, $variation_id, $attributes ) ) { $result['skipped'][] = $name; continue; }
            // WooCommerce's own checks (variation attributes, stock held in carts, ...) still apply; its raw error
            // notices are replaced by one summary notice.
            $notices = wc_get_notices();
            $ok = $key ? $cart->set_quantity( $key, $target, false ) : $cart->add_to_cart( $parent_id, $add, $variation_id, $attributes );
            if ( $ok ) { $result['restored']++; } else { wc_set_notices( $notices ); $result['skipped'][] = $name; }
        }
        $cart->calculate_totals();
        $result['reduced'] = array_values( array_diff( array_unique( $result['reduced'] ), $result['skipped'] ) );
        return $result;
    }
}
