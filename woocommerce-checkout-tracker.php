<?php
/**
 * Plugin Name: WooCommerce Checkout Tracker
 * Description: Captures initiated checkout activity, checkout fields and cart snapshots, and links converted checkout sessions to WooCommerce orders.
 * Version: 1.7.0
 * Author: Elmates
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.2
 * Text Domain: wc-checkout-tracker
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WCT_VERSION', '1.7.0' );
define( 'WCT_FILE', __FILE__ );
define( 'WCT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCT_URL', plugin_dir_url( __FILE__ ) );

// Asset version = plugin version + file modified time, so browsers and cache plugins pick up every change to a file.
function wct_asset_ver( $path ) {
    $mtime = @filemtime( WCT_DIR . $path );
    return $mtime ? WCT_VERSION . '.' . $mtime : WCT_VERSION;
}

require_once WCT_DIR . 'includes/class-wct-db.php';
require_once WCT_DIR . 'includes/class-wct-tracker.php';
require_once WCT_DIR . 'includes/class-wct-admin.php';
require_once WCT_DIR . 'includes/class-wct-recovery.php';
require_once WCT_DIR . 'includes/class-wct-coupons.php';

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
    WCT_DB::maybe_upgrade();
    WCT_Tracker::init();
    WCT_Admin::init();
    WCT_Recovery::init();
    WCT_Coupons::init();
} );
