<?php
/**
 * Admin UI for the RAR Documents Center.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RAR_WOW_Documents_Admin {

    /** @var RAR_WOW_Documents_Admin|null */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );

        // Orders list (legacy posts + HPOS).
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ), 20 );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column_legacy' ), 20, 2 );
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ), 20 );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column_hpos' ), 20, 2 );

        add_filter( 'bulk_actions-edit-shop_order', array( $this, 'bulk_actions' ), 30 );
        add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'bulk_actions' ), 30 );
        add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk' ), 10, 3 );
        add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk' ), 10, 3 );
        add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
        add_action( 'admin_notices', array( $this, 'action_notice' ) );
        add_action( 'admin_notices', array( $this, 'legacy_plugin_notice' ) );

        // Search orders by invoice number.
        add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'search_fields_legacy' ) );
        add_filter( 'woocommerce_order_table_search_query_meta_keys', array( $this, 'search_fields_hpos' ) );

        // Order edit screen.
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 30 );
        add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_meta_box' ), 60, 2 );
        add_action( 'admin_post_rar_wow_doc_action', array( $this, 'handle_doc_action' ) );

        // Tools.
        add_action( 'admin_post_rar_wow_tools', array( $this, 'handle_tools' ) );
    }

    /* ---------------------------------------------------------------------
     * Settings schema
     * ------------------------------------------------------------------ */

    private function statuses() {
        $out = array();

        foreach ( wc_get_order_statuses() as $slug => $label ) {
            $out[ substr( $slug, 3 ) ] = wp_strip_all_tags( $label );
        }

        return $out;
    }

    public function schema( $section ) {
        $yes_no = array( 'type' => 'checkbox' );

        if ( 'documents' === $section ) {
            return array(
                'General' => array(
                    'enabled'         => $yes_no + array( 'label' => 'Documents Center', 'desc' => 'Enable invoices, packing slips and delivery labels' ),
                    'invoice_enabled' => $yes_no + array( 'label' => 'Invoice', 'desc' => 'Enable PDF invoices' ),
                    'packing_enabled' => $yes_no + array( 'label' => 'Packing slip', 'desc' => 'Enable PDF packing slips (no prices, pick-list with checkboxes)' ),
                    'label_enabled'   => $yes_no + array( 'label' => 'Delivery label', 'desc' => 'Enable courier delivery labels with COD amount, barcode and QR' ),
                ),
                'Shop identity' => array(
                    'logo_id'      => array( 'type' => 'media', 'label' => 'Shop logo', 'desc' => 'PNG/JPG recommended. Used on invoice and packing slip.' ),
                    'logo_height'  => array( 'type' => 'number', 'label' => 'Logo height (mm)', 'min' => 6, 'max' => 40 ),
                    'shop_name'    => array( 'type' => 'text', 'label' => 'Shop / company name' ),
                    'shop_address' => array( 'type' => 'textarea', 'label' => 'Shop address', 'desc' => 'One line per row.' ),
                    'shop_phone'   => array( 'type' => 'text', 'label' => 'Phone / hotline' ),
                    'shop_email'   => array( 'type' => 'text', 'label' => 'Email' ),
                    'shop_website' => array( 'type' => 'text', 'label' => 'Website' ),
                    'tax_id_label' => array( 'type' => 'text', 'label' => 'Tax ID label', 'desc' => 'e.g. BIN, VAT Reg. No., TIN, Trade License' ),
                    'tax_id'       => array( 'type' => 'text', 'label' => 'Tax ID value' ),
                    'shop_extra'   => array( 'type' => 'textarea', 'label' => 'Extra header lines', 'desc' => 'Optional: bKash/Nagad merchant number, bank account, office hours…' ),
                    'accent_color' => array( 'type' => 'color', 'label' => 'Accent colour' ),
                ),
                'Layout' => array(
                    'paper_size'            => array( 'type' => 'select', 'label' => 'Paper size (invoice & packing slip)', 'options' => array( 'A4' => 'A4', 'Letter' => 'Letter', 'A5' => 'A5', 'Legal' => 'Legal' ) ),
                    'label_size'            => array( 'type' => 'select', 'label' => 'Delivery label size', 'options' => array( '100x150' => '100 × 150 mm (4 × 6 in thermal)', '100x100' => '100 × 100 mm', 'A6' => 'A6 (105 × 148 mm)', '75x100' => '75 × 100 mm' ) ),
                    'date_format'           => array( 'type' => 'text', 'label' => 'Document date format', 'desc' => 'PHP date format, e.g. d M Y → 25 Sep 2026, d/m/Y → 25/09/2026' ),
                    'invoice_title'         => array( 'type' => 'text', 'label' => 'Invoice title' ),
                    'invoice_subtitle'      => array( 'type' => 'text', 'label' => 'Invoice subtitle (Bangla)' ),
                    'packing_title'         => array( 'type' => 'text', 'label' => 'Packing slip title' ),
                    'packing_subtitle'      => array( 'type' => 'text', 'label' => 'Packing slip subtitle (Bangla)' ),
                    'label_title'           => array( 'type' => 'text', 'label' => 'Delivery label title' ),
                    'show_shipping_address' => array( 'type' => 'select', 'label' => 'Ship-to address on invoice', 'options' => array( 'always' => 'Always', 'when_different' => 'Only when different from billing', 'never' => 'Never' ) ),
                    'show_email'            => $yes_no + array( 'label' => 'Customer email', 'desc' => 'Show billing email' ),
                    'show_phone'            => $yes_no + array( 'label' => 'Customer phone', 'desc' => 'Show billing phone' ),
                    'show_sku'              => $yes_no + array( 'label' => 'SKU', 'desc' => 'Show product SKU' ),
                    'show_thumbnails'       => $yes_no + array( 'label' => 'Product images', 'desc' => 'Show product thumbnails (slightly larger PDFs)' ),
                    'show_item_meta'        => $yes_no + array( 'label' => 'Item meta', 'desc' => 'Show variation attributes / item meta' ),
                    'show_weight'           => $yes_no + array( 'label' => 'Weight', 'desc' => 'Show weights on packing slip & label' ),
                    'show_payment_method'   => $yes_no + array( 'label' => 'Payment method', 'desc' => 'Show payment method in the invoice header' ),
                    'show_courier'          => $yes_no + array( 'label' => 'Courier & tracking', 'desc' => 'Show shipping method, courier, ETA and tracking on invoice' ),
                    'show_customer_note'    => $yes_no + array( 'label' => 'Customer note', 'desc' => 'Show the customer’s checkout note' ),
                    'show_amount_words'     => array( 'type' => 'select', 'label' => 'Amount in words', 'options' => array( 'en' => 'English (Taka … Only)', 'bn' => 'Bangla (… টাকা মাত্র)', 'both' => 'English + Bangla', 'no' => 'Hide' ) ),
                    'show_status_stamp'     => $yes_no + array( 'label' => 'Payment stamp', 'desc' => 'PAID / CASH ON DELIVERY stamp with amount to collect' ),
                    'qr_mode'               => array( 'type' => 'select', 'label' => 'QR code', 'options' => array( 'verify' => 'Invoice authenticity verification page', 'track' => 'Customer order-status page', 'none' => 'No QR code' ) ),
                    'show_barcode'          => $yes_no + array( 'label' => 'Barcode', 'desc' => 'Code-128 order/tracking barcode on packing slip & label' ),
                    'show_signature'        => $yes_no + array( 'label' => 'Signature lines', 'desc' => 'Customer + authorized signature lines on invoice' ),
                    'signature_label'       => array( 'type' => 'text', 'label' => 'Authorized signature caption' ),
                    'terms'                 => array( 'type' => 'textarea', 'label' => 'Terms & conditions' ),
                    'footer'                => array( 'type' => 'textarea', 'label' => 'Footer text' ),
                ),
            );
        }

        if ( 'numbering' === $section ) {
            $emails = RAR_WOW_Documents::attachable_emails();

            return array(
                'Invoice numbers' => array(
                    'number_prefix'         => array( 'type' => 'text', 'label' => 'Prefix', 'desc' => 'Placeholders: [invoice_year] [invoice_month] [invoice_day] [yy] [order_year] [order_month] [order_number] [invoice_date="ymd"]. Example: NAB-[invoice_year]-' ),
                    'number_suffix'         => array( 'type' => 'text', 'label' => 'Suffix' ),
                    'number_padding'        => array( 'type' => 'number', 'label' => 'Padding (digits)', 'min' => 0, 'max' => 12, 'desc' => '5 → 00042' ),
                    'reset_yearly'          => $yes_no + array( 'label' => 'Yearly reset', 'desc' => 'Restart numbering at 1 every January (separate series per year)' ),
                    'invoice_date_source'   => array( 'type' => 'select', 'label' => 'Invoice date', 'options' => array( 'invoice' => 'Date the invoice is created', 'order' => 'Order date' ) ),
                    'auto_invoice_statuses' => array( 'type' => 'multicheck', 'label' => 'Create invoice automatically when order becomes', 'options' => $this->statuses(), 'desc' => 'Only assigns the number (no PDF is generated), so it is fast. Leave all unchecked to create invoices only when printed/attached.' ),
                    'disable_free'          => $yes_no + array( 'label' => 'Free orders', 'desc' => 'Do not issue invoices for zero-total orders' ),
                    'invoice_blocked_statuses' => array( 'type' => 'multicheck', 'label' => 'Never issue invoices automatically for', 'options' => $this->statuses(), 'desc' => 'Admins can still print manually.' ),
                    'mirror_legacy_meta'    => $yes_no + array( 'label' => 'Compatibility meta', 'desc' => 'Also write _wcpdf_invoice_number/_wcpdf_invoice_date so exports, courier and staff tools built for the old PDF plugin keep working' ),
                ),
                'Email attachments' => array(
                    'attach_invoice'        => array( 'type' => 'multicheck', 'label' => 'Attach invoice PDF to', 'options' => $emails, 'desc' => 'Tip: avoid attaching to “New order” / “Processing order” if checkout speed matters — each attachment renders a PDF during that request.' ),
                    'attach_packing'        => array( 'type' => 'multicheck', 'label' => 'Attach packing slip PDF to', 'options' => $emails ),
                    'email_download_button' => $yes_no + array( 'label' => 'Invoice button in emails', 'desc' => 'Add a “Download Invoice” button next to “View Order” in RAR customer emails (when the customer may download it)' ),
                ),
                'Customer access' => array(
                    'customer_access'   => array( 'type' => 'select', 'label' => 'Customer invoice download', 'options' => array( 'created' => 'Only when the invoice already exists (recommended)', 'auto' => 'Always — create the invoice when the customer downloads', 'never' => 'Never' ) ),
                    'customer_statuses' => array( 'type' => 'multicheck', 'label' => 'Allow download for statuses', 'options' => $this->statuses() ),
                    'guest_access'      => $yes_no + array( 'label' => 'Guest links', 'desc' => 'Allow secure order-key links (for guest orders and email buttons) without logging in' ),
                    'output_mode'       => array( 'type' => 'select', 'label' => 'PDF opens', 'options' => array( 'inline' => 'In the browser (print from viewer)', 'download' => 'As a file download' ) ),
                ),
            );
        }

        return array();
    }

    public function register_settings() {
        register_setting(
            'rar_wow_docs_group',
            RAR_WOW_Documents::OPTION,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize' ),
            )
        );
    }

    public function sanitize( $input ) {
        $current = RAR_WOW_Documents::settings();
        $before  = $current;
        $input   = is_array( $input ) ? $input : array();
        $section = isset( $input['_section'] ) ? sanitize_key( $input['_section'] ) : '';
        $schema  = $this->schema( $section );

        if ( ! $schema ) {
            // Programmatic update (e.g. migration) — values already sanitized by caller.
            unset( $input['_section'] );
            return wp_parse_args( $input, $current );
        }

        foreach ( $schema as $fields ) {
            foreach ( $fields as $key => $field ) {
                $value = isset( $input[ $key ] ) ? $input[ $key ] : null;

                switch ( $field['type'] ) {
                    case 'checkbox':
                        $current[ $key ] = $value ? 'yes' : 'no';
                        break;
                    case 'multicheck':
                        $allowed         = array_keys( $field['options'] );
                        $current[ $key ] = array_values( array_intersect( array_map( 'sanitize_text_field', (array) $value ), $allowed ) );
                        break;
                    case 'select':
                        $current[ $key ] = isset( $field['options'][ $value ] ) ? $value : $current[ $key ];
                        break;
                    case 'number':
                    case 'media':
                        $num = absint( $value );
                        if ( isset( $field['max'] ) ) {
                            $num = min( $field['max'], max( isset( $field['min'] ) ? $field['min'] : 0, $num ) );
                        }
                        $current[ $key ] = $num;
                        break;
                    case 'color':
                        $color           = sanitize_hex_color( $value );
                        $current[ $key ] = $color ? $color : '#0b8a62';
                        break;
                    case 'textarea':
                        $current[ $key ] = sanitize_textarea_field( (string) $value );
                        break;
                    default:
                        $current[ $key ] = sanitize_text_field( (string) $value );
                }
            }
        }

        if ( 'numbering' === $section ) {
            if ( 'yes' === $current['reset_yearly'] && ! RAR_WOW_Documents::has_year_placeholder( $current['number_prefix'], $current['number_suffix'] ) ) {
                $current['reset_yearly'] = $before['reset_yearly'];
                add_settings_error( 'rar_wow_docs', 'rar_wow_reset', 'Yearly reset needs a year placeholder in the prefix or suffix (e.g. NAB-[invoice_year]-), otherwise January invoices would repeat last year\'s numbers. Yearly reset was not changed.', 'error' );
            }

            RAR_WOW_Documents::protect_series_switch( $before['reset_yearly'], $current['reset_yearly'] );
        }

        $next_changed = isset( $input['_next_number'], $input['_next_number_original'] ) && (string) absint( $input['_next_number'] ) !== (string) absint( $input['_next_number_original'] );

        if ( 'numbering' === $section && $next_changed && '' !== $input['_next_number'] ) {
            $series = 'yes' === $current['reset_yearly'] ? 'invoice-' . wp_date( 'Y' ) : 'invoice';
            $next   = absint( $input['_next_number'] );
            $max    = RAR_WOW_Documents::max_number( $series );

            if ( $next && $next !== RAR_WOW_Documents::next_number( $series ) ) {
                if ( $next <= $max ) {
                    add_settings_error( 'rar_wow_docs', 'rar_wow_next', sprintf( 'Next invoice number must be higher than the last issued number (%d) in this series. Duplicates are not allowed.', $max ), 'error' );
                } else {
                    RAR_WOW_Documents::set_floor( $series, $next );
                }
            }
        }

        RAR_WOW_Documents::flush_settings_cache();

        return $current;
    }

    /* ---------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------ */

    private function is_orders_screen() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $id     = $screen ? (string) $screen->id : '';

        return false !== strpos( $id, 'shop_order' ) || false !== strpos( $id, 'wc-orders' );
    }

    public function enqueue() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $id     = $screen ? (string) $screen->id : '';

        $is_settings = 'woocommerce_page_rar-wow-workflow' === $id;
        $is_register = 'woocommerce_page_rar-wow-register' === $id;

        if ( ! $is_settings && ! $is_register && ! $this->is_orders_screen() && 'dashboard' !== $id ) {
            return;
        }

        wp_enqueue_style( 'rar-wow-admin', RAR_WOW_URL . 'assets/admin.css', array(), RAR_WOW_VERSION );

        if ( $is_settings ) {
            wp_enqueue_media();
            wp_enqueue_style( 'wp-color-picker' );
            wp_enqueue_script( 'wp-color-picker' );
        }

        wp_enqueue_script( 'rar-wow-admin', RAR_WOW_URL . 'assets/admin.js', $is_settings ? array( 'jquery', 'wp-color-picker' ) : array( 'jquery' ), RAR_WOW_VERSION, true );
        wp_localize_script(
            'rar-wow-admin',
            'rarWowAdmin',
            array(
                'docUrl'      => admin_url( 'admin-ajax.php?action=rar_wow_document' ),
                'nonce'       => wp_create_nonce( 'rar_wow_document' ),
                'printActions'=> array(
                    'rar_wow_print_invoice'      => 'invoice',
                    'rar_wow_print_packing-slip' => 'packing-slip',
                    'rar_wow_print_label'        => 'label',
                ),
                'noSelection' => 'Select at least one order first.',
            )
        );
    }

    /* ---------------------------------------------------------------------
     * Orders list
     * ------------------------------------------------------------------ */

    public function add_column( $columns ) {
        if ( 'yes' !== RAR_WOW_Documents::setting( 'enabled' ) ) {
            return $columns;
        }

        $new = array();

        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;

            if ( 'order_status' === $key ) {
                $new['rar_wow_docs'] = 'Documents';
            }
        }

        if ( ! isset( $new['rar_wow_docs'] ) ) {
            $new['rar_wow_docs'] = 'Documents';
        }

        return $new;
    }

    public function render_column_legacy( $column, $post_id ) {
        if ( 'rar_wow_docs' === $column ) {
            $order = wc_get_order( $post_id );

            if ( $order ) {
                $this->column_html( $order );
            }
        }
    }

    public function render_column_hpos( $column, $order ) {
        if ( 'rar_wow_docs' === $column && $order instanceof WC_Order ) {
            $this->column_html( $order );
        }
    }

    private function icon( $name ) {
        $icons = array(
            'invoice' => '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm8 1.5V8h4.5L14 3.5zM7 11v1.6h10V11H7zm0 3.4V16h10v-1.6H7zm0 3.4v1.6h6v-1.6H7z"/></svg>',
            'packing' => '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M12 2 3 6.5v11L12 22l9-4.5v-11L12 2zm0 2.2 6.6 3.3L12 10.8 5.4 7.5 12 4.2zM5 9.1l6 3v7.6l-6-3V9.1zm8 10.6v-7.6l6-3v7.6l-6 3z"/></svg>',
            'label'   => '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M3 5h18v14H3V5zm2 2v10h14V7H5zm1 2h1.2v6H6V9zm2 0h.8v6H8V9zm1.8 0H11v6H9.8V9zm2.2 0h.8v6H12V9zm1.8 0h1.6v6h-1.6V9zm2.6 0h.8v6h-.8V9zm1.6 0H18v6h-1V9z"/></svg>',
        );

        return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
    }

    private function column_html( WC_Order $order ) {
        $invoice = RAR_WOW_Documents::get_invoice( $order );
        $printed = $order->get_meta( RAR_WOW_Documents::META_PRINTED, true );
        $printed = is_array( $printed ) ? $printed : array();

        echo '<div class="rar-wow-docs-cell">';

        if ( $invoice ) {
            echo '<span class="rar-wow-inv-no" title="Invoice date: ' . esc_attr( wp_date( 'd M Y', $invoice['date'] ) ) . '">' . esc_html( $invoice['number'] ) . '</span>';
        } else {
            echo '<span class="rar-wow-inv-no is-empty">No invoice</span>';
        }

        echo '<span class="rar-wow-doc-buttons">';

        foreach ( RAR_WOW_Documents::types() as $type => $def ) {
            if ( ! RAR_WOW_Documents::type_enabled( $type ) ) {
                continue;
            }

            $was   = isset( $printed[ $type ] ) ? $printed[ $type ] : null;
            $title = $def['label'] . ( $was ? ' — printed ' . absint( $was['count'] ) . '× (last ' . wp_date( 'd M, H:i', absint( $was['last'] ) ) . ')' : ' — not printed yet' );

            printf(
                '<a href="%1$s" target="_blank" rel="noopener" class="rar-wow-doc-btn rar-wow-doc-%2$s%3$s" title="%4$s" aria-label="%4$s">%5$s</a>',
                esc_url( RAR_WOW_Documents::admin_url_for( array( $order->get_id() ), $type ) ),
                esc_attr( $type ),
                $was ? ' is-printed' : '',
                esc_attr( $title ),
                $this->icon( $def['icon'] ) // phpcs:ignore -- static SVG.
            );
        }

        echo '</span></div>';
    }

    public function bulk_actions( $actions ) {
        if ( 'yes' !== RAR_WOW_Documents::setting( 'enabled' ) ) {
            return $actions;
        }

        foreach ( RAR_WOW_Documents::types() as $type => $def ) {
            if ( RAR_WOW_Documents::type_enabled( $type ) ) {
                $actions[ 'rar_wow_print_' . $type ] = 'Print ' . $def['label'] . 's (PDF)';
            }
        }

        if ( RAR_WOW_Documents::type_enabled( 'invoice' ) ) {
            $actions['rar_wow_create_invoices'] = 'Create invoice numbers';
        }

        return $actions;
    }

    public function handle_bulk( $redirect, $action, $ids ) {
        $ids = array_map( 'absint', (array) $ids );

        if ( 0 === strpos( $action, 'rar_wow_print_' ) ) {
            $type = substr( $action, strlen( 'rar_wow_print_' ) );

            if ( array_key_exists( $type, RAR_WOW_Documents::types() ) && $ids ) {
                // JS normally opens a new tab; this is the no-JS fallback.
                return RAR_WOW_Documents::admin_url_for( $ids, $type );
            }

            return $redirect;
        }

        if ( 'rar_wow_create_invoices' === $action ) {
            $created = 0;
            $failed  = 0;

            foreach ( $ids as $id ) {
                $order = wc_get_order( $id );

                if ( ! $order instanceof WC_Order ) {
                    continue;
                }

                $had    = (bool) RAR_WOW_Documents::get_invoice( $order );
                $result = RAR_WOW_Documents::ensure_invoice( $order, 'bulk action', 'admin' );

                if ( is_wp_error( $result ) ) {
                    $failed++;
                } elseif ( ! $had ) {
                    $created++;
                }
            }

            return add_query_arg(
                array(
                    'rar_wow_bulk_created' => $created,
                    'rar_wow_bulk_failed'  => $failed,
                ),
                $redirect
            );
        }

        return $redirect;
    }

    public function bulk_notice() {
        if ( ! isset( $_GET['rar_wow_bulk_created'] ) ) { // phpcs:ignore
            return;
        }

        $created = absint( $_GET['rar_wow_bulk_created'] ); // phpcs:ignore
        $failed  = isset( $_GET['rar_wow_bulk_failed'] ) ? absint( $_GET['rar_wow_bulk_failed'] ) : 0; // phpcs:ignore

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p><strong>RAR Documents:</strong> %2$d new invoice number(s) created.%3$s</p></div>',
            $failed ? 'warning' : 'success',
            (int) $created,
            $failed ? ' ' . (int) $failed . ' order(s) skipped (free order, disabled status or lock) — see order notes/settings.' : ''
        );
    }

    /**
     * Warn while the old PDF plugin is still active (duplicate attachments / two numbering systems).
     */
    public function legacy_plugin_notice() {
        if ( ! current_user_can( 'manage_woocommerce' ) || 'yes' !== RAR_WOW_Documents::setting( 'enabled' ) ) {
            return;
        }

        if ( ! function_exists( 'WPO_WCPDF' ) && ! defined( 'WPO_WCPDF_VERSION' ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $id     = $screen ? (string) $screen->id : '';

        if ( ! in_array( $id, array( 'dashboard', 'plugins', 'woocommerce_page_rar-wow-workflow' ), true ) && ! $this->is_orders_screen() ) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>RAR Documents:</strong> “PDF Invoices &amp; Packing Slips for WooCommerce” is still active. Both plugins may attach invoices to the same emails. <a href="%s">Migrate its settings &amp; invoice numbers</a>, then deactivate it.</p></div>',
            esc_url( admin_url( 'admin.php?page=rar-wow-workflow&tab=tools' ) )
        );
    }

    public function action_notice() {
        $key    = 'rar_wow_notice_' . get_current_user_id();
        $notice = get_transient( $key );

        if ( ! $notice || empty( $notice['message'] ) ) {
            return;
        }

        delete_transient( $key );

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p><strong>RAR Documents:</strong> %2$s</p></div>',
            'error' === $notice['type'] ? 'error' : 'success',
            esc_html( $notice['message'] )
        );
    }

    public function search_fields_legacy( $fields ) {
        $fields[] = RAR_WOW_Documents::META_NUMBER;

        return $fields;
    }

    public function search_fields_hpos( $keys ) {
        $keys   = is_array( $keys ) ? $keys : array();
        $keys[] = RAR_WOW_Documents::META_NUMBER;

        return $keys;
    }

    /* ---------------------------------------------------------------------
     * Order edit meta box
     * ------------------------------------------------------------------ */

    public function add_meta_box() {
        if ( 'yes' !== RAR_WOW_Documents::setting( 'enabled' ) ) {
            return;
        }

        $screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

        add_meta_box( 'rar-wow-documents', 'Documents — Invoice & Print', array( $this, 'render_meta_box' ), $screen, 'side', 'high' );
    }

    private function action_url( WC_Order $order, $do ) {
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=rar_wow_doc_action&do=' . rawurlencode( $do ) . '&order_id=' . $order->get_id() ),
            'rar_wow_doc_action_' . $order->get_id() . '_' . $do
        );
    }

    public function render_meta_box( $post_or_order ) {
        $order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );

        if ( ! $order ) {
            return;
        }

        $invoice = RAR_WOW_Documents::get_invoice( $order );
        $printed = $order->get_meta( RAR_WOW_Documents::META_PRINTED, true );
        $printed = is_array( $printed ) ? $printed : array();
        $types   = RAR_WOW_Documents::types();

        wp_nonce_field( 'rar_wow_save_invoice', 'rar_wow_invoice_nonce' );
        ?>
        <div class="rar-wow-metabox">
            <?php if ( RAR_WOW_Documents::type_enabled( 'invoice' ) ) : ?>
                <div class="rar-wow-mb-invoice">
                    <?php if ( $invoice ) : ?>
                        <div class="rar-wow-mb-row"><span>Invoice No.</span><strong><?php echo esc_html( $invoice['number'] ); ?></strong></div>
                        <div class="rar-wow-mb-row"><span>Invoice date</span><strong><?php echo esc_html( wp_date( 'd M Y, H:i', $invoice['date'] ) ); ?></strong></div>
                        <?php if ( 'legacy' === $invoice['source'] ) : ?>
                            <p class="rar-wow-mb-hint">Created by the old PDF Invoices plugin — number is kept and will be adopted into the RAR register on first print.</p>
                        <?php endif; ?>

                        <details class="rar-wow-mb-edit">
                            <summary>Edit number / date / notes</summary>
                            <p><label>Invoice number<br><input type="text" name="rar_wow_invoice_number" value="<?php echo esc_attr( $invoice['number'] ); ?>" class="widefat"></label></p>
                            <p><label>Invoice date<br><input type="datetime-local" name="rar_wow_invoice_date" value="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i', $invoice['date'] ) ); ?>" class="widefat"></label></p>
                            <p><label>Notes (printed on invoice)<br><textarea name="rar_wow_invoice_notes" rows="3" class="widefat"><?php echo esc_textarea( $invoice['notes'] ); ?></textarea></label></p>
                            <p class="rar-wow-mb-hint">Saved with the order (Update button). Changes are logged in order notes.</p>
                        </details>
                    <?php else : ?>
                        <p class="rar-wow-mb-hint">No invoice yet. Next number: <strong><?php echo esc_html( RAR_WOW_Documents::format_number( RAR_WOW_Documents::next_number(), $order, time() ) ); ?></strong></p>
                        <p><a class="button button-primary" href="<?php echo esc_url( $this->action_url( $order, 'create' ) ); ?>">Create invoice</a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="rar-wow-mb-docs">
                <?php foreach ( $types as $type => $def ) : ?>
                    <?php
                    if ( ! RAR_WOW_Documents::type_enabled( $type ) ) {
                        continue;
                    }
                    $was = isset( $printed[ $type ] ) ? $printed[ $type ] : null;
                    ?>
                    <div class="rar-wow-mb-doc">
                        <span class="rar-wow-mb-doc-icon"><?php echo $this->icon( $def['icon'] ); // phpcs:ignore ?></span>
                        <span class="rar-wow-mb-doc-name">
                            <?php echo esc_html( $def['label'] ); ?>
                            <small><?php echo $was ? esc_html( 'Printed ' . absint( $was['count'] ) . '× · ' . wp_date( 'd M, H:i', absint( $was['last'] ) ) ) : 'Not printed'; ?></small>
                        </span>
                        <a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( RAR_WOW_Documents::admin_url_for( array( $order->get_id() ), $type ) ); ?>">PDF</a>
                        <a class="button button-small" href="<?php echo esc_url( RAR_WOW_Documents::admin_url_for( array( $order->get_id() ), $type, array( 'download' => 1 ) ) ); ?>" title="Download">&#8595;</a>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ( RAR_WOW_Documents::type_enabled( 'invoice' ) ) : ?>
                <div class="rar-wow-mb-actions">
                    <?php if ( $order->get_billing_email() ) : ?>
                        <a class="button" href="<?php echo esc_url( $this->action_url( $order, 'email' ) ); ?>">Email invoice to customer</a>
                    <?php endif; ?>
                    <?php if ( $invoice ) : ?>
                        <a class="button-link rar-wow-mb-copy" href="#" data-copy="<?php echo esc_attr( RAR_WOW_Documents::customer_url_for( $order, 'invoice' ) ); ?>">Copy customer invoice link</a>
                        <a class="button-link-delete rar-wow-mb-void" href="<?php echo esc_url( $this->action_url( $order, 'void' ) ); ?>" onclick="return confirm('Void invoice <?php echo esc_js( $invoice['number'] ); ?>? The number stays reserved in the register and a new number will be issued next time.');">Void invoice</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function save_meta_box( $order_id, $post = null ) {
        if ( empty( $_POST['rar_wow_invoice_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rar_wow_invoice_nonce'] ) ), 'rar_wow_save_invoice' ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        if ( isset( $_POST['rar_wow_invoice_number'] ) ) {
            RAR_WOW_Documents::update_invoice_fields(
                $order,
                wp_unslash( $_POST['rar_wow_invoice_number'] ), // phpcs:ignore -- sanitized inside.
                isset( $_POST['rar_wow_invoice_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rar_wow_invoice_date'] ) ) : '',
                isset( $_POST['rar_wow_invoice_notes'] ) ? wp_unslash( $_POST['rar_wow_invoice_notes'] ) : '' // phpcs:ignore -- sanitized inside.
            );
        }

        if ( $order->get_meta( RAR_WOW_Documents::META_NUMBER, true ) ) {
            RAR_WOW_Documents::sync_register( $order );
        }
    }

    public function handle_doc_action() {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $do       = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';

        check_admin_referer( 'rar_wow_doc_action_' . $order_id . '_' . $do );

        if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage order documents.', 'rar-woo-order-workflow-notify' ) );
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_die( esc_html__( 'Order not found.', 'rar-woo-order-workflow-notify' ) );
        }

        $message = '';
        $type    = 'success';

        switch ( $do ) {
            case 'create':
                $result = RAR_WOW_Documents::ensure_invoice( $order, 'created from order screen', 'admin' );
                if ( is_wp_error( $result ) ) {
                    $message = $result->get_error_message();
                    $type    = 'error';
                } else {
                    $message = 'Invoice ' . $result['number'] . ' created.';
                }
                break;

            case 'void':
                $message = RAR_WOW_Documents::void_invoice( $order, 'voided from order screen' ) ? 'Invoice voided. The number remains reserved in the Invoice Register.' : 'No invoice to void.';
                break;

            case 'email':
                $result = $this->email_invoice( $order );
                if ( is_wp_error( $result ) ) {
                    $message = $result->get_error_message();
                    $type    = 'error';
                } else {
                    $message = 'Invoice emailed to ' . $order->get_billing_email() . '.';
                }
                break;
        }

        set_transient( 'rar_wow_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );

        wp_safe_redirect( $order->get_edit_order_url() );
        exit;
    }

    /**
     * Send the invoice as a branded RAR email with the PDF attached.
     *
     * @return true|WP_Error
     */
    private function email_invoice( WC_Order $order ) {
        $invoice = RAR_WOW_Documents::ensure_invoice( $order, 'emailed to customer', 'admin' );

        if ( is_wp_error( $invoice ) ) {
            return $invoice;
        }

        $file = RAR_WOW_Documents::create_attachment_file( $order, 'invoice' );

        if ( ! $file ) {
            return new WP_Error( 'rar_wow_pdf', 'The invoice PDF could not be generated. See WooCommerce → Status → Logs (rar-wow).' );
        }

        $to       = sanitize_email( $order->get_billing_email() );
        $workflow = wp_parse_args( (array) get_option( 'rar_wow_settings', array() ), array( 'brand_name' => get_bloginfo( 'name' ), 'support_text' => '' ) );
        $name     = trim( $order->get_billing_first_name() );
        $subject  = sprintf( '[%s] Invoice %s for order #%s', $workflow['brand_name'], $invoice['number'], $order->get_order_number() );
        $body     = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.7;color:#252a2e;max-width:600px">'
            . '<p>Dear ' . esc_html( $name ? $name : 'Customer' ) . ',</p>'
            . '<p>Please find attached invoice <strong>' . esc_html( $invoice['number'] ) . '</strong> for your order <strong>#' . esc_html( $order->get_order_number() ) . '</strong> (' . esc_html( RAR_WOW_Documents::money( $order->get_total(), $order ) ) . ').</p>'
            . '<p>আপনার অর্ডারের ইনভয়েস সংযুক্ত করা হলো। ধন্যবাদ।</p>'
            . '<p style="color:#68737c;font-size:12px">' . esc_html( $workflow['support_text'] ) . '</p>'
            . '<p style="color:#68737c;font-size:12px">' . esc_html( $workflow['brand_name'] ) . '</p></div>';

        $extra = RAR_WOW_Documents::attachments_for( $order, 'rar_document' );
        $files = array_unique( array_merge( array( $file ), array_filter( $extra, static function ( $f ) { return false === strpos( basename( $f ), 'Invoice' ); } ) ) );

        $sent = wc_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ), $files );

        $order->add_order_note( $sent ? 'RAR Documents: invoice ' . $invoice['number'] . ' emailed to ' . $to . ' by ' . wp_get_current_user()->display_name . '.' : 'RAR Documents: invoice email FAILED (' . $to . ').' );

        return $sent ? true : new WP_Error( 'rar_wow_mail', 'The mailer rejected the email. Check SMTP settings.' );
    }

    /* ---------------------------------------------------------------------
     * Settings tabs
     * ------------------------------------------------------------------ */

    public function render_tab( $tab ) {
        $notice = get_transient( 'rar_wow_tools_notice_' . get_current_user_id() );

        if ( $notice ) {
            delete_transient( 'rar_wow_tools_notice_' . get_current_user_id() );
            echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . ' is-dismissible"><p>' . wp_kses_post( $notice['message'] ) . '</p></div>';
        }

        if ( ! RAR_WOW_PDF::is_available() ) {
            echo '<div class="notice notice-error"><p><strong>PDF engine unavailable:</strong> PHP mbstring and GD extensions are required. Ask Hostinger support / hPanel → PHP Configuration to enable them.</p></div>';
        }

        if ( 'tools' === $tab ) {
            $this->render_tools();
            return;
        }

        $this->render_settings_form( $tab );
    }

    private function render_settings_form( $section ) {
        $settings = RAR_WOW_Documents::settings();
        $schema   = $this->schema( $section );
        ?>
        <form method="post" action="options.php" class="rar-wow-docs-form">
            <?php settings_fields( 'rar_wow_docs_group' ); ?>
            <input type="hidden" name="<?php echo esc_attr( RAR_WOW_Documents::OPTION ); ?>[_section]" value="<?php echo esc_attr( $section ); ?>">

            <?php foreach ( $schema as $group => $fields ) : ?>
                <div class="rar-wow-card">
                    <h2><?php echo esc_html( $group ); ?></h2>
                    <table class="form-table" role="presentation">
                        <?php if ( 'Invoice numbers' === $group ) : ?>
                            <?php
                            $series = RAR_WOW_Documents::series_for( time() );
                            $next   = RAR_WOW_Documents::next_number( $series );
                            $last   = RAR_WOW_Documents::max_number( $series );
                            ?>
                            <tr>
                                <th scope="row"><label for="rar_wow_next_number">Next invoice number</label></th>
                                <td>
                                    <input id="rar_wow_next_number" type="number" min="1" name="<?php echo esc_attr( RAR_WOW_Documents::OPTION ); ?>[_next_number]" value="<?php echo esc_attr( $next ); ?>" class="small-text">
                                    <input type="hidden" name="<?php echo esc_attr( RAR_WOW_Documents::OPTION ); ?>[_next_number_original]" value="<?php echo esc_attr( $next ); ?>">
                                    <p class="description">
                                        Series <code><?php echo esc_html( $series ); ?></code> · last issued: <strong><?php echo $last ? esc_html( $last ) : 'none'; ?></strong>.
                                        Numbers are reserved atomically in the Invoice Register, so parallel orders can never share a number.
                                    </p>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ( $fields as $key => $field ) : ?>
                            <?php $this->render_field( $key, $field, isset( $settings[ $key ] ) ? $settings[ $key ] : '' ); ?>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endforeach; ?>

            <?php submit_button( 'Save Document Settings' ); ?>
        </form>
        <?php
    }

    private function render_field( $key, $field, $value ) {
        $name = RAR_WOW_Documents::OPTION . '[' . $key . ']';
        $id   = 'rar_wow_doc_' . $key;
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
            <td>
                <?php
                switch ( $field['type'] ) {
                    case 'checkbox':
                        printf(
                            '<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> %4$s</label>',
                            esc_attr( $id ),
                            esc_attr( $name ),
                            checked( $value, 'yes', false ),
                            esc_html( isset( $field['desc'] ) ? $field['desc'] : '' )
                        );
                        unset( $field['desc'] );
                        break;

                    case 'textarea':
                        printf( '<textarea id="%1$s" name="%2$s" rows="3" class="large-text">%3$s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ) );
                        break;

                    case 'select':
                        printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
                        foreach ( $field['options'] as $opt => $label ) {
                            printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $opt ), selected( (string) $value, (string) $opt, false ), esc_html( $label ) );
                        }
                        echo '</select>';
                        break;

                    case 'multicheck':
                        $value = (array) $value;
                        echo '<fieldset class="rar-wow-multicheck">';
                        foreach ( $field['options'] as $opt => $label ) {
                            printf(
                                '<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>',
                                esc_attr( $name ),
                                esc_attr( $opt ),
                                checked( in_array( (string) $opt, array_map( 'strval', $value ), true ), true, false ),
                                esc_html( $label )
                            );
                        }
                        echo '</fieldset>';
                        break;

                    case 'number':
                        printf(
                            '<input type="number" id="%1$s" name="%2$s" value="%3$s" class="small-text" min="%4$d" max="%5$d">',
                            esc_attr( $id ),
                            esc_attr( $name ),
                            esc_attr( $value ),
                            isset( $field['min'] ) ? (int) $field['min'] : 0,
                            isset( $field['max'] ) ? (int) $field['max'] : 999999
                        );
                        break;

                    case 'color':
                        printf( '<input type="text" id="%1$s" name="%2$s" value="%3$s" class="rar-wow-color" data-default-color="#0b8a62">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
                        break;

                    case 'media':
                        $src = $value ? wp_get_attachment_image_url( absint( $value ), 'medium' ) : '';
                        printf(
                            '<div class="rar-wow-media"><img class="rar-wow-media-preview" src="%1$s" alt="" %2$s><input type="hidden" id="%3$s" name="%4$s" value="%5$s"><button type="button" class="button rar-wow-media-select">Select logo</button> <button type="button" class="button-link-delete rar-wow-media-remove" %6$s>Remove</button></div>',
                            esc_url( $src ? $src : '' ),
                            $src ? '' : 'style="display:none"',
                            esc_attr( $id ),
                            esc_attr( $name ),
                            esc_attr( $value ),
                            $src ? '' : 'style="display:none"'
                        );
                        break;

                    default:
                        printf( '<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
                }

                if ( ! empty( $field['desc'] ) ) {
                    echo '<p class="description">' . esc_html( $field['desc'] ) . '</p>';
                }
                ?>
            </td>
        </tr>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Tools, preview & migration
     * ------------------------------------------------------------------ */

    private function tools_url( $do, $extra = array() ) {
        return wp_nonce_url(
            add_query_arg( array_merge( array( 'action' => 'rar_wow_tools', 'do' => $do ), $extra ), admin_url( 'admin-post.php' ) ),
            'rar_wow_tools_' . $do
        );
    }

    private function legacy_stats() {
        global $wpdb;

        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active   = is_plugin_active( 'woocommerce-pdf-invoices-packing-slips/woocommerce-pdf-invoices-packingslips.php' );
        $settings = get_option( 'wpo_wcpdf_settings_general', false );
        $count    = $this->legacy_order_ids( 0, 0, true );

        $next  = 0;
        $table = $wpdb->prefix . 'wcpdf_invoice_number';

        if ( 'yes' === RAR_WOW_Documents::legacy_reset_yearly() ) {
            $table .= '_' . wp_date( 'Y' );
        }

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore

        if ( $exists === $table ) {
            $next   = (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$table}`" ) + 1; // phpcs:ignore
            $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $table ), ARRAY_A ); // phpcs:ignore

            if ( is_array( $status ) && ! empty( $status['Auto_increment'] ) ) {
                $next = max( $next, (int) $status['Auto_increment'] ); // The old plugin's "next number" is the AUTO_INCREMENT.
            }
        }

        return array(
            'active'   => $active,
            'settings' => is_array( $settings ),
            'orders'   => $count,
            'next'     => $next,
            'table'    => $table,
        );
    }

    private function render_tools() {
        $req    = RAR_WOW_PDF::requirements();
        $legacy = $this->legacy_stats();
        $latest = wc_get_orders( array( 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids', 'type' => 'shop_order' ) );
        $latest = $latest ? absint( $latest[0] ) : 0;
        ?>
        <div class="rar-wow-grid">
            <div class="rar-wow-card">
                <h2>Preview documents</h2>
                <p class="description">Preview uses live settings and does not mark the order as printed. An invoice preview creates the invoice number if the order has none.</p>
                <form method="get" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" target="_blank" class="rar-wow-inline-form">
                    <input type="hidden" name="action" value="rar_wow_document">
                    <input type="hidden" name="preview" value="1">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'rar_wow_document' ) ); ?>">
                    <label>Order ID <input type="number" name="order_ids" value="<?php echo esc_attr( $latest ); ?>" class="small-text" min="1"></label>
                    <select name="type">
                        <?php foreach ( RAR_WOW_Documents::types() as $type => $def ) : ?>
                            <option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $def['label'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="output">
                        <option value="pdf">PDF</option>
                        <option value="html">HTML (debug)</option>
                    </select>
                    <button class="button button-primary">Open preview</button>
                </form>
            </div>

            <div class="rar-wow-card">
                <h2>System check</h2>
                <table class="widefat striped rar-wow-syscheck">
                    <?php foreach ( $req as $row ) : ?>
                        <tr>
                            <td><?php echo $row['ok'] ? '<span class="rar-wow-ok">✔</span>' : '<span class="rar-wow-bad">✖</span>'; ?> <?php echo esc_html( $row['label'] ); ?></td>
                            <td><code><?php echo esc_html( $row['detail'] ); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr><td>PDF engine</td><td><code>mPDF 8.3 (scoped) · Hind Siliguri (Bangla + Latin)</code></td></tr>
                </table>
                <p>
                    <a class="button" href="<?php echo esc_url( $this->tools_url( 'purge' ) ); ?>">Clear temp files &amp; font cache</a>
                </p>
            </div>
        </div>

        <div class="rar-wow-card rar-wow-migrate">
            <h2>Migrate from “PDF Invoices &amp; Packing Slips for WooCommerce”</h2>
            <table class="widefat striped">
                <tr><td>Old plugin active</td><td><?php echo $legacy['active'] ? '<strong>Yes</strong> — deactivate it after migration to avoid duplicate attachments.' : 'No'; ?></td></tr>
                <tr><td>Old settings found</td><td><?php echo $legacy['settings'] ? 'Yes' : 'No'; ?></td></tr>
                <tr><td>Orders with an old invoice number</td><td><strong><?php echo esc_html( number_format_i18n( $legacy['orders'] ) ); ?></strong></td></tr>
                <tr><td>Old plugin next number</td><td><?php echo $legacy['next'] ? '<strong>' . esc_html( $legacy['next'] ) . '</strong> <code>' . esc_html( $legacy['table'] ) . '</code>' : '—'; ?></td></tr>
                <tr><td>RAR next number</td><td><strong><?php echo esc_html( RAR_WOW_Documents::next_number() ); ?></strong></td></tr>
            </table>
            <ol class="rar-wow-steps">
                <li>
                    <strong>Import settings</strong> — shop name, address, logo, footer, paper size, prefix/suffix/padding, yearly reset and display options.
                    <?php if ( $legacy['settings'] ) : ?>
                        <a class="button" href="<?php echo esc_url( $this->tools_url( 'import_settings' ) ); ?>">Import settings</a>
                    <?php else : ?>
                        <button type="button" class="button" disabled>Nothing to import</button>
                    <?php endif; ?>
                </li>
                <li>
                    <strong>Import invoice numbers</strong> — copies every old invoice number/date into the RAR register (numbers do not change) and continues numbering after the highest one. Safe to run more than once.
                    <?php if ( $legacy['orders'] || $legacy['next'] ) : ?>
                        <a class="button button-primary" href="<?php echo esc_url( $this->tools_url( 'import_numbers' ) ); ?>">Import invoice numbers</a>
                    <?php else : ?>
                        <button type="button" class="button" disabled>Nothing to import</button>
                    <?php endif; ?>
                </li>
                <li>
                    <strong>Verify</strong> one old order and one new order in the Preview box above, then <strong>deactivate &amp; delete</strong> the old plugin. Old invoice data stays in the order meta and remains readable here.
                </li>
            </ol>
        </div>
        <?php
    }

    public function handle_tools() {
        $do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';

        check_admin_referer( 'rar_wow_tools_' . $do );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'rar-woo-order-workflow-notify' ) );
        }

        $notice = array( 'type' => 'success', 'message' => '' );

        switch ( $do ) {
            case 'purge':
                $a = RAR_WOW_PDF::purge( 'attachments', 0 );
                $b = RAR_WOW_PDF::purge( 'mpdf-cache', 0 );
                $notice['message'] = sprintf( 'Removed %d temporary file(s). Font cache will rebuild on the next PDF.', $a + $b );
                break;

            case 'import_settings':
                $notice['message'] = $this->import_legacy_settings();
                break;

            case 'import_numbers':
                $after   = isset( $_GET['after'] ) ? absint( $_GET['after'] ) : 0;
                $done    = isset( $_GET['done'] ) ? absint( $_GET['done'] ) : 0;
                $batch   = 150;
                $result  = $this->import_legacy_numbers( $after, $batch );
                $done   += $result['imported'];

                if ( $result['more'] ) {
                    wp_safe_redirect( html_entity_decode( $this->tools_url( 'import_numbers', array( 'after' => $result['last'], 'done' => $done ) ) ) );
                    exit;
                }

                $floor = $this->continue_after_legacy();

                global $wpdb;
                $reg_table  = RAR_WOW_Documents::table();
                $duplicates = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$reg_table} WHERE series LIKE 'wcpdf-dup-%'" ); // phpcs:ignore

                $notice['message'] = sprintf(
                    'Imported %1$d legacy invoice number(s) into the RAR Invoice Register. Next RAR invoice number: <strong>%2$d</strong>.',
                    $done,
                    $floor
                );

                if ( $duplicates ) {
                    $notice['type']     = 'warning';
                    $notice['message'] .= sprintf( ' <br><strong>Attention:</strong> %d old invoice(s) shared a number with another order in the old plugin. They were kept unchanged and are listed in the Invoice Register (series <code>wcpdf-dup-&lt;order id&gt;</code>) — review them with your accountant.', $duplicates );
                }
                break;
        }

        set_transient( 'rar_wow_tools_notice_' . get_current_user_id(), $notice, 120 );
        wp_safe_redirect( admin_url( 'admin.php?page=rar-wow-workflow&tab=tools' ) );
        exit;
    }

    private function import_legacy_settings() {
        $general = get_option( 'wpo_wcpdf_settings_general', array() );
        $invoice = get_option( 'wpo_wcpdf_documents_settings_invoice', array() );
        $packing = get_option( 'wpo_wcpdf_documents_settings_packing-slip', array() );

        if ( ! is_array( $general ) ) {
            return 'No settings from the old plugin were found.';
        }

        $text = static function ( $value ) {
            if ( is_array( $value ) ) {
                $value = isset( $value['default'] ) ? $value['default'] : reset( $value );
            }

            return trim( wp_strip_all_tags( (string) $value ) );
        };

        $new = array();

        if ( $text( isset( $general['shop_name'] ) ? $general['shop_name'] : '' ) ) {
            $new['shop_name'] = $text( $general['shop_name'] );
        }

        $address = array();

        foreach ( array( 'shop_address_line_1', 'shop_address_line_2', 'shop_address_city', 'shop_address_postcode', 'shop_address_state', 'shop_address_additional' ) as $key ) {
            if ( isset( $general[ $key ] ) && $text( $general[ $key ] ) ) {
                $address[] = $text( $general[ $key ] );
            }
        }

        if ( ! $address && isset( $general['shop_address'] ) ) {
            $address = array( $text( $general['shop_address'] ) );
        }

        if ( $address ) {
            $new['shop_address'] = implode( "\n", $address );
        }

        $map = array(
            'shop_phone_number'  => 'shop_phone',
            'shop_email_address' => 'shop_email',
            'footer'             => 'footer',
            'extra_1'            => 'shop_extra',
        );

        foreach ( $map as $old => $key ) {
            if ( isset( $general[ $old ] ) && $text( $general[ $old ] ) ) {
                $new[ $key ] = sanitize_textarea_field( $text( $general[ $old ] ) );
            }
        }

        if ( ! empty( $general['vat_number'] ) && $text( $general['vat_number'] ) ) {
            $new['tax_id'] = $text( $general['vat_number'] );
        }

        if ( ! empty( $general['header_logo'] ) ) {
            $logo = is_array( $general['header_logo'] ) ? reset( $general['header_logo'] ) : $general['header_logo'];
            $new['logo_id'] = absint( $logo );
        }

        if ( ! empty( $general['paper_size'] ) ) {
            $new['paper_size'] = 'letter' === strtolower( $general['paper_size'] ) ? 'Letter' : 'A4';
        }

        if ( is_array( $invoice ) ) {
            if ( ! empty( $invoice['number_format'] ) && is_array( $invoice['number_format'] ) ) {
                $new['number_prefix']  = sanitize_text_field( isset( $invoice['number_format']['prefix'] ) ? $invoice['number_format']['prefix'] : '' );
                $new['number_suffix']  = sanitize_text_field( isset( $invoice['number_format']['suffix'] ) ? $invoice['number_format']['suffix'] : '' );
                $new['number_padding'] = absint( isset( $invoice['number_format']['padding'] ) ? $invoice['number_format']['padding'] : 0 );
            }

            $new['reset_yearly']       = ! empty( $invoice['reset_number_yearly'] ) ? 'yes' : 'no';
            $new['show_email']         = ! empty( $invoice['display_email'] ) ? 'yes' : 'no';
            $new['show_phone']         = ! empty( $invoice['display_phone'] ) ? 'yes' : 'no';
            $new['show_customer_note'] = ! empty( $invoice['display_customer_notes'] ) ? 'yes' : 'no';
            $new['disable_free']       = ! empty( $invoice['disable_free'] ) ? 'yes' : 'no';

            if ( isset( $invoice['display_shipping_address'] ) ) {
                $new['show_shipping_address'] = in_array( $invoice['display_shipping_address'], array( 'always', 'when_different' ), true ) ? $invoice['display_shipping_address'] : 'always';
            }

            if ( ! empty( $invoice['attach_to_email_ids'] ) && is_array( $invoice['attach_to_email_ids'] ) ) {
                $new['attach_invoice'] = array_values( array_unique( array_merge( (array) RAR_WOW_Documents::setting( 'attach_invoice' ), array_keys( array_filter( $invoice['attach_to_email_ids'] ) ) ) ) );
            }

            if ( isset( $invoice['display_date'] ) && 'order_date' === $invoice['display_date'] ) {
                $new['invoice_date_source'] = 'order';
            }

            if ( isset( $invoice['enabled'] ) ) {
                $new['invoice_enabled'] = $invoice['enabled'] ? 'yes' : 'no';
            }
        }

        if ( is_array( $packing ) && ! empty( $packing['attach_to_email_ids'] ) && is_array( $packing['attach_to_email_ids'] ) ) {
            $new['attach_packing'] = array_keys( array_filter( $packing['attach_to_email_ids'] ) );
        }

        $old_reset = RAR_WOW_Documents::setting( 'reset_yearly' );

        if ( isset( $new['reset_yearly'] ) && 'yes' === $new['reset_yearly'] ) {
            $prefix = isset( $new['number_prefix'] ) ? $new['number_prefix'] : RAR_WOW_Documents::setting( 'number_prefix' );
            $suffix = isset( $new['number_suffix'] ) ? $new['number_suffix'] : RAR_WOW_Documents::setting( 'number_suffix' );

            if ( ! RAR_WOW_Documents::has_year_placeholder( $prefix, $suffix ) ) {
                $new['reset_yearly'] = 'no';
            }
        }

        update_option( RAR_WOW_Documents::OPTION, wp_parse_args( $new, RAR_WOW_Documents::settings() ), false );
        RAR_WOW_Documents::flush_settings_cache();

        if ( isset( $new['reset_yearly'] ) ) {
            RAR_WOW_Documents::protect_series_switch( $old_reset, $new['reset_yearly'] );
        }

        return sprintf( 'Imported %d setting(s) from the old PDF plugin. Review the Documents and Invoice Numbers tabs.', count( $new ) );
    }

    /**
     * Orders carrying an old-plugin invoice number (works for HPOS and legacy post storage).
     *
     * Keyset pagination (id > $after) because imported orders drop out of the result set.
     *
     * @param int  $after Only orders with a higher ID.
     * @param int  $limit Limit (0 = none).
     * @param bool $count Return a count instead of ids.
     * @return int|int[]
     */
    private function legacy_order_ids( $after, $limit, $count = false ) {
        global $wpdb;

        $hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ( $hpos ) {
            $meta   = $wpdb->prefix . 'wc_orders_meta';
            $orders = $wpdb->prefix . 'wc_orders';
            $from   = "FROM {$meta} m INNER JOIN {$orders} o ON o.id = m.order_id WHERE m.meta_key = '_wcpdf_invoice_number' AND m.meta_value <> '' AND o.type = 'shop_order' AND NOT EXISTS (SELECT 1 FROM {$meta} r WHERE r.order_id = m.order_id AND r.meta_key = '_rar_wow_invoice_number')";
            $col    = 'm.order_id';
        } else {
            $from = "FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_wcpdf_invoice_number' AND m.meta_value <> '' AND p.post_type = 'shop_order' AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} r WHERE r.post_id = m.post_id AND r.meta_key = '_rar_wow_invoice_number')";
            $col  = 'm.post_id';
        }

        // phpcs:disable WordPress.DB.PreparedSQL -- static identifiers only.
        if ( $count ) {
            return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT {$col}) {$from}" );
        }

        $sql = "SELECT DISTINCT {$col} AS id {$from}" . $wpdb->prepare( " AND {$col} > %d", absint( $after ) ) . " ORDER BY {$col} ASC";

        if ( $limit ) {
            $sql .= $wpdb->prepare( ' LIMIT %d', $limit );
        }

        return array_map( 'absint', $wpdb->get_col( $sql ) );
        // phpcs:enable
    }

    /**
     * @return array{imported:int,more:bool}
     */
    private function import_legacy_numbers( $after, $batch ) {
        $ids = $this->legacy_order_ids( $after, $batch );

        $imported = 0;

        foreach ( $ids as $id ) {
            $order = wc_get_order( $id );

            if ( ! $order || $order->get_meta( RAR_WOW_Documents::META_NUMBER, true ) ) {
                continue;
            }

            if ( true === RAR_WOW_Documents::adopt_legacy( $order ) ) {
                $imported++;
            }
        }

        return array(
            'imported' => $imported,
            'more'     => count( $ids ) === $batch,
            'last'     => $ids ? max( $ids ) : $after,
        );
    }

    /**
     * Set the RAR floor so numbering continues after the highest legacy number.
     */
    private function continue_after_legacy() {
        global $wpdb;

        $table  = RAR_WOW_Documents::table();
        $series = RAR_WOW_Documents::series_for( time() );

        if ( 'yes' === RAR_WOW_Documents::setting( 'reset_yearly' ) ) {
            $legacy_series = 'wcpdf-' . wp_date( 'Y' );
            $legacy_max    = absint( $wpdb->get_var( $wpdb->prepare( "SELECT MAX(number) FROM {$table} WHERE series = %s", $legacy_series ) ) ); // phpcs:ignore
        } else {
            $legacy_max = absint( $wpdb->get_var( "SELECT MAX(number) FROM {$table} WHERE series LIKE 'wcpdf%'" ) ); // phpcs:ignore
        }

        $stats = $this->legacy_stats();
        $floor = max( $legacy_max + 1, $stats['next'], RAR_WOW_Documents::next_number( $series ) );

        RAR_WOW_Documents::set_floor( $series, $floor );

        return $floor;
    }
}
