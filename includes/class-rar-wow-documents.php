<?php
/**
 * RAR Documents Center — invoices, packing slips and delivery labels.
 *
 * Replaces the "PDF Invoices & Packing Slips for WooCommerce" plugin:
 * - Sequential, gap-audited invoice numbers (prefix/suffix placeholders, padding, yearly reset).
 * - Invoice / Packing slip / Courier delivery label PDFs with correct Bangla shaping.
 * - Email attachments for WooCommerce and RAR workflow emails.
 * - Admin single/bulk printing, customer My Account + guest (order-key) downloads.
 * - Invoice register (accounting snapshot) and public authenticity verification via QR.
 * - Backward compatible with existing `_wcpdf_invoice_*` order data.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RAR_WOW_Documents {

    const OPTION        = 'rar_wow_doc_settings';
    const TABLE         = 'rar_wow_invoices';
    const META_NUMBER   = '_rar_wow_invoice_number';
    const META_RAW      = '_rar_wow_invoice_number_raw';
    const META_DATE     = '_rar_wow_invoice_date';
    const META_NOTES    = '_rar_wow_invoice_notes';
    const META_SERIES   = '_rar_wow_invoice_series';
    const META_PRINTED  = '_rar_wow_printed';

    /** @var RAR_WOW_Documents|null */
    private static $instance = null;

    /** @var array|null */
    private static $settings_cache = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action( 'template_redirect', array( $this, 'handle_frontend_request' ), 1 );
        add_action( 'wp_ajax_rar_wow_document', array( $this, 'handle_admin_request' ) );

        add_filter( 'woocommerce_email_attachments', array( $this, 'attach_to_wc_email' ), 30, 4 );
        add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'my_account_action' ), 20, 2 );
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_details_button' ), 5 );

        // Priority 10: runs before the RAR workflow mailer (priority 20) so emails can link/attach the invoice.
        add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
        add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 20, 2 );

        add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush_settings_cache' ) );
    }

    /* ---------------------------------------------------------------------
     * Install / schema
     * ------------------------------------------------------------------ */

    public static function table() {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  series varchar(40) NOT NULL DEFAULT '',
  number bigint(20) unsigned NOT NULL DEFAULT 0,
  formatted_number varchar(100) NOT NULL DEFAULT '',
  invoice_date datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  status varchar(20) NOT NULL DEFAULT 'issued',
  order_status varchar(40) NOT NULL DEFAULT '',
  customer varchar(200) NOT NULL DEFAULT '',
  phone varchar(60) NOT NULL DEFAULT '',
  city varchar(120) NOT NULL DEFAULT '',
  payment_method varchar(120) NOT NULL DEFAULT '',
  currency varchar(10) NOT NULL DEFAULT '',
  subtotal decimal(18,2) NOT NULL DEFAULT 0,
  discount decimal(18,2) NOT NULL DEFAULT 0,
  shipping decimal(18,2) NOT NULL DEFAULT 0,
  fees decimal(18,2) NOT NULL DEFAULT 0,
  tax decimal(18,2) NOT NULL DEFAULT 0,
  total decimal(18,2) NOT NULL DEFAULT 0,
  refunded decimal(18,2) NOT NULL DEFAULT 0,
  advance decimal(18,2) NOT NULL DEFAULT 0,
  source varchar(20) NOT NULL DEFAULT 'rar',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY series_number (series,number),
  KEY order_id (order_id),
  KEY invoice_date (invoice_date),
  KEY status (status)
) {$charset};";

        dbDelta( $sql );

        if ( false === get_option( self::OPTION, false ) ) {
            add_option( self::OPTION, self::defaults(), '', false );
        }

        update_option( 'rar_wow_db_version', RAR_WOW_DB_VERSION, false );
        self::flush_settings_cache();
    }

    /* ---------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------ */

    public static function defaults() {
        $address = array_filter(
            array(
                get_option( 'woocommerce_store_address' ),
                get_option( 'woocommerce_store_address_2' ),
                trim( get_option( 'woocommerce_store_city' ) . ' ' . get_option( 'woocommerce_store_postcode' ) ),
            )
        );

        return array(
            'enabled'                  => 'yes',
            'invoice_enabled'          => 'yes',
            'packing_enabled'          => 'yes',
            'label_enabled'            => 'yes',

            // Shop identity.
            'shop_name'                => get_bloginfo( 'name' ),
            'shop_address'             => implode( "\n", $address ),
            'shop_phone'               => '',
            'shop_email'               => '',
            'shop_website'             => wp_parse_url( home_url(), PHP_URL_HOST ),
            'tax_id_label'             => 'BIN',
            'tax_id'                   => '',
            'shop_extra'               => '',
            'logo_id'                  => 0,
            'logo_height'              => 16,
            'accent_color'             => '#0b8a62',

            // Layout.
            'paper_size'               => 'A4',
            'label_size'               => '100x150',
            'date_format'              => 'd M Y',
            'invoice_title'            => 'INVOICE',
            'invoice_subtitle'         => 'ইনভয়েস',
            'packing_title'            => 'PACKING SLIP',
            'packing_subtitle'         => 'প্যাকিং স্লিপ',
            'label_title'              => 'DELIVERY LABEL',
            'show_sku'                 => 'yes',
            'show_thumbnails'          => 'no',
            'show_item_meta'           => 'yes',
            'show_email'               => 'yes',
            'show_phone'               => 'yes',
            'show_shipping_address'    => 'always',
            'show_customer_note'       => 'yes',
            'show_payment_method'      => 'yes',
            'show_courier'             => 'yes',
            'show_amount_words'        => 'en',
            'qr_mode'                  => 'verify',
            'show_barcode'             => 'yes',
            'show_signature'           => 'yes',
            'signature_label'          => 'Authorized Signature',
            'show_status_stamp'        => 'yes',
            'show_weight'              => 'yes',
            'terms'                    => 'Goods once sold are subject to the store return policy. Please check your parcel in front of the delivery person.',
            'footer'                   => 'Thank you for shopping with us!',

            // Numbering.
            'number_prefix'            => '',
            'number_suffix'            => '',
            'number_padding'           => 0,
            'reset_yearly'             => 'no',
            'invoice_date_source'      => 'invoice',
            'auto_invoice_statuses'    => array( 'confirmed' ),
            'disable_free'             => 'yes',
            'invoice_blocked_statuses' => array( 'failed' ),

            // Emails.
            'attach_invoice'           => array( 'customer_invoice', 'rar_completed' ),
            'attach_packing'           => array(),
            'email_download_button'    => 'yes',

            // Access.
            'customer_access'          => 'created',
            'customer_statuses'        => array( 'processing', 'confirmed', 'shipped', 'completed' ),
            'guest_access'             => 'yes',
            'output_mode'              => 'inline',
            'mirror_legacy_meta'       => 'yes',
        );
    }

    public static function flush_settings_cache() {
        self::$settings_cache = null;
    }

    public static function settings() {
        if ( null === self::$settings_cache ) {
            $saved = get_option( self::OPTION, array() );
            self::$settings_cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
        }

        return self::$settings_cache;
    }

    public static function setting( $key ) {
        $settings = self::settings();

        return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
    }

    public static function types() {
        return array(
            'invoice'      => array(
                'label'   => 'Invoice',
                'bn'      => 'ইনভয়েস',
                'setting' => 'invoice_enabled',
                'icon'    => 'invoice',
            ),
            'packing-slip' => array(
                'label'   => 'Packing Slip',
                'bn'      => 'প্যাকিং স্লিপ',
                'setting' => 'packing_enabled',
                'icon'    => 'packing',
            ),
            'label'        => array(
                'label'   => 'Delivery Label',
                'bn'      => 'ডেলিভারি লেবেল',
                'setting' => 'label_enabled',
                'icon'    => 'label',
            ),
        );
    }

    public static function type_enabled( $type ) {
        $types = self::types();

        if ( 'yes' !== self::setting( 'enabled' ) || ! isset( $types[ $type ] ) ) {
            return false;
        }

        return 'yes' === self::setting( $types[ $type ]['setting'] );
    }

    /**
     * Email ids that documents can be attached to.
     *
     * @return array<string,string>
     */
    public static function attachable_emails() {
        $list = array(
            'rar_confirmed' => 'RAR — Customer: Order Confirmed',
            'rar_shipped'   => 'RAR — Customer: Order Shipped',
            'rar_completed' => 'RAR — Customer: Order Completed (thank-you)',
            'rar_cancelled' => 'RAR — Customer: Order Cancelled',
            'rar_returned'  => 'RAR — Customer: Order Returned',
            'rar_admin'     => 'RAR — Admin workflow alert',
            'rar_document'  => 'RAR — "Email invoice to customer" action',
        );

        if ( function_exists( 'WC' ) && WC() && WC()->mailer() ) {
            foreach ( WC()->mailer()->get_emails() as $email ) {
                if ( ! is_object( $email ) || empty( $email->id ) ) {
                    continue;
                }

                $list[ $email->id ] = 'WooCommerce — ' . wp_strip_all_tags( $email->get_title() ) . ( $email->is_customer_email() ? ' (customer)' : ' (admin)' );
            }
        }

        return $list;
    }

    /* ---------------------------------------------------------------------
     * Invoice numbering
     * ------------------------------------------------------------------ */

    /**
     * Current invoice data for an order (RAR data first, then legacy WCPDF data).
     *
     * @return array|null number, raw, date (timestamp), notes, series, source.
     */
    public static function get_invoice( WC_Order $order ) {
        $number = (string) $order->get_meta( self::META_NUMBER, true );

        if ( '' !== $number ) {
            return array(
                'number' => $number,
                'raw'    => absint( $order->get_meta( self::META_RAW, true ) ),
                'date'   => absint( $order->get_meta( self::META_DATE, true ) ),
                'notes'  => (string) $order->get_meta( self::META_NOTES, true ),
                'series' => (string) $order->get_meta( self::META_SERIES, true ),
                'source' => 'rar',
            );
        }

        $legacy = self::get_legacy_invoice( $order );

        return $legacy ? $legacy : null;
    }

    /**
     * Invoice created by "PDF Invoices & Packing Slips for WooCommerce".
     */
    public static function get_legacy_invoice( WC_Order $order ) {
        $formatted = (string) $order->get_meta( '_wcpdf_invoice_number', true );

        if ( '' === $formatted ) {
            return null;
        }

        $data = $order->get_meta( '_wcpdf_invoice_number_data', true );
        $raw  = ( is_array( $data ) && isset( $data['number'] ) ) ? absint( $data['number'] ) : absint( preg_replace( '/\D+/', '', $formatted ) );

        if ( is_array( $data ) && ! empty( $data['formatted_number'] ) ) {
            $formatted = (string) $data['formatted_number'];
        }

        $date = $order->get_meta( '_wcpdf_invoice_date', true );

        if ( ! $date ) {
            $created = $order->get_date_created();
            $date    = $created ? $created->getTimestamp() : time();
        }

        return array(
            'number' => $formatted,
            'raw'    => $raw,
            'date'   => absint( $date ),
            'notes'  => (string) $order->get_meta( '_wcpdf_invoice_notes', true ),
            'series' => 'wcpdf',
            'source' => 'legacy',
        );
    }

    public static function series_for( $timestamp ) {
        if ( 'yes' === self::setting( 'reset_yearly' ) ) {
            return 'invoice-' . wp_date( 'Y', $timestamp );
        }

        return 'invoice';
    }

    /**
     * Next-number floor configured by admin (e.g. continue after old plugin).
     */
    public static function get_floor( $series ) {
        $floors = get_option( 'rar_wow_number_floor', array() );

        return ( is_array( $floors ) && isset( $floors[ $series ] ) ) ? absint( $floors[ $series ] ) : 1;
    }

    public static function set_floor( $series, $next ) {
        $floors = get_option( 'rar_wow_number_floor', array() );
        $floors = is_array( $floors ) ? $floors : array();

        $floors[ sanitize_key( $series ) ] = max( 1, absint( $next ) );
        update_option( 'rar_wow_number_floor', $floors, false );
    }

    /**
     * Highest raw number issued by RAR (any invoice* series), optionally only within a calendar year.
     */
    public static function max_number_any( $year = 0 ) {
        global $wpdb;

        $table = self::table();

        if ( $year ) {
            return absint( $wpdb->get_var( $wpdb->prepare( "SELECT MAX(number) FROM {$table} WHERE series LIKE %s AND invoice_date BETWEEN %s AND %s", 'invoice%', $year . '-01-01 00:00:00', $year . '-12-31 23:59:59' ) ) ); // phpcs:ignore
        }

        return absint( $wpdb->get_var( "SELECT MAX(number) FROM {$table} WHERE series LIKE 'invoice%'" ) ); // phpcs:ignore
    }

    /**
     * Called when numbering settings change: make sure the newly active series can never
     * re-issue a number already used this year (yearly reset switched on mid-year) or ever
     * (yearly reset switched off).
     */
    public static function protect_series_switch( $old_reset, $new_reset ) {
        if ( $old_reset === $new_reset ) {
            return;
        }

        $year   = (int) wp_date( 'Y' );
        $series = 'yes' === $new_reset ? 'invoice-' . $year : 'invoice';
        $max    = 'yes' === $new_reset ? self::max_number_any( $year ) : self::max_number_any();

        if ( $max + 1 > self::get_floor( $series ) ) {
            self::set_floor( $series, $max + 1 );
        }
    }

    public static function has_year_placeholder( $prefix, $suffix ) {
        return (bool) preg_match( '/\[(invoice_year|year|yy|order_year)\]|\[(invoice|order)_date="[^"]*[yY][^"]*"\]/', $prefix . ' ' . $suffix );
    }

    public static function max_number( $series ) {
        global $wpdb;

        $table = self::table();

        return absint( $wpdb->get_var( $wpdb->prepare( "SELECT MAX(number) FROM {$table} WHERE series = %s", $series ) ) ); // phpcs:ignore
    }

    public static function next_number( $series = null ) {
        if ( null === $series ) {
            $series = self::series_for( time() );
        }

        return max( self::max_number( $series ) + 1, self::get_floor( $series ) );
    }

    /**
     * Apply prefix/suffix placeholders and padding.
     */
    public static function format_number( $raw, WC_Order $order, $invoice_ts ) {
        $settings = self::settings();
        $created  = $order->get_date_created();
        $order_ts = $created ? $created->getTimestamp() : $invoice_ts;

        $padding = min( 12, absint( $settings['number_padding'] ) );
        $number  = $padding ? str_pad( (string) $raw, $padding, '0', STR_PAD_LEFT ) : (string) $raw;

        $replace = function ( $text ) use ( $order, $order_ts, $invoice_ts ) {
            $text = (string) $text;

            if ( '' === $text ) {
                return '';
            }

            $text = preg_replace_callback(
                '/\[(invoice|order)_date="([^"]{1,20})"\]/',
                static function ( $m ) use ( $order_ts, $invoice_ts ) {
                    return wp_date( $m[2], 'order' === $m[1] ? $order_ts : $invoice_ts );
                },
                $text
            );

            $map = array(
                '[invoice_year]'  => wp_date( 'Y', $invoice_ts ),
                '[invoice_month]' => wp_date( 'm', $invoice_ts ),
                '[invoice_day]'   => wp_date( 'd', $invoice_ts ),
                '[year]'          => wp_date( 'Y', $invoice_ts ),
                '[yy]'            => wp_date( 'y', $invoice_ts ),
                '[month]'         => wp_date( 'm', $invoice_ts ),
                '[day]'           => wp_date( 'd', $invoice_ts ),
                '[order_year]'    => wp_date( 'Y', $order_ts ),
                '[order_month]'   => wp_date( 'm', $order_ts ),
                '[order_day]'     => wp_date( 'd', $order_ts ),
                '[order_number]'  => $order->get_order_number(),
            );

            return strtr( $text, $map );
        };

        $formatted = $replace( $settings['number_prefix'] ) . $number . $replace( $settings['number_suffix'] );

        return (string) apply_filters( 'rar_wow_formatted_invoice_number', $formatted, $raw, $order, $invoice_ts );
    }

    /**
     * Whether an invoice may be created for this order.
     *
     * @return true|WP_Error
     */
    public static function invoice_allowed( WC_Order $order, $context = 'auto' ) {
        if ( ! self::type_enabled( 'invoice' ) ) {
            return new WP_Error( 'rar_wow_disabled', 'Invoices are disabled in RAR Documents settings.' );
        }

        if ( 'yes' === self::setting( 'disable_free' ) && (float) $order->get_total() <= 0 ) {
            return new WP_Error( 'rar_wow_free', 'Invoices are disabled for free (zero-total) orders.' );
        }

        $blocked = (array) self::setting( 'invoice_blocked_statuses' );

        if ( 'admin' !== $context && in_array( $order->get_status(), $blocked, true ) ) {
            return new WP_Error( 'rar_wow_status', 'Invoices are not issued for orders with status: ' . wc_get_order_status_name( $order->get_status() ) . '.' );
        }

        return (bool) apply_filters( 'rar_wow_invoice_allowed', true, $order, $context )
            ? true
            : new WP_Error( 'rar_wow_filtered', 'Invoice creation blocked by a filter.' );
    }

    /**
     * Portable mutex using the options table's unique key.
     */
    /**
     * Atomic mutex: INSERT IGNORE on the options table's unique option_name.
     * Bypasses the options API/object cache on purpose (stale caches break locks).
     */
    private static function lock( $name, $wait = 8 ) {
        global $wpdb;

        $key   = 'rar_wow_lock_' . $name;
        $start = microtime( true );

        do {
            $inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                    $key,
                    (string) time()
                )
            );

            if ( 1 === (int) $inserted ) {
                return true;
            }

            $held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) ); // phpcs:ignore

            if ( $held && ( time() - $held ) > 45 ) {
                // Stale lock left by a crashed request: remove only if still the same stale value.
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, (string) $held ) ); // phpcs:ignore
                continue;
            }

            usleep( wp_rand( 80000, 160000 ) );
        } while ( ( microtime( true ) - $start ) < $wait );

        return false;
    }

    private static function unlock( $name ) {
        global $wpdb;

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'rar_wow_lock_' . $name ) ); // phpcs:ignore
    }

    /**
     * Authoritative, cache-free lookup of the order's issued register row.
     */
    public static function issued_row( $order_id ) {
        global $wpdb;

        $table = self::table();

        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d AND status = 'issued' ORDER BY id DESC LIMIT 1", absint( $order_id ) ), ARRAY_A ); // phpcs:ignore
    }

    /**
     * Return the order's invoice, creating a new sequential number when needed.
     *
     * @param WC_Order $order   Order.
     * @param string   $trigger Human readable trigger for the audit note.
     * @param string   $context auto|admin|customer|email.
     * @return array|WP_Error
     */
    public static function ensure_invoice( WC_Order $order, $trigger = 'manual', $context = 'auto' ) {
        $existing = self::get_invoice( $order );

        if ( $existing ) {
            if ( 'legacy' === $existing['source'] ) {
                self::adopt_legacy( $order, $existing );
                $existing['source'] = 'rar';
            }

            return $existing;
        }

        $allowed = self::invoice_allowed( $order, $context );

        if ( is_wp_error( $allowed ) ) {
            return $allowed;
        }

        $lock_name = 'inv_' . $order->get_id();

        if ( ! self::lock( $lock_name ) ) {
            return new WP_Error( 'rar_wow_locked', 'Invoice number is being created by another request. Please retry.' );
        }

        try {
            // Re-check inside the lock against the register (no object/meta caches involved).
            $row = self::issued_row( $order->get_id() );

            if ( $row ) {
                $ts = strtotime( get_gmt_from_date( $row['invoice_date'] ) . ' UTC' );

                // Another request just issued it: hydrate the caller's object (in memory only).
                $order->update_meta_data( self::META_NUMBER, $row['formatted_number'] );
                $order->update_meta_data( self::META_RAW, absint( $row['number'] ) );
                $order->update_meta_data( self::META_DATE, $ts );
                $order->update_meta_data( self::META_SERIES, $row['series'] );

                self::unlock( $lock_name );

                return self::get_invoice( $order );
            }

            $invoice_ts = time();

            if ( 'order' === self::setting( 'invoice_date_source' ) && $order->get_date_created() ) {
                $invoice_ts = $order->get_date_created()->getTimestamp();
            }

            $series = self::series_for( $invoice_ts );
            $result = self::insert_register_row( $order, $series, $invoice_ts, 'rar' );

            if ( is_wp_error( $result ) ) {
                self::unlock( $lock_name );
                return $result;
            }

            $order->update_meta_data( self::META_NUMBER, $result['formatted'] );
            $order->update_meta_data( self::META_RAW, $result['number'] );
            $order->update_meta_data( self::META_DATE, $invoice_ts );
            $order->update_meta_data( self::META_SERIES, $series );
            self::mirror_legacy( $order, $result['formatted'], $result['number'], $invoice_ts );
            $order->save_meta_data();

            $order->add_order_note(
                sprintf(
                    'RAR Documents: Invoice %1$s created (%2$s)%3$s.',
                    $result['formatted'],
                    $trigger,
                    is_user_logged_in() && current_user_can( 'edit_shop_orders' ) ? ' by ' . wp_get_current_user()->display_name : ''
                )
            );

            do_action( 'rar_wow_invoice_created', $order, $result );
        } catch ( Throwable $e ) {
            self::unlock( $lock_name );
            return new WP_Error( 'rar_wow_exception', $e->getMessage() );
        }

        self::unlock( $lock_name );

        return self::get_invoice( $order );
    }

    /**
     * Insert register row with a collision-safe sequential number.
     *
     * @return array|WP_Error number, formatted
     */
    private static function insert_register_row( WC_Order $order, $series, $invoice_ts, $source, $forced_number = 0, $forced_formatted = '' ) {
        global $wpdb;

        $table = self::table();

        for ( $attempt = 0; $attempt < 8; $attempt++ ) {
            $number    = $forced_number ? absint( $forced_number ) : self::next_number( $series );
            $formatted = $forced_formatted ? $forced_formatted : self::format_number( $number, $order, $invoice_ts );
            $row       = self::snapshot( $order );

            $row['order_id']         = $order->get_id();
            $row['series']           = $series;
            $row['number']           = $number;
            $row['formatted_number'] = $formatted;
            $row['invoice_date']     = wp_date( 'Y-m-d H:i:s', $invoice_ts );
            $row['status']           = 'issued';
            $row['source']           = $source;
            $row['created_by']       = get_current_user_id();
            $row['created_at']       = current_time( 'mysql' );

            $suppress = $wpdb->suppress_errors( true );
            $ok       = $wpdb->insert( $table, $row );
            $wpdb->suppress_errors( $suppress );

            if ( $ok ) {
                return array(
                    'number'    => $number,
                    'formatted' => $formatted,
                    'id'        => (int) $wpdb->insert_id,
                );
            }

            if ( $forced_number ) {
                // Imported number already exists in this series: keep the register clean.
                return new WP_Error( 'rar_wow_duplicate', sprintf( 'Invoice number %s already exists in series %s.', $formatted, $series ) );
            }

            usleep( wp_rand( 20000, 120000 ) ); // Collision with a parallel request — retry with the next number.
        }

        return new WP_Error( 'rar_wow_number', 'Could not reserve a unique invoice number. ' . $wpdb->last_error );
    }

    /**
     * Accounting snapshot of the order for the register.
     */
    public static function snapshot( WC_Order $order ) {
        $name = trim( $order->get_formatted_billing_full_name() );

        if ( '' === $name ) {
            $name = trim( $order->get_formatted_shipping_full_name() );
        }

        $fees = 0.0;

        foreach ( $order->get_fees() as $fee ) {
            $fees += (float) $fee->get_total();
        }

        $pay = self::payment_position( $order );

        return array(
            'order_status'   => $order->get_status(),
            'customer'       => mb_substr( $name, 0, 200 ),
            'phone'          => mb_substr( (string) $order->get_billing_phone(), 0, 60 ),
            'city'           => mb_substr( trim( $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city() ), 0, 120 ),
            'payment_method' => mb_substr( (string) $order->get_payment_method_title(), 0, 120 ),
            'currency'       => $order->get_currency(),
            'subtotal'       => wc_format_decimal( $order->get_subtotal(), 2 ),
            'discount'       => wc_format_decimal( $order->get_discount_total(), 2 ),
            'shipping'       => wc_format_decimal( $order->get_shipping_total(), 2 ),
            'fees'           => wc_format_decimal( $fees, 2 ),
            'tax'            => wc_format_decimal( $order->get_total_tax(), 2 ),
            'total'          => wc_format_decimal( $order->get_total(), 2 ),
            'refunded'       => wc_format_decimal( $order->get_total_refunded(), 2 ),
            'advance'        => wc_format_decimal( $pay['advance'], 2 ),
            'updated_at'     => current_time( 'mysql' ),
        );
    }

    public static function sync_register( WC_Order $order ) {
        global $wpdb;

        $table = self::table();

        $wpdb->update( $table, self::snapshot( $order ), array( 'order_id' => $order->get_id(), 'status' => 'issued' ) ); // phpcs:ignore
    }

    private static function mirror_legacy( WC_Order $order, $formatted, $raw, $invoice_ts ) {
        if ( 'yes' !== self::setting( 'mirror_legacy_meta' ) ) {
            return;
        }

        // Keeps exports, courier and staff tools that read the old plugin's meta working.
        $order->update_meta_data( '_wcpdf_invoice_number', $formatted );
        $order->update_meta_data(
            '_wcpdf_invoice_number_data',
            array(
                'number'           => $raw,
                'formatted_number' => $formatted,
                'prefix'           => '',
                'suffix'           => '',
                'padding'          => '',
                'document_type'    => 'invoice',
                'order_id'         => $order->get_id(),
            )
        );
        $order->update_meta_data( '_wcpdf_formatted_invoice_number', $formatted );
        $order->update_meta_data( '_wcpdf_invoice_date', $invoice_ts );
        $order->update_meta_data( '_wcpdf_invoice_date_formatted', wp_date( 'Y-m-d H:i:s', $invoice_ts ) );
    }

    /**
     * Copy a legacy (WCPDF) invoice into RAR meta + register, keeping the same number.
     *
     * @return true|WP_Error
     */
    public static function adopt_legacy( WC_Order $order, $legacy = null ) {
        $legacy = $legacy ? $legacy : self::get_legacy_invoice( $order );

        if ( ! $legacy ) {
            return new WP_Error( 'rar_wow_no_legacy', 'No legacy invoice on this order.' );
        }

        global $wpdb;

        $table  = self::table();
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d AND status = 'issued' LIMIT 1", $order->get_id() ) ); // phpcs:ignore

        if ( ! $exists ) {
            $series = 'wcpdf';

            if ( $legacy['date'] && 'yes' === self::legacy_reset_yearly() ) {
                $series = 'wcpdf-' . wp_date( 'Y', $legacy['date'] );
            }

            $result = self::insert_register_row( $order, $series, $legacy['date'], 'wcpdf', max( 1, $legacy['raw'] ), $legacy['number'] );

            if ( is_wp_error( $result ) ) {
                // Duplicate raw number (e.g. a manual edit in the old plugin): register under a unique series.
                $result = self::insert_register_row( $order, 'wcpdf-dup-' . $order->get_id(), $legacy['date'], 'wcpdf', max( 1, $legacy['raw'] ), $legacy['number'] );
            }
        }

        $order->update_meta_data( self::META_NUMBER, $legacy['number'] );
        $order->update_meta_data( self::META_RAW, $legacy['raw'] );
        $order->update_meta_data( self::META_DATE, $legacy['date'] );
        $order->update_meta_data( self::META_SERIES, 'wcpdf' );

        if ( $legacy['notes'] ) {
            $order->update_meta_data( self::META_NOTES, $legacy['notes'] );
        }

        $order->save_meta_data();

        return true;
    }

    public static function legacy_reset_yearly() {
        $settings = get_option( 'wpo_wcpdf_documents_settings_invoice', array() );

        return ( is_array( $settings ) && ! empty( $settings['reset_number_yearly'] ) ) ? 'yes' : 'no';
    }

    /**
     * Void the invoice: the number stays reserved in the register for audit.
     */
    public static function void_invoice( WC_Order $order, $reason = '' ) {
        global $wpdb;

        $invoice = self::get_invoice( $order );

        if ( ! $invoice ) {
            return false;
        }

        if ( 'legacy' === $invoice['source'] ) {
            self::adopt_legacy( $order, $invoice ); // Register it first so the void keeps an audit trail.
        }

        $table = self::table();

        $wpdb->update( // phpcs:ignore
            $table,
            array(
                'status'     => 'void',
                'updated_at' => current_time( 'mysql' ),
            ),
            array(
                'order_id' => $order->get_id(),
                'status'   => 'issued',
            )
        );

        foreach ( array( self::META_NUMBER, self::META_RAW, self::META_DATE, self::META_SERIES, self::META_NOTES ) as $key ) {
            $order->delete_meta_data( $key );
        }

        foreach ( array( '_wcpdf_invoice_number', '_wcpdf_invoice_number_data', '_wcpdf_invoice_date', '_wcpdf_invoice_date_formatted', '_wcpdf_formatted_invoice_number', '_wcpdf_invoice_notes' ) as $key ) {
            $order->delete_meta_data( $key );
        }

        $order->save_meta_data();
        $order->add_order_note(
            sprintf(
                'RAR Documents: Invoice %1$s voided by %2$s. %3$s The number stays reserved in the Invoice Register.',
                $invoice['number'],
                wp_get_current_user()->display_name,
                $reason ? 'Reason: ' . $reason . '.' : ''
            )
        );

        do_action( 'rar_wow_invoice_voided', $order, $invoice );

        return true;
    }

    /**
     * Manual edit of number/date/notes from the order screen.
     */
    public static function update_invoice_fields( WC_Order $order, $number, $date_string, $notes ) {
        $invoice = self::get_invoice( $order );

        if ( ! $invoice ) {
            return;
        }

        if ( 'legacy' === $invoice['source'] ) {
            self::adopt_legacy( $order, $invoice );
        }

        $changes = array();
        $number  = trim( sanitize_text_field( $number ) );

        if ( '' !== $number && $number !== $invoice['number'] ) {
            global $wpdb;

            $table = self::table();
            $taken = $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$table} WHERE formatted_number = %s AND order_id <> %d LIMIT 1", $number, $order->get_id() ) ); // phpcs:ignore

            if ( $taken ) {
                $order->add_order_note( sprintf( 'RAR Documents: invoice number change to %1$s refused — already used by order #%2$d.', $number, $taken ) );
                set_transient( 'rar_wow_notice_' . get_current_user_id(), array( 'type' => 'error', 'message' => sprintf( 'Invoice number %1$s is already used by order #%2$d. The number was not changed.', $number, $taken ) ), 60 );
                $number = '';
            } else {
                $order->update_meta_data( self::META_NUMBER, $number );
                $changes[] = 'number ' . $invoice['number'] . ' → ' . $number;
            }
        }

        $ts = 0;

        if ( $date_string ) {
            try {
                $dt = new DateTime( $date_string, wp_timezone() );
                $ts = $dt->getTimestamp();
            } catch ( Exception $e ) {
                $ts = 0;
            }
        }

        // The date field has minute precision: ignore second-level differences on every order save.
        if ( $ts && wp_date( 'Y-m-d H:i', $ts ) !== wp_date( 'Y-m-d H:i', (int) $invoice['date'] ) ) {
            $order->update_meta_data( self::META_DATE, $ts );
            $changes[] = 'date → ' . wp_date( 'Y-m-d H:i', $ts );
        }

        $notes = sanitize_textarea_field( $notes );

        if ( $notes !== $invoice['notes'] ) {
            $order->update_meta_data( self::META_NOTES, $notes );
            $changes[] = 'notes updated';
        }

        if ( ! $changes ) {
            return;
        }

        $final_number = '' !== $number ? $number : $invoice['number'];
        $final_ts     = ( $ts && wp_date( 'Y-m-d H:i', $ts ) !== wp_date( 'Y-m-d H:i', (int) $invoice['date'] ) ) ? $ts : (int) $invoice['date'];

        self::mirror_legacy( $order, $final_number, $invoice['raw'], $final_ts );
        $order->save_meta_data();

        global $wpdb;
        $table = self::table();
        $wpdb->update( // phpcs:ignore
            $table,
            array(
                'formatted_number' => $final_number,
                'invoice_date'     => wp_date( 'Y-m-d H:i:s', $final_ts ),
                'updated_at'       => current_time( 'mysql' ),
            ),
            array(
                'order_id' => $order->get_id(),
                'status'   => 'issued',
            )
        );

        $order->add_order_note( 'RAR Documents: Invoice edited by ' . wp_get_current_user()->display_name . ' — ' . implode( '; ', $changes ) . '.' );
    }

    /* ---------------------------------------------------------------------
     * Workflow hooks
     * ------------------------------------------------------------------ */

    public function on_status_changed( $order_id, $old_status, $new_status, $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }

        if ( ! $order || 'yes' !== self::setting( 'enabled' ) ) {
            return;
        }

        $auto = (array) self::setting( 'auto_invoice_statuses' );

        if ( in_array( $new_status, $auto, true ) && ! self::get_invoice( $order ) ) {
            $result = self::ensure_invoice( $order, 'automatic on ' . wc_get_order_status_name( $new_status ), 'auto' );

            if ( is_wp_error( $result ) && ! in_array( $result->get_error_code(), array( 'rar_wow_free', 'rar_wow_disabled', 'rar_wow_status' ), true ) ) {
                wc_get_logger()->warning( 'Automatic invoice failed: ' . $result->get_error_message(), array( 'source' => 'rar-wow', 'order_id' => $order_id ) );
            }

            return;
        }

        if ( $order->get_meta( self::META_NUMBER, true ) ) {
            self::sync_register( $order );
        }
    }

    public function on_order_refunded( $order_id, $refund_id ) {
        $order = wc_get_order( $order_id );

        if ( $order && $order->get_meta( self::META_NUMBER, true ) ) {
            self::sync_register( $order );
        }
    }

    /* ---------------------------------------------------------------------
     * Payment position (COD / advance / paid)
     * ------------------------------------------------------------------ */

    /**
     * @return array{advance:float,paid:float,collect:float,is_paid:bool,label:string,advance_pending:bool}
     */
    public static function payment_position( WC_Order $order ) {
        $total    = (float) $order->get_total() - (float) $order->get_total_refunded();
        $wap      = sanitize_key( (string) $order->get_meta( '_rar_wap_status', true ) );
        $required = (float) $order->get_meta( '_rar_wap_required_amount', true );
        $advance  = ( 'verified' === $wap && $required > 0 ) ? min( $required, max( 0, $total ) ) : 0.0;
        $pending  = in_array( $wap, array( 'submitted', 'unverified' ), true );
        $method   = (string) $order->get_payment_method();

        if ( 'cod' !== $method && $order->get_date_paid() && 'verified' !== $wap ) {
            $paid = max( 0, $total );
        } else {
            $paid = $advance;
        }

        $collect = max( 0, round( $total - $paid, 2 ) );

        if ( $collect <= 0 ) {
            $label = 'PAID';
        } elseif ( 'cod' === $method || $advance > 0 ) {
            $label = 'CASH ON DELIVERY';
        } else {
            $label = 'PAYMENT DUE';
        }

        return array(
            'advance'         => (float) $advance,
            'paid'            => (float) $paid,
            'collect'         => (float) $collect,
            'is_paid'         => $collect <= 0,
            'label'           => $label,
            'advance_pending' => $pending,
        );
    }

    /* ---------------------------------------------------------------------
     * Document data
     * ------------------------------------------------------------------ */

    public static function money( $amount, WC_Order $order ) {
        $html = wc_price( $amount, array( 'currency' => $order->get_currency() ) );

        return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
    }

    private static function plain( $html ) {
        $text = preg_replace( '#<br\s*/?>#i', "\n", (string) $html );

        return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) );
    }

    private static function lines_html( $text ) {
        $lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $text ) ), 'strlen' );

        return implode( '<br>', array_map( 'esc_html', $lines ) );
    }

    /**
     * Local path for an attachment size (fast, no HTTP round-trip in the PDF engine).
     */
    public static function attachment_path( $attachment_id, $size = 'thumbnail' ) {
        $attachment_id = absint( $attachment_id );

        if ( ! $attachment_id ) {
            return '';
        }

        $file = get_attached_file( $attachment_id );

        if ( ! $file ) {
            return '';
        }

        if ( 'full' !== $size ) {
            $info = image_get_intermediate_size( $attachment_id, $size );

            if ( $info && ! empty( $info['path'] ) ) {
                $upload    = wp_upload_dir( null, false );
                $candidate = trailingslashit( $upload['basedir'] ) . $info['path'];

                if ( file_exists( $candidate ) ) {
                    return $candidate;
                }
            }
        }

        return file_exists( $file ) ? $file : '';
    }

    public static function verify_token( $order_id, $number ) {
        return substr( hash_hmac( 'sha256', absint( $order_id ) . '|' . $number, wp_salt( 'nonce' ) . 'rar-wow-verify' ), 0, 20 );
    }

    public static function verify_url( WC_Order $order, $number ) {
        return add_query_arg(
            array(
                'rar-wow-verify' => rawurlencode( $number ),
                'o'              => $order->get_id(),
                't'              => self::verify_token( $order->get_id(), $number ),
            ),
            home_url( '/' )
        );
    }

    public static function track_url( WC_Order $order ) {
        return $order->get_customer_id() ? $order->get_view_order_url() : $order->get_checkout_order_received_url();
    }

    /**
     * QR destination. Packing slips and labels travel with the parcel (courier staff can scan
     * them), so they must never carry a URL containing the order key.
     */
    public static function qr_target( WC_Order $order, $type, $number, $mode ) {
        if ( 'none' === $mode ) {
            return '';
        }

        if ( 'invoice' === $type ) {
            if ( 'verify' === $mode && $number ) {
                return self::verify_url( $order, $number );
            }

            return self::track_url( $order ); // The invoice itself goes to the customer.
        }

        if ( 'verify' === $mode && $number ) {
            return self::verify_url( $order, $number );
        }

        // Login-protected page only; guests get no QR on parcel documents.
        return $order->get_customer_id() ? $order->get_view_order_url() : ( $number ? self::verify_url( $order, $number ) : '' );
    }

    /**
     * Build the full, escaped-at-render data array used by all templates.
     */
    public static function build_data( WC_Order $order, $type ) {
        $s        = self::settings();
        $types    = self::types();
        $plugin   = class_exists( 'RAR_WOW_Plugin' ) ? RAR_WOW_Plugin::instance() : null;
        $invoice  = self::get_invoice( $order );
        $created  = $order->get_date_created();
        $pay      = self::payment_position( $order );
        $incl_tax = 'incl' === get_option( 'woocommerce_tax_display_cart' );
        $w_unit   = get_option( 'woocommerce_weight_unit', 'kg' );

        $items       = array();
        $total_qty   = 0;
        $total_weight = 0.0;
        $index       = 0;

        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            /** @var WC_Order_Item_Product $item */
            $product  = $item->get_product();
            $qty      = (float) $item->get_quantity();
            $refunded = abs( (float) $order->get_qty_refunded_for_item( $item_id ) );
            $weight   = ( $product && $product->get_weight() ) ? (float) $product->get_weight() * $qty : 0.0;
            $meta     = '';

            if ( 'yes' === $s['show_item_meta'] ) {
                $meta = self::plain(
                    wc_display_item_meta(
                        $item,
                        array(
                            'echo'      => false,
                            'before'    => '',
                            'after'     => '',
                            'separator' => ' | ',
                            'autop'     => false,
                        )
                    )
                );
            }

            $thumb = '';

            if ( 'yes' === $s['show_thumbnails'] && $product ) {
                $thumb = self::attachment_path( $product->get_image_id(), 'thumbnail' );
            }

            $total_qty    += $qty - $refunded;
            $total_weight += $weight;

            $items[] = array(
                'index'    => ++$index,
                'name'     => $item->get_name(),
                'sku'      => $product ? (string) $product->get_sku() : '',
                'meta'     => $meta,
                'qty'      => wc_stock_amount( $qty ),
                'refunded' => $refunded ? wc_stock_amount( $refunded ) : 0,
                'unit'     => self::money( $order->get_item_subtotal( $item, $incl_tax, true ), $order ),
                'line'     => self::money( $order->get_line_subtotal( $item, $incl_tax, true ), $order ),
                'weight'   => $weight ? wc_format_decimal( $weight, 2 ) . ' ' . $w_unit : '',
                'thumb'    => $thumb,
            );
        }

        $totals = array();

        foreach ( $order->get_order_item_totals( $incl_tax ? 'incl' : 'excl' ) as $key => $row ) {
            if ( 'payment_method' === $key ) {
                continue;
            }

            $totals[] = array(
                'key'   => $key,
                'label' => rtrim( self::plain( $row['label'] ), ':' ),
                'value' => self::plain( $row['value'] ),
            );
        }

        // Order: charges → Total → refunds (then the template adds Net total).
        $rank = static function ( $row ) {
            if ( 0 === strpos( $row['key'], 'refund_' ) ) {
                return 2;
            }

            return 'order_total' === $row['key'] ? 1 : 0;
        };
        uksort(
            $totals,
            static function ( $a, $b ) use ( $totals, $rank ) {
                $diff = $rank( $totals[ $a ] ) - $rank( $totals[ $b ] );
                return 0 !== $diff ? $diff : $a - $b;
            }
        );
        $totals = array_values( $totals );

        $billing  = $order->get_formatted_billing_address();
        $shipping = $order->get_formatted_shipping_address();

        $show_ship = $s['show_shipping_address'];
        $has_ship  = $shipping && ( 'always' === $show_ship || ( 'when_different' === $show_ship && self::plain( $shipping ) !== self::plain( $billing ) ) );

        $logo = self::attachment_path( $s['logo_id'], 'medium' );

        if ( ! $logo ) {
            $logo = self::attachment_path( $s['logo_id'], 'full' );
        }

        $number = $invoice ? $invoice['number'] : '';
        $qr     = self::qr_target( $order, $type, $number, $s['qr_mode'] );

        // Bundled QR data covers versions 1–15 (≈400 bytes); real URLs are ~60–130 chars.
        if ( strlen( $qr ) > 300 ) {
            $qr = '';
        }

        $words = '';

        if ( 'invoice' === $type && 'no' !== $s['show_amount_words'] ) {
            $amount = (float) $order->get_total() - (float) $order->get_total_refunded();
            $parts  = array();

            if ( in_array( $s['show_amount_words'], array( 'en', 'both' ), true ) ) {
                $parts[] = RAR_WOW_Amount_Words::english( $amount, $order->get_currency() );
            }

            if ( in_array( $s['show_amount_words'], array( 'bn', 'both' ), true ) && 'BDT' === $order->get_currency() ) {
                $parts[] = RAR_WOW_Amount_Words::bangla( $amount );
            }

            $words = implode( "\n", $parts );
        }

        $shipping_methods = array();

        foreach ( $order->get_shipping_methods() as $method ) {
            $shipping_methods[] = $method->get_name();
        }

        $billing_name = trim( $order->get_formatted_billing_full_name() );
        $ship_name    = trim( $order->get_formatted_shipping_full_name() );

        // Compact one-paragraph address without the recipient name (labels).
        $addr_lines = array_filter( array_map( 'trim', explode( "\n", self::plain( $shipping ? $shipping : $billing ) ) ), 'strlen' );
        $addr_lines = array_values(
            array_filter(
                $addr_lines,
                static function ( $line ) use ( $ship_name, $billing_name ) {
                    return $line !== $ship_name && $line !== $billing_name;
                }
            )
        );
        $addr_lines = array_values( array_unique( $addr_lines ) );

        $data = array(
            'type'            => $type,
            'title'           => 'invoice' === $type ? $s['invoice_title'] : ( 'packing-slip' === $type ? $s['packing_title'] : $s['label_title'] ),
            'subtitle'        => 'invoice' === $type ? $s['invoice_subtitle'] : ( 'packing-slip' === $type ? $s['packing_subtitle'] : '' ),
            'type_label'      => $types[ $type ]['label'],
            'settings'        => $s,
            'order'           => $order,
            'shop'            => array(
                'name'     => $s['shop_name'],
                'address'  => self::lines_html( $s['shop_address'] ),
                'address_plain' => trim( preg_replace( '/\s*\n\s*/', ', ', (string) $s['shop_address'] ) ),
                'phone'    => $s['shop_phone'],
                'email'    => $s['shop_email'],
                'website'  => $s['shop_website'],
                'tax'      => $s['tax_id'] ? trim( $s['tax_id_label'] . ': ' . $s['tax_id'] ) : '',
                'extra'    => self::lines_html( $s['shop_extra'] ),
                'logo'     => $logo,
            ),
            'invoice_number'  => $number,
            'invoice_date'    => ( $invoice && $invoice['date'] ) ? wp_date( $s['date_format'], $invoice['date'] ) : '',
            'invoice_notes'   => $invoice ? self::lines_html( $invoice['notes'] ) : '',
            'order_number'    => $order->get_order_number(),
            'order_date'      => $created ? wp_date( $s['date_format'], $created->getTimestamp() ) : '',
            'order_status'    => wc_get_order_status_name( $order->get_status() ),
            'payment_method'  => $order->get_payment_method_title(),
            'shipping_method' => implode( ', ', $shipping_methods ),
            'courier'         => $plugin ? $plugin->courier_label( $order ) : '',
            'tracking'        => $plugin ? $plugin->tracking_value( $order ) : '',
            'eta'             => $plugin ? $plugin->courier_eta( $order ) : '',
            'billing_html'    => self::lines_html( self::plain( $billing ) ),
            'shipping_html'   => $has_ship ? self::lines_html( self::plain( $shipping ) ) : '',
            'ship_to_html'    => self::lines_html( self::plain( $shipping ? $shipping : $billing ) ),
            'recipient_name'  => $ship_name ? $ship_name : $billing_name,
            'address_compact' => implode( ', ', $addr_lines ),
            'billing_name'    => $billing_name,
            'email'           => 'yes' === $s['show_email'] ? $order->get_billing_email() : '',
            'phone'           => 'yes' === $s['show_phone'] ? $order->get_billing_phone() : '',
            'shipping_phone'  => method_exists( $order, 'get_shipping_phone' ) ? $order->get_shipping_phone() : '',
            'items'           => $items,
            'total_qty'       => wc_stock_amount( $total_qty ),
            'total_weight'    => $total_weight ? wc_format_decimal( $total_weight, 2 ) . ' ' . $w_unit : '',
            'totals'          => $totals,
            'grand_total'     => self::money( (float) $order->get_total() - (float) $order->get_total_refunded(), $order ),
            'order_total'     => self::money( (float) $order->get_total(), $order ),
            'refunded'        => (float) $order->get_total_refunded() > 0 ? self::money( $order->get_total_refunded(), $order ) : '',
            'pay'             => $pay,
            'advance_text'    => $pay['advance'] > 0 ? self::money( $pay['advance'], $order ) : '',
            'paid_text'       => self::money( $pay['paid'], $order ),
            'collect_text'    => self::money( $pay['collect'], $order ),
            'amount_words'    => self::lines_html( $words ),
            'customer_note'   => 'yes' === $s['show_customer_note'] ? self::lines_html( $order->get_customer_note() ) : '',
            'qr'              => $qr,
            'barcode'         => 'yes' === $s['show_barcode'] ? preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $order->get_order_number() ) : '',
            'accent'          => sanitize_hex_color( $s['accent_color'] ) ? sanitize_hex_color( $s['accent_color'] ) : '#0b8a62',
            'generated'       => wp_date( 'd M Y, h:i A' ),
        );

        return apply_filters( 'rar_wow_document_data', $data, $order, $type );
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------ */

    public static function locate_template( $file ) {
        $theme = locate_template( array( 'rar-wow/documents/' . $file ) );

        if ( $theme ) {
            return $theme;
        }

        return RAR_WOW_DIR . 'templates/documents/' . $file;
    }

    public static function template_file( $type ) {
        $files = array(
            'invoice'      => 'invoice.php',
            'packing-slip' => 'packing-slip.php',
            'label'        => 'label.php',
        );

        return self::locate_template( isset( $files[ $type ] ) ? $files[ $type ] : 'invoice.php' );
    }

    public static function document_css( $type ) {
        $accent = sanitize_hex_color( self::setting( 'accent_color' ) );
        $accent = $accent ? $accent : '#0b8a62';
        $css    = (string) file_get_contents( self::locate_template( 'style.css' ) ); // phpcs:ignore

        $css = str_replace( '__ACCENT__', $accent, $css );
        $css .= "\n.logo img{height:" . max( 6, min( 40, absint( self::setting( 'logo_height' ) ) ) ) . "mm;}\n";

        return (string) apply_filters( 'rar_wow_document_css', $css, $type );
    }

    /**
     * Render one or more orders into document body HTML.
     *
     * @param WC_Order[] $orders Orders.
     */
    public static function render_body( array $orders, $type ) {
        $html  = '';
        $first = true;

        foreach ( $orders as $order ) {
            $d = self::build_data( $order, $type );

            ob_start();
            include self::template_file( $type );
            $doc = ob_get_clean();

            if ( ! $first ) {
                $html .= '<pagebreak resetpagenum="1" />';
            }

            $html .= $doc;
            $first = false;
        }

        return $html;
    }

    public static function paper_args( $type ) {
        if ( 'label' === $type ) {
            $size = (string) self::setting( 'label_size' );

            return array(
                'format'  => 'A6' === $size ? 'A6' : ( preg_match( '/^\d+x\d+$/', $size ) ? $size : '100x150' ),
                'margins' => array( 4, 4, 4, 4 ),
            );
        }

        $paper = in_array( self::setting( 'paper_size' ), array( 'A4', 'Letter', 'A5', 'Legal' ), true ) ? self::setting( 'paper_size' ) : 'A4';

        return array(
            'format'  => $paper,
            'margins' => 'A5' === $paper ? array( 8, 8, 8, 14 ) : array( 12, 12, 11, 16 ),
        );
    }

    /**
     * @param WC_Order[] $orders Orders.
     * @return string PDF bytes.
     * @throws Exception On engine failure.
     */
    public static function render_pdf( array $orders, $type ) {
        $body  = self::render_body( $orders, $type );
        $paper = self::paper_args( $type );
        $first = reset( $orders );

        return RAR_WOW_PDF::render(
            $body,
            array(
                'format'  => $paper['format'],
                'margins' => $paper['margins'],
                'css'     => self::document_css( $type ),
                'title'   => self::filename( $orders, $type, false ),
                'author'  => self::setting( 'shop_name' ),
            )
        );
    }

    /**
     * Standalone HTML (debug / print-in-browser fallback).
     */
    public static function render_html_page( array $orders, $type ) {
        $body = self::render_body( $orders, $type );
        $body = preg_replace( '#<pagebreak[^>]*>#', '<div style="page-break-after:always"></div>', $body );
        $body = preg_replace( '#<barcode[^>]*>#', '', $body );
        $body = preg_replace( '#</?(htmlpagefooter|sethtmlpagefooter)[^>]*>#', '', $body );

        return '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html( self::filename( $orders, $type, false ) ) . '</title><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600&display=swap" rel="stylesheet"><style>body{font-family:"Hind Siliguri",Arial,sans-serif;max-width:190mm;margin:10mm auto;font-size:9.5pt}' . self::document_css( $type ) . '</style></head><body>' . $body . '</body></html>';
    }

    public static function filename( array $orders, $type, $ext = true ) {
        $types = self::types();
        $label = str_replace( ' ', '-', $types[ $type ]['label'] );

        if ( 1 === count( $orders ) ) {
            $order = reset( $orders );
            $ref   = $order->get_order_number();

            if ( 'invoice' === $type ) {
                $inv = self::get_invoice( $order );
                $ref = $inv ? $inv['number'] : $ref;
            }

            $name = $label . '-' . $ref;
        } else {
            $name = $label . 's-' . wp_date( 'Y-m-d' ) . '-' . count( $orders ) . '-orders';
        }

        $name = sanitize_file_name( apply_filters( 'rar_wow_document_filename', $name, $orders, $type ) );

        return $ext ? $name . '.pdf' : $name;
    }

    /* ---------------------------------------------------------------------
     * Access control & output
     * ------------------------------------------------------------------ */

    public static function admin_url_for( $order_ids, $type, $extra = array() ) {
        $ids = implode( ',', array_map( 'absint', (array) $order_ids ) );

        return add_query_arg(
            array_merge(
                array(
                    'action'    => 'rar_wow_document',
                    'type'      => $type,
                    'order_ids' => $ids,
                    '_wpnonce'  => wp_create_nonce( 'rar_wow_document' ),
                ),
                $extra
            ),
            admin_url( 'admin-ajax.php' )
        );
    }

    public static function customer_url_for( WC_Order $order, $type = 'invoice' ) {
        return add_query_arg(
            array(
                'rar-wow-doc' => $type,
                'order'       => $order->get_id(),
                'key'         => $order->get_order_key(),
            ),
            home_url( '/' )
        );
    }

    /**
     * Whether the customer should see an invoice download for this order.
     */
    public static function customer_can_download( WC_Order $order, $type = 'invoice' ) {
        if ( ! self::type_enabled( $type ) || 'invoice' !== $type ) {
            return false; // Customers only receive invoices.
        }

        $mode = self::setting( 'customer_access' );

        if ( 'never' === $mode ) {
            return false;
        }

        if ( ! in_array( $order->get_status(), (array) self::setting( 'customer_statuses' ), true ) ) {
            return false;
        }

        if ( self::get_invoice( $order ) ) {
            return true;
        }

        return 'auto' === $mode && true === self::invoice_allowed( $order, 'customer' );
    }

    private static function no_cache() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        do_action( 'litespeed_control_set_nocache', 'RAR private document' );
        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow', true );
    }

    private static function stream_pdf( $pdf, $filename, $disposition ) {
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        self::no_cache();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: ' . ( 'download' === $disposition ? 'attachment' : 'inline' ) . '; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $pdf ) );
        header( 'X-Content-Type-Options: nosniff' );
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
        exit;
    }

    private static function fail( $message, $code = 403 ) {
        self::no_cache();
        wp_die( esc_html( $message ), esc_html( get_bloginfo( 'name' ) ), array( 'response' => $code, 'back_link' => true ) );
    }

    /**
     * Admin (single & bulk) document endpoint.
     */
    public function handle_admin_request() {
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'rar_wow_document' ) ) {
            self::fail( 'This document link has expired. Reload the orders page and try again.' );
        }

        if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            self::fail( 'You do not have permission to view order documents.' );
        }

        $type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'invoice';

        if ( ! array_key_exists( $type, self::types() ) ) {
            self::fail( 'Unknown document type.', 400 );
        }

        if ( ! self::type_enabled( $type ) ) {
            self::fail( 'This document type is disabled in WooCommerce → Order Workflow → Documents.', 400 );
        }

        $ids = isset( $_GET['order_ids'] ) ? wp_parse_id_list( wp_unslash( $_GET['order_ids'] ) ) : array(); // phpcs:ignore
        $ids = array_slice( array_filter( $ids ), 0, 300 );

        $orders  = array();
        $skipped = array();

        foreach ( $ids as $id ) {
            $order = wc_get_order( $id );

            if ( ! $order instanceof WC_Order || 'shop_order_refund' === $order->get_type() ) {
                continue;
            }

            if ( 'invoice' === $type ) {
                $result = self::ensure_invoice( $order, 'printed from admin', 'admin' );

                if ( is_wp_error( $result ) ) {
                    $skipped[] = '#' . $order->get_order_number() . ': ' . $result->get_error_message();
                    continue;
                }
            }

            $orders[] = $order;
        }

        if ( ! $orders ) {
            self::fail( $skipped ? "No document generated.\n" . implode( "\n", $skipped ) : 'No valid orders selected.', 400 );
        }

        $output = isset( $_GET['output'] ) ? sanitize_key( wp_unslash( $_GET['output'] ) ) : 'pdf';

        if ( 'html' === $output ) {
            self::no_cache();
            echo self::render_html_page( $orders, $type ); // phpcs:ignore -- template output is escaped.
            exit;
        }

        try {
            $pdf = self::render_pdf( $orders, $type );
        } catch ( Throwable $e ) {
            wc_get_logger()->error( 'PDF generation failed: ' . $e->getMessage(), array( 'source' => 'rar-wow', 'type' => $type, 'orders' => $ids ) );
            self::fail( 'PDF generation failed: ' . $e->getMessage() . ' — check WooCommerce → Status → Logs (rar-wow).', 500 );
        }

        if ( empty( $_GET['preview'] ) ) {
            foreach ( $orders as $order ) {
                self::mark_printed( $order, $type );
            }
        }

        $disposition = ! empty( $_GET['download'] ) ? 'download' : self::setting( 'output_mode' );

        self::stream_pdf( $pdf, self::filename( $orders, $type ), $disposition );
    }

    public static function mark_printed( WC_Order $order, $type ) {
        $printed = $order->get_meta( self::META_PRINTED, true );
        $printed = is_array( $printed ) ? $printed : array();
        $entry   = isset( $printed[ $type ] ) && is_array( $printed[ $type ] ) ? $printed[ $type ] : array( 'first' => time(), 'count' => 0 );

        $entry['last']  = time();
        $entry['count'] = absint( isset( $entry['count'] ) ? $entry['count'] : 0 ) + 1;
        $entry['by']    = wp_get_current_user()->display_name;

        $printed[ $type ] = $entry;
        $order->update_meta_data( self::META_PRINTED, $printed );
        $order->save_meta_data();
    }

    /**
     * Customer / guest downloads and public invoice verification.
     */
    public function handle_frontend_request() {
        if ( isset( $_GET['rar-wow-verify'] ) ) { // phpcs:ignore
            $this->render_verify_page();
            return;
        }

        if ( empty( $_GET['rar-wow-doc'] ) ) { // phpcs:ignore
            return;
        }

        $type     = sanitize_key( wp_unslash( $_GET['rar-wow-doc'] ) ); // phpcs:ignore
        $order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0; // phpcs:ignore

        if ( ! array_key_exists( $type, self::types() ) || ! self::type_enabled( $type ) ) {
            self::fail( 'Unknown or disabled document type.', 404 );
        }
        $key      = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore
        $order    = $order_id ? wc_get_order( $order_id ) : false;

        if ( ! $order instanceof WC_Order || ! $key || ! hash_equals( (string) $order->get_order_key(), (string) $key ) ) {
            self::fail( 'Invalid or expired document link.', 404 );
        }

        $is_staff = current_user_can( 'edit_shop_orders' );
        $is_owner = is_user_logged_in() && absint( $order->get_customer_id() ) === get_current_user_id();

        if ( ! $is_staff && ! $is_owner ) {
            if ( 'yes' !== self::setting( 'guest_access' ) ) {
                if ( $order->get_customer_id() ) {
                    wp_safe_redirect( wp_login_url( self::customer_url_for( $order, $type ) ) );
                    exit;
                }

                self::fail( 'Please contact the store to receive your invoice.' );
            }
        }

        if ( ! $is_staff && ! self::customer_can_download( $order, $type ) ) {
            self::fail( 'The invoice for this order is not available yet. It will be available once the order is confirmed.' );
        }

        if ( 'invoice' === $type ) {
            $result = self::ensure_invoice( $order, 'downloaded by customer', $is_staff ? 'admin' : 'customer' );

            if ( is_wp_error( $result ) ) {
                self::fail( $result->get_error_message() );
            }
        }

        try {
            $pdf = self::render_pdf( array( $order ), $type );
        } catch ( Throwable $e ) {
            wc_get_logger()->error( 'Customer PDF failed: ' . $e->getMessage(), array( 'source' => 'rar-wow', 'order_id' => $order_id ) );
            self::fail( 'The document could not be generated right now. Please try again later.', 500 );
        }

        self::stream_pdf( $pdf, self::filename( array( $order ), $type ), self::setting( 'output_mode' ) );
    }

    private function render_verify_page() {
        $number   = isset( $_GET['rar-wow-verify'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['rar-wow-verify'] ) ) ) : ''; // phpcs:ignore
        $order_id = isset( $_GET['o'] ) ? absint( $_GET['o'] ) : 0; // phpcs:ignore
        $token    = isset( $_GET['t'] ) ? sanitize_key( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore
        $order    = $order_id ? wc_get_order( $order_id ) : false;
        $valid    = false;
        $invoice  = null;

        if ( $order instanceof WC_Order && $token && hash_equals( self::verify_token( $order_id, $number ), $token ) ) {
            $invoice = self::get_invoice( $order );
            $valid   = $invoice && $invoice['number'] === $number;
        }

        $s = self::settings();

        self::no_cache();
        status_header( $valid ? 200 : 404 );
        header( 'Content-Type: text/html; charset=utf-8' );

        $mask = static function ( $name ) {
            $parts = preg_split( '/\s+/u', trim( (string) $name ) );
            $out   = array();

            foreach ( $parts as $part ) {
                if ( '' === $part ) {
                    continue;
                }

                $out[] = mb_substr( $part, 0, 1 ) . str_repeat( '•', max( 2, min( 6, mb_strlen( $part ) - 1 ) ) );
            }

            return implode( ' ', $out );
        };

        $accent = sanitize_hex_color( $s['accent_color'] ) ? sanitize_hex_color( $s['accent_color'] ) : '#0b8a62';
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $valid ? 'Invoice verified' : 'Invoice not verified' ); ?> — <?php echo esc_html( $s['shop_name'] ); ?></title>
<style>
body{margin:0;background:#f3f6f7;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hind Siliguri",Arial,sans-serif;color:#1f2a30}
.card{max-width:460px;margin:40px auto;background:#fff;border:1px solid #e3e9ec;border-radius:16px;overflow:hidden;box-shadow:0 12px 30px rgba(20,40,50,.06)}
.top{padding:26px 26px 18px;text-align:center}
.badge{width:64px;height:64px;border-radius:50%;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;font-size:32px;color:#fff;background:<?php echo esc_attr( $valid ? $accent : '#c0392b' ); ?>}
h1{font-size:21px;margin:0 0 6px}
p{margin:0;color:#5c6b73;font-size:14px;line-height:1.55}
dl{margin:0;padding:6px 26px 22px;display:grid;grid-template-columns:42% 58%;row-gap:10px;font-size:14px}
dt{color:#6b7a82}dd{margin:0;font-weight:600;text-align:right}
.foot{background:#f8fafb;border-top:1px solid #edf1f3;padding:14px 26px;font-size:12px;color:#7a878e;text-align:center}
@media (max-width:520px){.card{margin:16px}}
</style>
</head>
<body>
<div class="card">
    <div class="top">
        <div class="badge"><?php echo $valid ? '&#10003;' : '&#10007;'; ?></div>
        <?php if ( $valid ) : ?>
            <h1>Genuine invoice</h1>
            <p>This invoice was issued by <strong><?php echo esc_html( $s['shop_name'] ); ?></strong> and matches our records.</p>
        <?php else : ?>
            <h1>Invoice could not be verified</h1>
            <p>The invoice number or security code does not match our records. Please contact <?php echo esc_html( $s['shop_name'] ); ?> directly.</p>
        <?php endif; ?>
    </div>
    <?php if ( $valid ) : ?>
        <?php $created = $order->get_date_created(); ?>
        <dl>
            <dt>Invoice No.</dt><dd><?php echo esc_html( $invoice['number'] ); ?></dd>
            <dt>Invoice date</dt><dd><?php echo esc_html( wp_date( $s['date_format'], $invoice['date'] ) ); ?></dd>
            <dt>Order No.</dt><dd>#<?php echo esc_html( $order->get_order_number() ); ?></dd>
            <dt>Order date</dt><dd><?php echo esc_html( $created ? wp_date( $s['date_format'], $created->getTimestamp() ) : '—' ); ?></dd>
            <dt>Customer</dt><dd><?php echo esc_html( $mask( $order->get_formatted_billing_full_name() ) ); ?></dd>
            <dt>Invoice total</dt><dd><?php echo esc_html( self::money( $order->get_total(), $order ) ); ?></dd>
            <dt>Current status</dt><dd><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></dd>
        </dl>
    <?php endif; ?>
    <div class="foot"><?php echo esc_html( $s['shop_name'] ); ?><?php echo $s['shop_website'] ? ' · ' . esc_html( $s['shop_website'] ) : ''; ?> — Invoice authenticity check</div>
</div>
</body>
</html>
        <?php
        exit;
    }

    /* ---------------------------------------------------------------------
     * Emails
     * ------------------------------------------------------------------ */

    /**
     * Generate a PDF file for an email attachment.
     *
     * @return string Absolute file path or '' on failure.
     */
    public static function create_attachment_file( WC_Order $order, $type ) {
        if ( ! self::type_enabled( $type ) || ! RAR_WOW_PDF::is_available() ) {
            return '';
        }

        if ( 'invoice' === $type ) {
            $result = self::ensure_invoice( $order, 'attached to email', 'email' );

            if ( is_wp_error( $result ) ) {
                return '';
            }
        }

        // Opportunistic cleanup: no dependency on WP-Cron.
        RAR_WOW_PDF::purge( 'attachments', 6 * HOUR_IN_SECONDS );

        $dir = RAR_WOW_PDF::temp_dir( true, 'attachments' );

        if ( ! $dir ) {
            return '';
        }

        $folder = $dir . '/' . wp_generate_password( 16, false, false );

        if ( ! wp_mkdir_p( $folder ) ) {
            return '';
        }

        try {
            $pdf  = self::render_pdf( array( $order ), $type );
            $file = $folder . '/' . self::filename( array( $order ), $type );

            if ( false === file_put_contents( $file, $pdf ) ) { // phpcs:ignore
                wc_get_logger()->error( 'Could not write attachment file ' . $file, array( 'source' => 'rar-wow', 'order_id' => $order->get_id() ) );
                return '';
            }

            return $file;
        } catch ( Throwable $e ) {
            wc_get_logger()->error( 'Email attachment PDF failed: ' . $e->getMessage(), array( 'source' => 'rar-wow', 'order_id' => $order->get_id(), 'type' => $type ) );
            $order->add_order_note( 'RAR Documents: could not attach ' . $type . ' PDF — ' . $e->getMessage() );

            return '';
        }
    }

    /**
     * Attachments for an email id (WooCommerce or RAR).
     *
     * @return string[]
     */
    public static function attachments_for( WC_Order $order, $email_id ) {
        $files = array();

        if ( 'yes' !== self::setting( 'enabled' ) ) {
            return $files;
        }

        $map = array(
            'invoice'      => (array) self::setting( 'attach_invoice' ),
            'packing-slip' => (array) self::setting( 'attach_packing' ),
        );

        foreach ( $map as $type => $email_ids ) {
            if ( ! in_array( $email_id, $email_ids, true ) ) {
                continue;
            }

            if ( 'invoice' === $type && true !== self::invoice_allowed( $order, 'email' ) && ! self::get_invoice( $order ) ) {
                continue;
            }

            $file = self::create_attachment_file( $order, $type );

            if ( $file ) {
                $files[] = $file;
            }
        }

        return $files;
    }

    public function attach_to_wc_email( $attachments, $email_id = '', $object = null, $email = null ) {
        if ( ! $object instanceof WC_Order || ! is_array( $attachments ) ) {
            return $attachments;
        }

        return array_merge( $attachments, self::attachments_for( $object, (string) $email_id ) );
    }

    /**
     * Button HTML for RAR customer emails.
     */
    public static function email_button_html( WC_Order $order ) {
        if ( 'yes' !== self::setting( 'email_download_button' ) || ! self::customer_can_download( $order, 'invoice' ) ) {
            return '';
        }

        if ( 'yes' !== self::setting( 'guest_access' ) && ! $order->get_customer_id() ) {
            return ''; // Guest order without guest links: the button could not be opened.
        }

        return '<a href="' . esc_url( self::customer_url_for( $order, 'invoice' ) ) . '" style="display:inline-block;margin-left:6px;background:#ffffff;color:#0b8a62;border:1px solid #0b8a62;text-decoration:none;font-weight:700;padding:9px 14px;border-radius:7px;">Download Invoice / ইনভয়েস</a>';
    }

    /* ---------------------------------------------------------------------
     * My Account
     * ------------------------------------------------------------------ */

    public function my_account_action( $actions, $order ) {
        if ( $order instanceof WC_Order && self::customer_can_download( $order, 'invoice' ) ) {
            $actions['rar_invoice'] = array(
                'url'  => self::customer_url_for( $order, 'invoice' ),
                'name' => 'Invoice (PDF)',
            );
        }

        return $actions;
    }

    /**
     * Fallback button on View Order when the RAR status panel is disabled.
     */
    public function order_details_button( $order ) {
        if ( ! $order instanceof WC_Order || ! is_account_page() ) {
            return;
        }

        $workflow = get_option( 'rar_wow_settings', array() );

        if ( ! isset( $workflow['customer_status_panel'] ) || 'yes' === $workflow['customer_status_panel'] ) {
            return; // Panel renders its own button.
        }

        if ( ! self::customer_can_download( $order, 'invoice' ) ) {
            return;
        }

        echo '<p class="rar-wow-invoice-download"><a class="button rar-wow-doc-link" target="_blank" rel="noopener" href="' . esc_url( self::customer_url_for( $order, 'invoice' ) ) . '">Download Invoice (PDF)</a></p>';
    }
}
