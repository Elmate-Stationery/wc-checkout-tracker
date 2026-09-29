<?php
/**
 * Plugin Name: WooCommerce Checkout Tracker
 * Description: Captures initiated checkout activity, checkout fields and cart snapshots, and links converted checkout sessions to WooCommerce orders.
 * Version: 1.7.1
 * Author: Elmates
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.2
 * Text Domain: wc-checkout-tracker
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Safe loading: every check below stops only this plugin (with an admin notice and a server error-log line) instead
// of letting a PHP fatal error take down the whole site.

// A second copy of this plugin (e.g. another folder) is already loaded: redeclaring its classes would be fatal.
if ( defined( 'WCT_FILE' ) || class_exists( 'WCT_DB', false ) ) {
    add_action( 'admin_notices', function () {
        if ( current_user_can( 'activate_plugins' ) ) echo '<div class="notice notice-error"><p><strong>WooCommerce Checkout Tracker</strong> is installed more than once. Under Plugins, deactivate and delete the extra copy.</p></div>';
    } );
    return;
}

define( 'WCT_VERSION', '1.7.1' );
define( 'WCT_FILE', __FILE__ );
define( 'WCT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCT_URL', plugin_dir_url( __FILE__ ) );

function wct_boot_error( $message ) {
    error_log( 'WooCommerce Checkout Tracker disabled itself: ' . $message );
    add_action( 'admin_notices', function () use ( $message ) {
        if ( current_user_can( 'activate_plugins' ) ) echo '<div class="notice notice-error"><p><strong>WooCommerce Checkout Tracker</strong> is not running: ' . esc_html( $message ) . '</p></div>';
    } );
}

// Asset version = plugin version + file modified time, so browsers and cache plugins pick up every change to a file.
function wct_asset_ver( $path ) {
    $mtime = @filemtime( WCT_DIR . $path );
    return $mtime ? WCT_VERSION . '.' . $mtime : WCT_VERSION;
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    wct_boot_error( sprintf( 'it needs PHP 7.4 or newer, and this server runs PHP %s. Ask your host to upgrade PHP.', PHP_VERSION ) );
    return;
}
$wct_classes = array( 'class-wct-db' => 'WCT_DB', 'class-wct-tracker' => 'WCT_Tracker', 'class-wct-admin' => 'WCT_Admin', 'class-wct-recovery' => 'WCT_Recovery', 'class-wct-coupons' => 'WCT_Coupons' );
$wct_missing = array();
foreach ( $wct_classes as $wct_file => $wct_class ) {
    if ( ! is_readable( WCT_DIR . 'includes/' . $wct_file . '.php' ) ) $wct_missing[] = 'includes/' . $wct_file . '.php';
    elseif ( class_exists( $wct_class, false ) ) { wct_boot_error( sprintf( 'another plugin already uses the class name %s. Deactivate the conflicting plugin.', $wct_class ) ); return; }
}
if ( $wct_missing ) {
    wct_boot_error( 'files are missing, so the upload was probably incomplete: ' . implode( ', ', $wct_missing ) . '. Upload the complete plugin folder again.' );
    return;
}
try {
    foreach ( array_keys( $wct_classes ) as $wct_file ) require_once WCT_DIR . 'includes/' . $wct_file . '.php';
} catch ( Throwable $e ) {
    wct_boot_error( sprintf( 'a plugin file could not be loaded (%s in %s line %d).', $e->getMessage(), basename( $e->getFile() ), $e->getLine() ) );
    return;
}

register_activation_hook( __FILE__, array( 'WCT_DB', 'install' ) );
register_deactivation_hook( __FILE__, array( 'WCT_Tracker', 'deactivate' ) );

// Orders are only read and written through WC_Order APIs, and the Checkout block is tracked via the Store API.
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCT_FILE, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WCT_FILE, true );
    }
} );

add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            if ( current_user_can( 'activate_plugins' ) ) {
                echo '<div class="notice notice-error"><p><strong>WooCommerce Checkout Tracker</strong> requires WooCommerce to be installed and active.</p></div>';
            }
        } );
        return;
    }
    if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '7.2', '<' ) ) {
        wct_boot_error( sprintf( 'it needs WooCommerce 7.2 or newer, and this site runs WooCommerce %s.', WC_VERSION ) );
        return;
    }
    try {
        WCT_DB::maybe_upgrade();
        WCT_Tracker::init();
        WCT_Admin::init();
        WCT_Recovery::init();
        WCT_Coupons::init();
    } catch ( Throwable $e ) {
        wct_boot_error( sprintf( 'it failed to start (%s in %s line %d). Please send this message to your developer.', $e->getMessage(), basename( $e->getFile() ), $e->getLine() ) );
    }
} );
