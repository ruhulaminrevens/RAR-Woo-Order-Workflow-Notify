<?php
/**
 * Invoice Register (accounting view + CSV export) and the dashboard "Order Workflow Pulse" widget.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RAR_WOW_Register {

    /** @var RAR_WOW_Register|null */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ), 91 );
        add_action( 'admin_post_rar_wow_register_csv', array( $this, 'export_csv' ) );
        add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
    }

    public function menu() {
        add_submenu_page(
            'woocommerce',
            'Invoice Register',
            'Invoice Register',
            'manage_woocommerce',
            'rar-wow-register',
            array( $this, 'render' )
        );
    }

    /**
     * Normalised filters from the request.
     */
    private function filters() {
        $from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : wp_date( 'Y-m-01' ); // phpcs:ignore
        $to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : wp_date( 'Y-m-d' ); // phpcs:ignore

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
            $from = wp_date( 'Y-m-01' );
        }

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
            $to = wp_date( 'Y-m-d' );
        }

        $status = isset( $_GET['reg_status'] ) ? sanitize_key( wp_unslash( $_GET['reg_status'] ) ) : 'issued'; // phpcs:ignore
        $status = in_array( $status, array( 'issued', 'void', 'all' ), true ) ? $status : 'issued';

        return array(
            'from'   => $from,
            'to'     => $to,
            'status' => $status,
            'search' => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore
            'paged'  => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1, // phpcs:ignore
        );
    }

    private function where( $f ) {
        global $wpdb;

        $where = $wpdb->prepare( 'invoice_date BETWEEN %s AND %s', $f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59' );

        if ( 'all' !== $f['status'] ) {
            $where .= $wpdb->prepare( ' AND status = %s', $f['status'] );
        }

        if ( '' !== $f['search'] ) {
            $like   = '%' . $wpdb->esc_like( $f['search'] ) . '%';
            $where .= $wpdb->prepare( ' AND (formatted_number LIKE %s OR customer LIKE %s OR phone LIKE %s OR order_id = %d)', $like, $like, $like, absint( $f['search'] ) );
        }

        return $where;
    }

    public function render() {
        global $wpdb;

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $f        = $this->filters();
        $table    = RAR_WOW_Documents::table();
        $where    = $this->where( $f );
        $per_page = 50;
        $offset   = ( $f['paged'] - 1 ) * $per_page;

        // phpcs:disable WordPress.DB.PreparedSQL
        $total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
        // KPIs never include voided numbers unless the user is explicitly looking at voids.
        $kpi_where  = 'all' === $f['status'] ? $where . " AND status = 'issued'" : $where;
        $sums       = $wpdb->get_row( "SELECT COUNT(*) AS n, SUM(subtotal) AS subtotal, SUM(discount) AS discount, SUM(shipping) AS shipping, SUM(fees) AS fees, SUM(tax) AS tax, SUM(total) AS total, SUM(refunded) AS refunded, SUM(advance) AS advance FROM {$table} WHERE {$kpi_where}", ARRAY_A );
        $rows       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY invoice_date DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
        $by_status  = $wpdb->get_results( "SELECT order_status, COUNT(*) AS n, SUM(total) AS total FROM {$table} WHERE {$kpi_where} GROUP BY order_status ORDER BY total DESC", ARRAY_A );
        // phpcs:enable

        $currency = get_woocommerce_currency();
        $money    = static function ( $v ) use ( $currency ) {
            return html_entity_decode( wp_strip_all_tags( wc_price( (float) $v, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
        };

        $net   = (float) $sums['total'] - (float) $sums['refunded'];
        $pages = max( 1, (int) ceil( $total_rows / $per_page ) );
        $csv   = wp_nonce_url(
            add_query_arg(
                array(
                    'action'     => 'rar_wow_register_csv',
                    'from'       => $f['from'],
                    'to'         => $f['to'],
                    'reg_status' => $f['status'],
                    's'          => $f['search'],
                ),
                admin_url( 'admin-post.php' )
            ),
            'rar_wow_register_csv'
        );
        ?>
        <div class="wrap rar-wow-settings rar-wow-register">
            <h1 class="wp-heading-inline">Invoice Register</h1>
            <a href="<?php echo esc_url( $csv ); ?>" class="page-title-action">Export CSV (Excel/Tally)</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=rar-wow-workflow&tab=numbering' ) ); ?>" class="page-title-action">Numbering settings</a>
            <p class="description">Accounting snapshot of every issued invoice number. Amounts refresh when the order status changes, a refund is made, or the order is saved. Voided numbers stay listed for audit — numbers are never reused.</p>

            <form method="get" class="rar-wow-register-filters">
                <input type="hidden" name="page" value="rar-wow-register">
                <label>From <input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>"></label>
                <label>To <input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>"></label>
                <select name="reg_status">
                    <option value="issued" <?php selected( $f['status'], 'issued' ); ?>>Issued</option>
                    <option value="void" <?php selected( $f['status'], 'void' ); ?>>Voided</option>
                    <option value="all" <?php selected( $f['status'], 'all' ); ?>>All</option>
                </select>
                <input type="search" name="s" value="<?php echo esc_attr( $f['search'] ); ?>" placeholder="Invoice no., customer, phone, order ID">
                <button class="button">Filter</button>
                <span class="rar-wow-quick">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=rar-wow-register&from=' . wp_date( 'Y-m-d' ) . '&to=' . wp_date( 'Y-m-d' ) ) ); ?>">Today</a> ·
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=rar-wow-register&from=' . wp_date( 'Y-m-01' ) . '&to=' . wp_date( 'Y-m-d' ) ) ); ?>">This month</a> ·
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=rar-wow-register&from=' . wp_date( 'Y-m-01', strtotime( 'first day of last month' ) ) . '&to=' . wp_date( 'Y-m-t', strtotime( 'first day of last month' ) ) ) ); ?>">Last month</a> ·
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=rar-wow-register&from=' . wp_date( 'Y-01-01' ) . '&to=' . wp_date( 'Y-m-d' ) ) ); ?>">This year</a>
                </span>
            </form>

            <div class="rar-wow-kpis">
                <div class="rar-wow-kpi"><span><?php echo 'void' === $f['status'] ? 'Voided numbers' : 'Invoices issued'; ?></span><strong><?php echo esc_html( number_format_i18n( (int) $sums['n'] ) ); ?></strong></div>
                <div class="rar-wow-kpi"><span>Gross invoiced</span><strong><?php echo esc_html( $money( $sums['total'] ) ); ?></strong></div>
                <div class="rar-wow-kpi"><span>Refunded</span><strong><?php echo esc_html( $money( $sums['refunded'] ) ); ?></strong></div>
                <div class="rar-wow-kpi is-accent"><span>Net invoiced</span><strong><?php echo esc_html( $money( $net ) ); ?></strong></div>
                <div class="rar-wow-kpi"><span>Discounts</span><strong><?php echo esc_html( $money( $sums['discount'] ) ); ?></strong></div>
                <div class="rar-wow-kpi"><span>Shipping charged</span><strong><?php echo esc_html( $money( $sums['shipping'] ) ); ?></strong></div>
                <div class="rar-wow-kpi"><span>Advance received</span><strong><?php echo esc_html( $money( $sums['advance'] ) ); ?></strong></div>
            </div>

            <?php if ( $by_status ) : ?>
                <p class="rar-wow-status-split">
                    <strong>By current order status:</strong>
                    <?php foreach ( $by_status as $row ) : ?>
                        <span class="rar-wow-chip"><?php echo esc_html( wc_get_order_status_name( $row['order_status'] ) ); ?> · <?php echo esc_html( number_format_i18n( (int) $row['n'] ) ); ?> · <?php echo esc_html( $money( $row['total'] ) ); ?></span>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>

            <table class="widefat striped rar-wow-register-table">
                <thead>
                    <tr>
                        <th>Invoice No.</th><th>Invoice date</th><th>Order</th><th>Customer</th><th>Phone</th><th>City</th>
                        <th>Payment</th><th>Order status</th><th class="num">Subtotal</th><th class="num">Discount</th><th class="num">Shipping</th>
                        <th class="num">Total</th><th class="num">Refunded</th><th class="num">Advance</th><th>Docs</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( ! $rows ) : ?>
                    <tr><td colspan="15">No invoices in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ( $rows as $row ) : ?>
                    <tr class="<?php echo 'void' === $row['status'] ? 'is-void' : ''; ?>">
                        <td><strong><?php echo esc_html( $row['formatted_number'] ); ?></strong><?php echo 'void' === $row['status'] ? ' <span class="rar-wow-chip is-void">VOID</span>' : ''; ?><?php echo 'wcpdf' === $row['source'] ? ' <span class="rar-wow-chip" title="Imported from the old PDF plugin">legacy</span>' : ''; ?></td>
                        <td><?php echo esc_html( mysql2date( 'd M Y', $row['invoice_date'] ) ); ?></td>
                        <td><a href="<?php echo esc_url( $this->edit_url( (int) $row['order_id'] ) ); ?>">#<?php echo esc_html( $row['order_id'] ); ?></a></td>
                        <td><?php echo esc_html( $row['customer'] ); ?></td>
                        <td><?php echo esc_html( $row['phone'] ); ?></td>
                        <td><?php echo esc_html( $row['city'] ); ?></td>
                        <td><?php echo esc_html( $row['payment_method'] ); ?></td>
                        <td><?php echo esc_html( $row['order_status'] ? wc_get_order_status_name( $row['order_status'] ) : '—' ); ?></td>
                        <td class="num"><?php echo esc_html( $money( $row['subtotal'] ) ); ?></td>
                        <td class="num"><?php echo esc_html( $money( $row['discount'] ) ); ?></td>
                        <td class="num"><?php echo esc_html( $money( $row['shipping'] ) ); ?></td>
                        <td class="num"><strong><?php echo esc_html( $money( $row['total'] ) ); ?></strong></td>
                        <td class="num"><?php echo esc_html( $money( $row['refunded'] ) ); ?></td>
                        <td class="num"><?php echo esc_html( $money( $row['advance'] ) ); ?></td>
                        <td>
                            <?php if ( 'issued' === $row['status'] ) : ?>
                                <a target="_blank" rel="noopener" href="<?php echo esc_url( RAR_WOW_Documents::admin_url_for( array( (int) $row['order_id'] ), 'invoice' ) ); ?>">PDF</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $pages > 1 ) : ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php
                    echo wp_kses_post(
                        paginate_links(
                            array(
                                'base'    => add_query_arg( 'paged', '%#%' ),
                                'format'  => '',
                                'current' => $f['paged'],
                                'total'   => $pages,
                            )
                        )
                    );
                    ?>
                </div></div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function edit_url( $order_id ) {
        $order = wc_get_order( $order_id );

        return $order ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
    }

    public function export_csv() {
        global $wpdb;

        check_admin_referer( 'rar_wow_register_csv' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'rar-woo-order-workflow-notify' ) );
        }

        $f     = $this->filters();
        $table = RAR_WOW_Documents::table();
        $where = $this->where( $f );
        $rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY series ASC, number ASC", ARRAY_A ); // phpcs:ignore

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="invoice-register-' . $f['from'] . '-to-' . $f['to'] . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel shows Bangla correctly.

        fputcsv( $out, array( 'Invoice No', 'Series', 'Sequence', 'Invoice Date', 'Order ID', 'Customer', 'Phone', 'City', 'Payment Method', 'Order Status', 'Currency', 'Subtotal', 'Discount', 'Shipping', 'Fees', 'Tax', 'Total', 'Refunded', 'Net Total', 'Advance Received', 'Register Status', 'Source' ), ',', '"', '' );

        foreach ( $rows as $r ) {
            fputcsv(
                $out,
                array(
                    $this->csv_safe( $r['formatted_number'] ),
                    $r['series'],
                    $r['number'],
                    $r['invoice_date'],
                    $r['order_id'],
                    $this->csv_safe( $r['customer'] ),
                    $this->csv_safe( $r['phone'] ),
                    $this->csv_safe( $r['city'] ),
                    $this->csv_safe( $r['payment_method'] ),
                    $r['order_status'],
                    $r['currency'],
                    $r['subtotal'],
                    $r['discount'],
                    $r['shipping'],
                    $r['fees'],
                    $r['tax'],
                    $r['total'],
                    $r['refunded'],
                    wc_format_decimal( (float) $r['total'] - (float) $r['refunded'], 2 ),
                    $r['advance'],
                    $r['status'],
                    $r['source'],
                ),
                ',',
                '"',
                ''
            );
        }

        fclose( $out ); // phpcs:ignore
        exit;
    }

    /**
     * Prevent CSV formula injection when opened in Excel.
     */
    private function csv_safe( $value ) {
        $value = (string) $value;

        if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ) {
            return "'" . $value;
        }

        return $value;
    }

    /* ---------------------------------------------------------------------
     * Dashboard widget
     * ------------------------------------------------------------------ */

    public function dashboard_widget() {
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            return;
        }

        wp_add_dashboard_widget( 'rar_wow_pulse', 'Order Workflow Pulse — RAR', array( $this, 'render_widget' ) );
    }

    private function orders_list_url( $status ) {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
            return admin_url( 'admin.php?page=wc-orders&status=wc-' . $status );
        }

        return admin_url( 'edit.php?post_type=shop_order&post_status=wc-' . $status );
    }

    public function render_widget() {
        global $wpdb;

        $stages = array(
            'on-hold'    => 'On hold',
            'processing' => 'To confirm',
            'confirmed'  => 'To pack & ship',
            'shipped'    => 'In transit',
            'returned'   => 'Returned',
        );

        $table = RAR_WOW_Documents::table();
        $today = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, SUM(total) AS total FROM {$table} WHERE status = 'issued' AND invoice_date >= %s", wp_date( 'Y-m-d 00:00:00' ) ), ARRAY_A ); // phpcs:ignore
        $month = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, SUM(total) AS total FROM {$table} WHERE status = 'issued' AND invoice_date >= %s", wp_date( 'Y-m-01 00:00:00' ) ), ARRAY_A ); // phpcs:ignore
        $money = static function ( $v ) {
            return html_entity_decode( wp_strip_all_tags( wc_price( (float) $v ) ), ENT_QUOTES, 'UTF-8' );
        };

        $confirmed_ids = wc_get_orders( array( 'status' => 'confirmed', 'limit' => 100, 'return' => 'ids', 'type' => 'shop_order' ) );
        ?>
        <div class="rar-wow-pulse">
            <div class="rar-wow-pulse-grid">
                <?php foreach ( $stages as $status => $label ) : ?>
                    <a class="rar-wow-pulse-cell rar-wow-pulse-<?php echo esc_attr( $status ); ?>" href="<?php echo esc_url( $this->orders_list_url( $status ) ); ?>">
                        <strong><?php echo esc_html( number_format_i18n( (int) wc_orders_count( $status ) ) ); ?></strong>
                        <span><?php echo esc_html( $label ); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <p class="rar-wow-pulse-inv">
                Invoices today: <strong><?php echo esc_html( (int) $today['n'] ); ?></strong> (<?php echo esc_html( $money( $today['total'] ) ); ?>)
                · This month: <strong><?php echo esc_html( (int) $month['n'] ); ?></strong> (<?php echo esc_html( $money( $month['total'] ) ); ?>)
            </p>
            <?php if ( $confirmed_ids ) : ?>
                <p class="rar-wow-pulse-actions">
                    <strong><?php echo esc_html( count( $confirmed_ids ) ); ?> confirmed order(s) waiting for dispatch:</strong><br>
                    <a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( RAR_WOW_Documents::admin_url_for( $confirmed_ids, 'packing-slip' ) ); ?>">Print all packing slips</a>
                    <a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( RAR_WOW_Documents::admin_url_for( $confirmed_ids, 'label' ) ); ?>">Print all delivery labels</a>
                </p>
            <?php endif; ?>
            <p class="rar-wow-pulse-links">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=rar-wow-register' ) ); ?>">Invoice Register</a> ·
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=rar-wow-workflow' ) ); ?>">Workflow settings</a>
            </p>
        </div>
        <?php
    }
}
