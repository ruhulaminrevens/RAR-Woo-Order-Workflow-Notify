<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RAR_WOW_Plugin {
    private static $instance = null;

    /** @var array<string,bool> */
    private $mail_guard = array();

    /** @var array<int,string> Manual action context; prevents checkout-time Processing alerts. */
    private $manual_action_context = array();

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', array( $this, 'register_statuses' ) );
        add_filter( 'wc_order_statuses', array( $this, 'inject_statuses' ) );

        add_filter( 'woocommerce_admin_order_actions', array( $this, 'add_order_actions' ), 20, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_post_rar_wow_change_status', array( $this, 'handle_status_action' ) );

        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_changed' ), 20, 4 );
        add_filter( 'woocommerce_email_enabled_cancelled_order', array( $this, 'maybe_disable_core_cancelled_email' ), 20, 3 );
        add_filter( 'woocommerce_email_enabled_customer_completed_order', array( $this, 'maybe_disable_core_completed_email' ), 20, 3 );

        add_action( 'admin_menu', array( $this, 'add_settings_page' ), 90 );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( RAR_WOW_FILE ), array( $this, 'plugin_action_links' ) );
    }

    public function register_statuses() {
        $statuses = array(
            'wc-confirmed' => array(
                'label' => 'Confirmed',
                'label_count' => _n_noop(
                    'Confirmed <span class="count">(%s)</span>',
                    'Confirmed <span class="count">(%s)</span>',
                    'rar-woo-order-workflow-notify'
                ),
            ),
            'wc-shipped' => array(
                'label' => 'Shipped',
                'label_count' => _n_noop(
                    'Shipped <span class="count">(%s)</span>',
                    'Shipped <span class="count">(%s)</span>',
                    'rar-woo-order-workflow-notify'
                ),
            ),
            'wc-returned' => array(
                'label' => 'Returned',
                'label_count' => _n_noop(
                    'Returned <span class="count">(%s)</span>',
                    'Returned <span class="count">(%s)</span>',
                    'rar-woo-order-workflow-notify'
                ),
            ),
        );

        foreach ( $statuses as $slug => $args ) {
            register_post_status(
                $slug,
                array(
                    'label'                     => $args['label'],
                    'public'                    => false,
                    'exclude_from_search'       => false,
                    'show_in_admin_all_list'    => true,
                    'show_in_admin_status_list' => true,
                    'label_count'               => $args['label_count'],
                )
            );
        }
    }

    public function inject_statuses( $statuses ) {
        $new = array();

        foreach ( $statuses as $key => $label ) {
            $new[ $key ] = $label;

            if ( 'wc-processing' === $key ) {
                $new['wc-confirmed'] = 'Confirmed';
                $new['wc-shipped']   = 'Shipped';
            }

            if ( 'wc-completed' === $key ) {
                $new['wc-returned'] = 'Returned';
            }
        }

        return $new;
    }

    private function allowed_transitions() {
        return array(
            'pending'    => array( 'processing', 'cancelled' ),
            'on-hold'    => array( 'processing', 'cancelled' ),
            'failed'     => array( 'processing', 'cancelled' ),
            'processing' => array( 'confirmed', 'cancelled' ),
            'confirmed'  => array( 'shipped', 'cancelled' ),
            'shipped'    => array( 'completed', 'cancelled' ),
            'completed'  => array( 'returned' ),
            'cancelled'  => array( 'processing' ),
            'returned'   => array( 'processing' ),
        );
    }

    private function action_definitions() {
        return array(
            'processing' => array(
                'label' => 'Processing — admin alert',
                'class' => 'rar-wow-processing',
            ),
            'confirmed' => array(
                'label' => 'Confirmed — email customer',
                'class' => 'rar-wow-confirmed',
            ),
            'shipped' => array(
                'label' => 'Shipped — email customer',
                'class' => 'rar-wow-shipped',
            ),
            'completed' => array(
                'label' => 'Complete — thank customer & request review',
                'class' => 'rar-wow-completed',
            ),
            'cancelled' => array(
                'label' => 'Cancelled — notify customer & admin',
                'class' => 'rar-wow-cancelled',
            ),
            'returned' => array(
                'label' => 'Returned — notify customer & admin',
                'class' => 'rar-wow-returned',
            ),
        );
    }

    public function add_order_actions( $actions, $order ) {
        if ( ! $order instanceof WC_Order ) {
            return $actions;
        }

        $current = $order->get_status();
        $map     = $this->allowed_transitions();
        $defs    = $this->action_definitions();

        if ( empty( $map[ $current ] ) ) {
            return $actions;
        }

        foreach ( $map[ $current ] as $target ) {
            if ( empty( $defs[ $target ] ) ) {
                continue;
            }

            $url = wp_nonce_url(
                admin_url(
                    'admin-post.php?action=rar_wow_change_status&order_id=' .
                    absint( $order->get_id() ) .
                    '&status=' . rawurlencode( $target )
                ),
                'rar_wow_change_status_' . absint( $order->get_id() ) . '_' . $target,
                '_rar_wow_nonce'
            );

            $actions[ 'rar_wow_' . $target ] = array(
                'url'    => $url,
                'name'   => $defs[ $target ]['label'],
                'action' => $defs[ $target ]['class'],
            );
        }

        return $actions;
    }

    public function enqueue_admin_assets( $hook ) {
        if ( ! is_admin() ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $id     = $screen ? (string) $screen->id : '';

        if (
            false === strpos( $id, 'shop_order' ) &&
            false === strpos( $id, 'wc-orders' ) &&
            'woocommerce_page_rar-wow-workflow' !== $id
        ) {
            return;
        }

        wp_enqueue_style(
            'rar-wow-admin',
            RAR_WOW_URL . 'assets/admin.css',
            array(),
            RAR_WOW_VERSION
        );
    }

    public function handle_status_action() {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        $target   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
        $nonce    = isset( $_GET['_rar_wow_nonce'] )
            ? sanitize_text_field( wp_unslash( $_GET['_rar_wow_nonce'] ) )
            : '';

        if (
            ! $order_id ||
            ! $target ||
            ! wp_verify_nonce(
                $nonce,
                'rar_wow_change_status_' . $order_id . '_' . $target
            )
        ) {
            wp_die(
                esc_html__(
                    'Invalid or expired order action.',
                    'rar-woo-order-workflow-notify'
                )
            );
        }

        if (
            ! current_user_can( 'edit_shop_orders' ) &&
            ! current_user_can( 'edit_shop_order', $order_id ) &&
            ! current_user_can( 'edit_post', $order_id )
        ) {
            wp_die(
                esc_html__(
                    'You do not have permission to update this order.',
                    'rar-woo-order-workflow-notify'
                )
            );
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_die(
                esc_html__(
                    'Order not found.',
                    'rar-woo-order-workflow-notify'
                )
            );
        }

        $current = $order->get_status();
        $map     = $this->allowed_transitions();

        if (
            empty( $map[ $current ] ) ||
            ! in_array( $target, $map[ $current ], true )
        ) {
            wp_die(
                esc_html__(
                    'This status transition is not allowed from the current order state.',
                    'rar-woo-order-workflow-notify'
                )
            );
        }

        $labels = wc_get_order_statuses();
        $label  = isset( $labels[ 'wc-' . $target ] )
            ? wp_strip_all_tags( $labels[ 'wc-' . $target ] )
            : ucfirst( $target );

        $this->manual_action_context[ $order_id ] = $target;

        $order->update_status(
            $target,
            sprintf(
                'RAR workflow: status changed from %s to %s by %s.',
                ucfirst( $current ),
                $label,
                wp_get_current_user()->display_name
            ),
            true
        );

        unset( $this->manual_action_context[ $order_id ] );

        $redirect = wp_get_referer();

        if ( ! $redirect ) {
            $redirect = admin_url( 'edit.php?post_type=shop_order' );
        }

        $redirect = add_query_arg(
            array( 'rar_wow_updated' => $target ),
            $redirect
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    public function handle_status_changed(
        $order_id,
        $old_status,
        $new_status,
        $order
    ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }

        if ( ! $order ) {
            return;
        }

        $key = absint( $order_id ) . ':' . sanitize_key( $new_status );

        if ( isset( $this->mail_guard[ $key ] ) ) {
            return;
        }

        $this->mail_guard[ $key ] = true;

        switch ( $new_status ) {
            case 'processing':
                /*
                 * New COD orders normally enter Processing during checkout.
                 * Do not add another synchronous mail to the Place Order request.
                 * This admin alert runs only for an explicit RAR recovery action,
                 * e.g. Cancelled → Processing.
                 */
                if (
                    isset( $this->manual_action_context[ $order_id ] ) &&
                    'processing' === $this->manual_action_context[ $order_id ]
                ) {
                    $this->send_admin_workflow_email(
                        $order,
                        'Processing',
                        'Order moved to Processing',
                        'এই order এখন Processing status-এ আছে। প্রয়োজনীয় verification, stock check ও fulfilment প্রস্তুতি করুন।'
                    );
                }
                break;

            case 'confirmed':
                $this->send_customer_email( $order, 'confirmed' );
                break;

            case 'shipped':
                $this->send_customer_email( $order, 'shipped' );
                break;

            case 'completed':
                $this->send_customer_email( $order, 'completed' );
                break;

            case 'cancelled':
                $this->send_customer_email( $order, 'cancelled' );
                $this->send_admin_workflow_email(
                    $order,
                    'Cancelled',
                    'Order cancelled',
                    'Orderটি Cancelled হয়েছে। Customer notification-ও পাঠানো হয়েছে। প্রয়োজন হলে reason/stock/payment follow-up করুন।'
                );
                break;

            case 'returned':
                $this->send_customer_email( $order, 'returned' );
                $this->send_admin_workflow_email(
                    $order,
                    'Returned',
                    'Return update requires attention',
                    'Orderটি Returned হিসেবে update হয়েছে। Parcel condition, courier handover, replacement/refund/settlement review করুন।'
                );
                break;
        }
    }

    private function settings() {
        return wp_parse_args(
            (array) get_option( 'rar_wow_settings', array() ),
            array(
                'admin_email'     => '',
                'brand_name'      => 'Nabiad',
                'support_text'    => 'Need help? Reply to this email and our team will assist you.',
                'customer_emails' => 'yes',
                'admin_alerts'    => 'yes',
            )
        );
    }

    private function admin_recipient() {
        $settings = $this->settings();

        if (
            ! empty( $settings['admin_email'] ) &&
            is_email( $settings['admin_email'] )
        ) {
            return sanitize_email( $settings['admin_email'] );
        }

        if (
            function_exists( 'WC' ) &&
            WC() &&
            WC()->mailer()
        ) {
            $emails = WC()->mailer()->get_emails();

            if (
                isset( $emails['WC_Email_New_Order'] ) &&
                method_exists(
                    $emails['WC_Email_New_Order'],
                    'get_recipient'
                )
            ) {
                $recipient = (string) $emails['WC_Email_New_Order']->get_recipient();
                $valid     = array();

                foreach (
                    array_filter(
                        array_map(
                            'trim',
                            explode( ',', $recipient )
                        )
                    ) as $address
                ) {
                    if ( is_email( $address ) ) {
                        $valid[] = sanitize_email( $address );
                    }
                }

                if ( $valid ) {
                    return implode(
                        ',',
                        array_unique( $valid )
                    );
                }
            }
        }

        return sanitize_email( get_option( 'admin_email' ) );
    }

    private function send_admin_workflow_email(
        WC_Order $order,
        $status_label,
        $heading,
        $message
    ) {
        $settings = $this->settings();

        if ( 'yes' !== $settings['admin_alerts'] ) {
            return;
        }

        $to = $this->admin_recipient();

        if ( ! $to ) {
            $order->add_order_note(
                'RAR workflow admin email skipped: no valid admin recipient.'
            );
            return;
        }

        $subject = sprintf(
            '[%s] Order #%s %s',
            $settings['brand_name'],
            $order->get_order_number(),
            $status_label
        );

        $body = $this->render_email(
            $order,
            'admin',
            array(
                'heading'       => $heading,
                'intro'         => $message,
                'status_label'  => $status_label,
                'show_progress' => false,
                'show_reviews'  => false,
            )
        );

        $sent = wc_mail(
            $to,
            $subject,
            $body,
            array( 'Content-Type: text/html; charset=UTF-8' )
        );

        $order->add_order_note(
            $sent
                ? sprintf(
                    'RAR workflow admin alert accepted by the mailer for %s — recipient: %s.',
                    $status_label,
                    $to
                )
                : sprintf(
                    'RAR workflow admin alert FAILED for %s (%s).',
                    $status_label,
                    $to
                )
        );

        if ( ! $sent ) {
            wc_get_logger()->error(
                'Admin workflow email failed.',
                array(
                    'source'    => 'rar-wow',
                    'order_id'  => $order->get_id(),
                    'status'    => $status_label,
                    'recipient' => $to,
                )
            );
        }
    }

    private function send_customer_email( WC_Order $order, $type ) {
        $settings = $this->settings();

        if ( 'yes' !== $settings['customer_emails'] ) {
            return;
        }

        $to = sanitize_email( $order->get_billing_email() );

        if ( ! $to ) {
            $order->add_order_note(
                'RAR workflow customer email skipped: billing email is empty/invalid.'
            );
            return;
        }

        $config = $this->customer_email_config( $type, $order );

        if ( empty( $config ) ) {
            return;
        }

        $subject = sprintf(
            '[%s] %s',
            $settings['brand_name'],
            $config['subject']
        );

        $body = $this->render_email(
            $order,
            'customer',
            $config
        );

        $sent = wc_mail(
            $to,
            $subject,
            $body,
            array( 'Content-Type: text/html; charset=UTF-8' )
        );

        $order->add_order_note(
            $sent
                ? sprintf(
                    'RAR workflow customer email accepted by the mailer for status: %s.',
                    ucfirst( $type )
                )
                : sprintf(
                    'RAR workflow customer email FAILED for status: %s.',
                    ucfirst( $type )
                )
        );

        if ( ! $sent ) {
            wc_get_logger()->error(
                'Customer workflow email failed.',
                array(
                    'source'    => 'rar-wow',
                    'order_id'  => $order->get_id(),
                    'status'    => $type,
                    'recipient' => $to,
                )
            );
        }
    }

    private function customer_email_config( $type, WC_Order $order ) {
        $number = $order->get_order_number();

        switch ( $type ) {
            case 'confirmed':
                return array(
                    'subject'       => sprintf(
                        'Order #%s Confirmed ✅',
                        $number
                    ),
                    'heading'       => 'Order Confirmed ✅',
                    'intro'         => 'আপনার order confirm করা হয়েছে। Thank you for choosing Nabiad! আমরা এখন আপনার পণ্য carefully prepare করছি। খুব শিগগিরই courier-এর কাছে handover করা হবে।',
                    'status_label'  => 'Confirmed',
                    'show_progress' => true,
                    'show_reviews'  => false,
                );

            case 'shipped':
                return array(
                    'subject'       => sprintf(
                        'Order #%s Shipped 🚚',
                        $number
                    ),
                    'heading'       => 'Your Order Is On The Way 🚚',
                    'intro'         => 'Good news! আপনার order courier-এর কাছে handover করা হয়েছে এবং এখন delivery journey-তে আছে। নিচে courier/tracking details থাকলে দেখতে পারবেন।',
                    'status_label'  => 'Shipped',
                    'show_progress' => true,
                    'show_reviews'  => false,
                );

            case 'completed':
                return array(
                    'subject'       => sprintf(
                        'Thank you! How was your Nabiad order #%s? ⭐',
                        $number
                    ),
                    'heading'       => 'Thank You for Shopping with Nabiad 💚',
                    'intro'         => 'আপনার order successfully complete হয়েছে। আপনার বিশ্বাসের জন্য আন্তরিক ধন্যবাদ। Product experience ভালো লাগলে একটি rating/review আমাদের এবং অন্য customers—দুজনকেই সঠিক product choose করতে সাহায্য করবে।',
                    'status_label'  => 'Completed',
                    'show_progress' => true,
                    'show_reviews'  => true,
                );

            case 'cancelled':
                return array(
                    'subject'       => sprintf(
                        'Order #%s has been Cancelled',
                        $number
                    ),
                    'heading'       => 'Order Cancelled',
                    'intro'         => 'আপনার order টি Cancelled হিসেবে update করা হয়েছে। যদি এটি unexpected হয়ে থাকে অথবা আপনি আবার order করতে চান, reply করে আমাদের support team-এর সাথে যোগাযোগ করতে পারেন। আমরা সাহায্য করতে প্রস্তুত।',
                    'status_label'  => 'Cancelled',
                    'show_progress' => false,
                    'show_reviews'  => false,
                    'notice'        => '<strong>Need this order again?</strong><br>Reply to this email and our team can help you place a fresh order.',
                );

            case 'returned':
                return array(
                    'subject'       => sprintf(
                        'Order #%s Return Update',
                        $number
                    ),
                    'heading'       => 'Return Update Received ↩️',
                    'intro'         => 'আপনার order টি Returned হিসেবে update করা হয়েছে। আমাদের team return status review করবে এবং প্রয়োজন হলে পরবর্তী step, replacement বা settlement বিষয়ে আপনার সাথে যোগাযোগ করবে।',
                    'status_label'  => 'Returned',
                    'show_progress' => false,
                    'show_reviews'  => false,
                    'notice'        => '<strong>Return support</strong><br>Return review/settlement timing may depend on parcel condition and courier handover. আমাদের team প্রয়োজনে আপনার সাথে যোগাযোগ করবে।',
                );
        }

        return array();
    }

    private function render_email(
        WC_Order $order,
        $audience,
        $config
    ) {
        $settings = $this->settings();
        $name     = trim( $order->get_billing_first_name() );

        if ( '' === $name ) {
            $name = 'Customer';
        }

        $courier  = $this->courier_label( $order );
        $tracking = $this->tracking_value( $order );
        $view_url = $order->get_user_id()
            ? $order->get_view_order_url()
            : $order->get_checkout_order_received_url();

        $items = $order->get_items();

        ob_start();
        ?>
        <!doctype html>
        <html>
        <body style="margin:0;padding:0;background:#f5f7f8;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;color:#252a2e;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7f8;padding:24px 10px;">
        <tr><td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e6eaed;border-radius:14px;overflow:hidden;">
            <tr>
                <td style="padding:24px 28px 12px;">
                    <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#0b8a62;font-weight:700;margin-bottom:8px;">
                        <?php echo esc_html( $settings['brand_name'] ); ?>
                    </div>

                    <h1 style="font-size:25px;line-height:1.25;margin:0 0 18px;color:#202529;">
                        <?php echo esc_html( $config['heading'] ); ?>
                    </h1>

                    <p style="margin:0 0 14px;font-size:15px;line-height:1.75;">
                        Dear <?php echo esc_html( $name ); ?>,
                    </p>

                    <p style="margin:0 0 18px;font-size:15px;line-height:1.75;">
                        <?php echo wp_kses_post( $config['intro'] ); ?>
                    </p>

                    <?php if ( ! empty( $config['show_progress'] ) ) : ?>
                        <?php
                        echo $this->render_progress(
                            strtolower( $config['status_label'] )
                        ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        ?>
                    <?php endif; ?>

                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;border:1px solid #dfe5e8;margin:18px 0;">
                        <tr>
                            <td style="padding:9px 10px;border-bottom:1px solid #e7ecef;font-weight:700;width:34%;">Order</td>
                            <td style="padding:9px 10px;border-bottom:1px solid #e7ecef;">#<?php echo esc_html( $order->get_order_number() ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding:9px 10px;border-bottom:1px solid #e7ecef;font-weight:700;">Status</td>
                            <td style="padding:9px 10px;border-bottom:1px solid #e7ecef;"><?php echo esc_html( $config['status_label'] ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding:9px 10px;border-bottom:1px solid #e7ecef;font-weight:700;">Total</td>
                            <td style="padding:9px 10px;border-bottom:1px solid #e7ecef;"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
                        </tr>
                        <tr>
                            <td style="padding:9px 10px;font-weight:700;">Courier</td>
                            <td style="padding:9px 10px;">
                                <?php echo esc_html( $courier ? $courier : '—' ); ?>
                                <?php
                                if ( $tracking ) {
                                    echo '<br><span style="color:#66717a;font-size:12px;">Tracking: ' .
                                        esc_html( $tracking ) .
                                        '</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    </table>

                    <?php
                    foreach ( $items as $item ) :
                        $product = $item->get_product();
                        $image   = $product
                            ? wp_get_attachment_image_url(
                                $product->get_image_id(),
                                'thumbnail'
                            )
                            : '';
                        $link = $product
                            ? get_permalink( $product->get_id() )
                            : '';
                        ?>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e4e9ec;border-radius:10px;margin:10px 0;">
                            <tr>
                                <td style="padding:12px;width:58px;vertical-align:middle;">
                                    <?php if ( $image ) : ?>
                                        <img
                                            src="<?php echo esc_url( $image ); ?>"
                                            width="52"
                                            height="52"
                                            alt=""
                                            style="display:block;object-fit:contain;border-radius:6px;"
                                        >
                                    <?php endif; ?>
                                </td>
                                <td style="padding:12px 12px 12px 4px;vertical-align:middle;font-size:14px;line-height:1.45;">
                                    <strong><?php echo esc_html( $item->get_name() ); ?></strong><br>
                                    <span style="font-size:12px;color:#68737c;">
                                        Qty: <?php echo esc_html( $item->get_quantity() ); ?>
                                    </span>

                                    <?php
                                    if (
                                        ! empty( $config['show_reviews'] ) &&
                                        $link
                                    ) :
                                        ?>
                                        <br>
                                        <span style="color:#ff9f0a;letter-spacing:1px;">★★★★★</span><br>
                                        <a
                                            href="<?php echo esc_url( $link . '#reviews' ); ?>"
                                            style="display:inline-block;margin-top:7px;background:#0b8a62;color:#fff;text-decoration:none;font-weight:700;padding:8px 12px;border-radius:6px;"
                                        >Rate &amp; Review / রিভিউ দিন</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    <?php endforeach; ?>

                    <?php if ( ! empty( $config['notice'] ) ) : ?>
                        <div style="margin:18px 0;padding:12px 14px;background:#fff7ec;border-left:4px solid #f28c28;font-size:13px;line-height:1.55;">
                            <?php echo wp_kses_post( $config['notice'] ); ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    if (
                        'customer' === $audience &&
                        $view_url
                    ) :
                        ?>
                        <p style="margin:18px 0;">
                            <a
                                href="<?php echo esc_url( $view_url ); ?>"
                                style="display:inline-block;background:#0b8a62;color:#fff;text-decoration:none;font-weight:700;padding:10px 15px;border-radius:7px;"
                            >View Order / অর্ডার দেখুন</a>
                        </p>
                    <?php endif; ?>

                    <p style="margin:18px 0 4px;font-size:12px;color:#68737c;line-height:1.65;">
                        <?php echo esc_html( $settings['support_text'] ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <td style="padding:14px 28px;background:#f8fafb;border-top:1px solid #e8edef;font-size:11px;color:#7b858d;">
                    Nabiad — Built with Nabiad Distribution Ltd.
                </td>
            </tr>
        </table>
        </td></tr>
        </table>
        </body>
        </html>
        <?php

        return (string) ob_get_clean();
    }

    private function render_progress( $current ) {
        $steps = array(
            'confirmed' => 'Confirmed',
            'shipped'   => 'Shipped',
            'completed' => 'Complete',
        );

        $order = array_keys( $steps );
        $idx   = array_search( $current, $order, true );

        if ( false === $idx ) {
            $idx = -1;
        }

        ob_start();
        ?>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:16px 0 8px;">
        <tr>
            <?php
            foreach ( $order as $i => $step ) :
                $done = $i <= $idx;
                ?>
                <td align="center" style="width:33.33%;padding:0 3px;">
                    <div style="height:3px;background:<?php echo $done ? '#0b8a62' : '#dce2e6'; ?>;margin-bottom:8px;"></div>
                    <div style="width:20px;height:20px;line-height:20px;border-radius:50%;margin:0 auto 5px;background:<?php echo $done ? '#0b8a62' : '#e1e6ea'; ?>;color:<?php echo $done ? '#fff' : '#89949d'; ?>;font-size:12px;font-weight:700;">
                        <?php echo $done ? '&#10003;' : '&bull;'; ?>
                    </div>
                    <div style="font-size:11px;color:#53606a;">
                        <?php echo esc_html( $steps[ $step ] ); ?>
                    </div>
                </td>
            <?php endforeach; ?>
        </tr>
        </table>
        <?php

        return (string) ob_get_clean();
    }

    private function courier_label( WC_Order $order ) {
        $courier = (string) $order->get_meta(
            '_nabiad_courier',
            true
        );

        if ( $courier ) {
            $labels = array(
                'pathao'    => 'Pathao',
                'redx'      => 'RedX',
                'paperfly'  => 'Paperfly',
                'steadfast' => 'Steadfast',
                'sundarban' => 'Sundarban',
            );

            $key = sanitize_key( $courier );

            return isset( $labels[ $key ] )
                ? $labels[ $key ]
                : ucwords(
                    str_replace(
                        array( '-', '_' ),
                        ' ',
                        $courier
                    )
                );
        }

        foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
            $name = trim(
                wp_strip_all_tags(
                    $shipping_item->get_name()
                )
            );

            if ( ! $name ) {
                continue;
            }

            foreach (
                array(
                    'Pathao',
                    'Paperfly',
                    'Steadfast',
                    'RedX',
                    'Sundarban',
                ) as $known
            ) {
                if ( false !== stripos( $name, $known ) ) {
                    return $known;
                }
            }

            return $name;
        }

        return '';
    }

    private function tracking_value( WC_Order $order ) {
        foreach (
            array(
                '_nabiad_tracking_id',
                '_redx_tracking_id',
                '_tracking_number',
            ) as $key
        ) {
            $value = trim(
                (string) $order->get_meta(
                    $key,
                    true
                )
            );

            if ( $value ) {
                return $value;
            }
        }

        return '';
    }

    public function maybe_disable_core_cancelled_email(
        $enabled,
        $order = null,
        $email = null
    ) {
        $settings = $this->settings();

        /*
         * RAR sends the immediate customer + admin Cancelled notifications.
         * Suppress WooCommerce's separate admin-only mail to prevent duplicates.
         */
        return 'yes' === $settings['admin_alerts']
            ? false
            : $enabled;
    }

    public function maybe_disable_core_completed_email(
        $enabled,
        $order = null,
        $email = null
    ) {
        $settings = $this->settings();

        /*
         * RAR's Completed email includes the custom Nabiad thank-you
         * and per-product review CTA. Keep one professional message.
         */
        return 'yes' === $settings['customer_emails']
            ? false
            : $enabled;
    }

    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            'RAR Order Workflow',
            'Order Workflow',
            'manage_woocommerce',
            'rar-wow-workflow',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'rar_wow_settings_group',
            'rar_wow_settings',
            array( $this, 'sanitize_settings' )
        );
    }

    public function sanitize_settings( $input ) {
        return array(
            'admin_email' => isset( $input['admin_email'] )
                ? sanitize_email( $input['admin_email'] )
                : '',
            'brand_name' => isset( $input['brand_name'] )
                ? sanitize_text_field( $input['brand_name'] )
                : 'Nabiad',
            'support_text' => isset( $input['support_text'] )
                ? sanitize_text_field( $input['support_text'] )
                : '',
            'customer_emails' => ! empty( $input['customer_emails'] )
                ? 'yes'
                : 'no',
            'admin_alerts' => ! empty( $input['admin_alerts'] )
                ? 'yes'
                : 'no',
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $settings = $this->settings();
        ?>
        <div class="wrap rar-wow-settings">
            <h1>RAR Woo Order Workflow &amp; Notify</h1>

            <p class="description">
                Production workflow: Processing → Confirmed → Shipped → Completed,
                with Cancelled/Returned recovery and transactional notifications.
            </p>

            <div class="rar-wow-card">
                <form method="post" action="options.php">
                    <?php settings_fields( 'rar_wow_settings_group' ); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="rar_wow_admin_email">
                                    Admin notification email
                                </label>
                            </th>
                            <td>
                                <input
                                    id="rar_wow_admin_email"
                                    name="rar_wow_settings[admin_email]"
                                    type="email"
                                    class="regular-text"
                                    value="<?php echo esc_attr( $settings['admin_email'] ); ?>"
                                >
                                <p class="description">
                                    Leave blank to automatically use
                                    WooCommerce → Emails → New order recipient.
                                    Recommended.
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="rar_wow_brand_name">Brand name</label>
                            </th>
                            <td>
                                <input
                                    id="rar_wow_brand_name"
                                    name="rar_wow_settings[brand_name]"
                                    type="text"
                                    class="regular-text"
                                    value="<?php echo esc_attr( $settings['brand_name'] ); ?>"
                                >
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="rar_wow_support_text">
                                    Email support line
                                </label>
                            </th>
                            <td>
                                <input
                                    id="rar_wow_support_text"
                                    name="rar_wow_settings[support_text]"
                                    type="text"
                                    class="large-text"
                                    value="<?php echo esc_attr( $settings['support_text'] ); ?>"
                                >
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Customer workflow emails</th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="rar_wow_settings[customer_emails]"
                                        value="1"
                                        <?php checked( $settings['customer_emails'], 'yes' ); ?>
                                    >
                                    Confirmed, Shipped, Completed, Cancelled and Returned
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Admin workflow alerts</th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="rar_wow_settings[admin_alerts]"
                                        value="1"
                                        <?php checked( $settings['admin_alerts'], 'yes' ); ?>
                                    >
                                    Manual Processing recovery, Cancelled and Returned
                                </label>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button( 'Save Workflow Settings' ); ?>
                </form>
            </div>

            <div class="rar-wow-card rar-wow-health">
                <h2>Performance &amp; delivery design</h2>
                <ul>
                    <li>
                        No extra RAR workflow email is sent during checkout/order
                        placement, so the plugin does not add another blocking SMTP
                        call to the Place Order request.
                    </li>
                    <li>
                        Manual workflow transition emails are sent immediately and
                        logged as order notes. Failed sends are also written to
                        WooCommerce logs with source <code>rar-wow</code>.
                    </li>
                    <li>
                        For checkout delays, review SMTP latency and PDF-invoice
                        attachment generation separately; those are outside this plugin.
                    </li>
                </ul>
            </div>
        </div>
        <?php
    }

    public function plugin_action_links( $links ) {
        array_unshift(
            $links,
            '<a href="' .
            esc_url(
                admin_url(
                    'admin.php?page=rar-wow-workflow'
                )
            ) .
            '">Settings</a>'
        );

        return $links;
    }
}
