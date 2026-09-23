<?php
/**
 * Plugin Name: RAR Woo Order Workflow & Notify
 * Plugin URI:  https://github.com/ruhulaminrevens/RAR-Woo-Order-Workflow-Notify
 * Description: Professional WooCommerce order workflow for Processing → Confirmed → Shipped → Completed, with Cancelled/Returned recovery, compact admin actions, and bilingual customer/admin notifications.
 * Version:     1.5.0
 * Author:      Ruhul Amin Revens
 * Author URI:  https://github.com/ruhulaminrevens
 * Text Domain: rar-woo-order-workflow-notify
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RAR_WOW_VERSION', '1.5.0' );
define( 'RAR_WOW_FILE', __FILE__ );
define( 'RAR_WOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'RAR_WOW_URL', plugin_dir_url( __FILE__ ) );

add_action( 'before_woocommerce_init', static function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

require_once RAR_WOW_DIR . 'includes/class-rar-wow-plugin.php';

add_action( 'plugins_loaded', static function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function() {
            if ( current_user_can( 'activate_plugins' ) ) {
                echo '<div class="notice notice-error"><p><strong>RAR Woo Order Workflow &amp; Notify</strong> requires WooCommerce to be active.</p></div>';
            }
        } );
        return;
    }

    RAR_WOW_Plugin::instance();
}, 20 );
