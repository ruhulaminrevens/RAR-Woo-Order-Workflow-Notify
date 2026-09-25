<?php
/**
 * Plugin Name: RAR Woo Order Workflow & Notify
 * Plugin URI:  https://github.com/ruhulaminrevens/RAR-Woo-Order-Workflow-Notify
 * Description: Professional WooCommerce order workflow (Processing → Confirmed → Shipped → Completed, Cancelled/Returned recovery), bilingual notifications, customer order tracking, and a built-in Documents Center — PDF invoices, packing slips and courier delivery labels with sequential invoice numbers, Bangla-ready rendering, email attachments, bulk printing, invoice register and authenticity QR verification.
 * Version:     2.0.0
 * Author:      Ruhul Amin Revens
 * Author URI:  https://github.com/ruhulaminrevens
 * Text Domain: rar-woo-order-workflow-notify
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Another copy of this plugin (e.g. a differently named folder) is already loaded: never fatal.
if ( defined( 'RAR_WOW_VERSION' ) || class_exists( 'RAR_WOW_Plugin', false ) ) {
    add_action( 'admin_notices', static function() {
        if ( current_user_can( 'activate_plugins' ) ) {
            echo '<div class="notice notice-error"><p><strong>RAR Woo Order Workflow &amp; Notify:</strong> two copies of this plugin are installed. Deactivate and delete the older copy on the Plugins page — settings, invoices and order history are stored in the database and are kept.</p></div>';
        }
    } );
    return;
}

define( 'RAR_WOW_VERSION', '2.0.0' );
define( 'RAR_WOW_DB_VERSION', '2.0.0' );
define( 'RAR_WOW_FILE', __FILE__ );
define( 'RAR_WOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'RAR_WOW_URL', plugin_dir_url( __FILE__ ) );

add_action( 'before_woocommerce_init', static function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

require_once RAR_WOW_DIR . 'includes/class-rar-wow-plugin.php';
require_once RAR_WOW_DIR . 'includes/class-rar-wow-amount-words.php';
require_once RAR_WOW_DIR . 'includes/class-rar-wow-pdf.php';
require_once RAR_WOW_DIR . 'includes/class-rar-wow-documents.php';
require_once RAR_WOW_DIR . 'includes/class-rar-wow-documents-admin.php';
require_once RAR_WOW_DIR . 'includes/class-rar-wow-register.php';

register_activation_hook( __FILE__, static function() {
    RAR_WOW_Documents::install();
} );

add_action( 'plugins_loaded', static function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function() {
            if ( current_user_can( 'activate_plugins' ) ) {
                echo '<div class="notice notice-error"><p><strong>RAR Woo Order Workflow &amp; Notify</strong> requires WooCommerce to be active.</p></div>';
            }
        } );
        return;
    }

    // Plugin updates via "Upload → Replace current" do not fire the activation hook.
    if ( get_option( 'rar_wow_db_version' ) !== RAR_WOW_DB_VERSION ) {
        RAR_WOW_Documents::install();
    }

    RAR_WOW_Plugin::instance();
    RAR_WOW_Documents::instance();

    if ( is_admin() ) {
        RAR_WOW_Documents_Admin::instance();
        RAR_WOW_Register::instance();
    }
}, 20 );
