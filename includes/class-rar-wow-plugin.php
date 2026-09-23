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

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'woocommerce_view_order', array( $this, 'render_customer_order_experience' ), 30 );

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

        $this->record_status_history( $order, $old_status, $new_status );

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


    public function enqueue_frontend_assets() {
        if ( is_admin() ) {
            return;
        }

        $should_load = false;

        if ( function_exists( 'is_account_page' ) && is_account_page() ) {
            $should_load = true;
        }

        if ( function_exists( 'is_page' ) && is_page( 'order-tracking' ) ) {
            $should_load = true;
        }

        global $post;

        if (
            ! $should_load &&
            $post instanceof WP_Post &&
            has_shortcode( (string) $post->post_content, 'woocommerce_order_tracking' )
        ) {
            $should_load = true;
        }

        if ( ! $should_load ) {
            return;
        }

        wp_enqueue_style(
            'rar-wow-customer',
            RAR_WOW_URL . 'assets/frontend.css',
            array(),
            RAR_WOW_VERSION
        );

        wp_enqueue_script(
            'rar-wow-customer',
            RAR_WOW_URL . 'assets/frontend.js',
            array(),
            RAR_WOW_VERSION,
            true
        );
    }

    private function customer_status_definition( $status ) {
        $definitions = array(
            'pending' => array(
                'title'   => 'Order received',
                'message' => 'We received your order and it is waiting for the next processing step.',
            ),
            'on-hold' => array(
                'title'   => 'Awaiting verification',
                'message' => 'Your order is on hold while payment or order details are being verified.',
            ),
            'processing' => array(
                'title'   => 'Processing',
                'message' => 'Your order is being prepared for fulfilment.',
            ),
            'confirmed' => array(
                'title'   => 'Confirmed',
                'message' => 'Your order has been confirmed and is being prepared for courier handover.',
            ),
            'shipped' => array(
                'title'   => 'Shipped',
                'message' => 'Your order has been handed to the courier and is on the way.',
            ),
            'completed' => array(
                'title'   => 'Completed',
                'message' => 'Delivery is complete. Thank you for shopping with us.',
            ),
            'cancelled' => array(
                'title'   => 'Cancelled',
                'message' => 'This order has been cancelled. Contact support if this was unexpected.',
            ),
            'returned' => array(
                'title'   => 'Returned',
                'message' => 'This order has been marked as returned. Our team will review the return and settlement process.',
            ),
            'refunded' => array(
                'title'   => 'Refunded',
                'message' => 'A refund has been recorded for this order.',
            ),
            'failed' => array(
                'title'   => 'Payment failed',
                'message' => 'The payment attempt was not completed. Please review the payment instructions or contact support.',
            ),
        );

        $status = sanitize_key( $status );

        if ( isset( $definitions[ $status ] ) ) {
            return $definitions[ $status ];
        }

        return array(
            'title'   => wc_get_order_status_name( $status ),
            'message' => 'The order status has been updated.',
        );
    }

    private function record_status_history( WC_Order $order, $old_status, $new_status ) {
        $old_status = sanitize_key( $old_status );
        $new_status = sanitize_key( $new_status );

        /*
         * Avoid adding work to the checkout-critical initial status transition.
         * Initial Pending/On-hold/Processing state is reconstructed from the
         * order itself and WooCommerce status notes when the customer views it.
         */
        if (
            in_array( $old_status, array( 'pending', 'on-hold', '' ), true ) &&
            in_array( $new_status, array( 'on-hold', 'processing' ), true ) &&
            empty( $this->manual_action_context[ $order->get_id() ] )
        ) {
            return;
        }

        if (
            'processing' === $new_status &&
            empty( $this->manual_action_context[ $order->get_id() ] )
        ) {
            return;
        }

        if (
            ! in_array(
                $new_status,
                array(
                    'processing',
                    'confirmed',
                    'shipped',
                    'completed',
                    'cancelled',
                    'returned',
                    'refunded',
                    'failed',
                ),
                true
            )
        ) {
            return;
        }

        $definition = $this->customer_status_definition( $new_status );
        $message    = $definition['message'];

        if ( 'shipped' === $new_status ) {
            $courier  = $this->courier_label( $order );
            $tracking = $this->tracking_value( $order );

            if ( $courier ) {
                $message .= ' Courier: ' . $courier . '.';
            }

            if ( $tracking ) {
                $message .= ' Tracking: ' . $tracking . '.';
            }
        }

        $this->append_history_event(
            $order,
            array(
                'type'      => 'status',
                'status'    => $new_status,
                'title'     => $definition['title'],
                'message'   => $message,
                'timestamp' => time(),
            )
        );
    }

    private function append_history_event( WC_Order $order, $event ) {
        $history = $order->get_meta( '_rar_wow_customer_history', true );

        if ( ! is_array( $history ) ) {
            $history = array();
        }

        $normalized = $this->normalize_history_event( $event );

        if ( empty( $normalized ) ) {
            return;
        }

        $last = end( $history );

        if (
            is_array( $last ) &&
            isset( $last['type'], $last['status'], $last['timestamp'] ) &&
            $last['type'] === $normalized['type'] &&
            $last['status'] === $normalized['status'] &&
            absint( $normalized['timestamp'] ) - absint( $last['timestamp'] ) < 15
        ) {
            return;
        }

        $history[] = $normalized;

        if ( count( $history ) > 80 ) {
            $history = array_slice( $history, -80 );
        }

        $order->update_meta_data( '_rar_wow_customer_history', $history );
        $order->save_meta_data();
    }

    private function normalize_history_event( $event ) {
        if ( ! is_array( $event ) ) {
            return array();
        }

        $type = isset( $event['type'] )
            ? sanitize_key( $event['type'] )
            : 'update';

        $status = isset( $event['status'] )
            ? sanitize_key( $event['status'] )
            : '';

        $title = isset( $event['title'] )
            ? sanitize_text_field( $event['title'] )
            : '';

        $message = isset( $event['message'] )
            ? sanitize_textarea_field( $event['message'] )
            : '';

        $timestamp = isset( $event['timestamp'] )
            ? absint( $event['timestamp'] )
            : time();

        if ( ! $title || ! $timestamp ) {
            return array();
        }

        return array(
            'type'      => $type,
            'status'    => $status,
            'title'     => $title,
            'message'   => $message,
            'timestamp' => $timestamp,
        );
    }

    private function parse_local_datetime_timestamp( $value ) {
        $value = trim( (string) $value );

        if ( ! $value ) {
            return 0;
        }

        try {
            $date = new DateTime( $value, wp_timezone() );
            return $date->getTimestamp();
        } catch ( Exception $e ) {
            $timestamp = strtotime( $value );
            return $timestamp ? absint( $timestamp ) : 0;
        }
    }

    private function note_timestamp( $note ) {
        if ( isset( $note->date_created ) ) {
            if (
                is_object( $note->date_created ) &&
                method_exists( $note->date_created, 'getTimestamp' )
            ) {
                return absint( $note->date_created->getTimestamp() );
            }

            $timestamp = strtotime( (string) $note->date_created );

            if ( $timestamp ) {
                return absint( $timestamp );
            }
        }

        if ( isset( $note->date_created_gmt ) ) {
            $timestamp = strtotime( (string) $note->date_created_gmt . ' UTC' );

            if ( $timestamp ) {
                return absint( $timestamp );
            }
        }

        return 0;
    }

    private function status_slug_from_label( $label ) {
        $normalized = strtolower(
            trim(
                preg_replace(
                    '/[^a-z0-9]+/',
                    '-',
                    remove_accents( (string) $label )
                ),
                '-'
            )
        );

        $map = array(
            'pending-payment' => 'pending',
            'pending'         => 'pending',
            'on-hold'         => 'on-hold',
            'processing'      => 'processing',
            'confirmed'       => 'confirmed',
            'shipped'         => 'shipped',
            'completed'       => 'completed',
            'complete'        => 'completed',
            'cancelled'       => 'cancelled',
            'canceled'        => 'cancelled',
            'returned'        => 'returned',
            'refunded'        => 'refunded',
            'failed'          => 'failed',
        );

        return isset( $map[ $normalized ] )
            ? $map[ $normalized ]
            : '';
    }

    private function status_from_internal_note( $content ) {
        $plain = trim( wp_strip_all_tags( (string) $content ) );

        if ( ! $plain ) {
            return '';
        }

        if (
            ! preg_match(
                '/Order status changed from\s+.{1,100}?\s+to\s+([A-Za-z][A-Za-z \-]{1,60})\./i',
                $plain,
                $matches
            )
        ) {
            return '';
        }

        return $this->status_slug_from_label( $matches[1] );
    }

    private function public_note_event( $note ) {
        $content = isset( $note->content )
            ? trim( wp_strip_all_tags( (string) $note->content ) )
            : '';

        if ( ! $content ) {
            return array();
        }

        $title   = 'Order update';
        $message = $content;

        $known_titles = array(
            'finished'      => 'Finished',
            'delivered'     => 'Delivered',
            'shipped'       => 'Shipped',
            'delivery hold' => 'Delivery Hold',
            'billing'       => 'Billing',
            'confirmed'     => 'Confirmed',
            'processing'    => 'Processing',
            'pending'       => 'Pending',
            'cancelled'     => 'Cancelled',
            'returned'      => 'Returned',
        );

        foreach ( $known_titles as $needle => $label ) {
            if ( 0 === stripos( $content, $needle ) ) {
                $title = $label;

                $remaining = trim(
                    preg_replace(
                        '/^' . preg_quote( $needle, '/' ) . '\s*[:\-]?\s*/i',
                        '',
                        $content
                    )
                );

                if ( $remaining ) {
                    $message = $remaining;
                }

                break;
            }
        }

        return $this->normalize_history_event(
            array(
                'type'      => 'note',
                'status'    => '',
                'title'     => $title,
                'message'   => $message,
                'timestamp' => $this->note_timestamp( $note ),
            )
        );
    }

    private function payment_history_events( WC_Order $order ) {
        $events       = array();
        $status       = sanitize_key( (string) $order->get_meta( '_rar_wap_status', true ) );
        $channel      = trim( (string) $order->get_meta( '_rar_wap_channel_label', true ) );
        $amount       = (float) $order->get_meta( '_rar_wap_required_amount', true );
        $currency     = $order->get_currency();
        $amount_label = $amount > 0
            ? wp_strip_all_tags(
                wc_price(
                    $amount,
                    array( 'currency' => $currency )
                )
            )
            : '';

        $submitted_at = $this->parse_local_datetime_timestamp(
            $order->get_meta( '_rar_wap_submitted_at', true )
        );

        if ( $submitted_at ) {
            $message = 'Advance payment details were submitted';

            if ( $channel ) {
                $message .= ' via ' . $channel;
            }

            if ( $amount_label ) {
                $message .= ' for ' . $amount_label;
            }

            $message .= '.';

            if ( 'verified' !== $status ) {
                $message .= ' Awaiting manual verification.';
            }

            $events[] = $this->normalize_history_event(
                array(
                    'type'      => 'payment',
                    'status'    => 'submitted',
                    'title'     => 'Advance payment submitted',
                    'message'   => $message,
                    'timestamp' => $submitted_at,
                )
            );
        }

        if ( 'verified' === $status ) {
            $verified_at = $this->parse_local_datetime_timestamp(
                $order->get_meta( '_rar_wap_verified_at', true )
            );

            if ( ! $verified_at && $order->get_date_paid() ) {
                $verified_at = $order->get_date_paid()->getTimestamp();
            }

            if ( $verified_at ) {
                $message = 'Advance payment';

                if ( $amount_label ) {
                    $message .= ' of ' . $amount_label;
                }

                if ( $channel ) {
                    $message .= ' via ' . $channel;
                }

                $message .= ' was verified.';

                $events[] = $this->normalize_history_event(
                    array(
                        'type'      => 'payment',
                        'status'    => 'verified',
                        'title'     => 'Payment verified',
                        'message'   => $message,
                        'timestamp' => $verified_at,
                    )
                );
            }
        } elseif ( 'unverified' === $status ) {
            $events[] = $this->normalize_history_event(
                array(
                    'type'      => 'payment',
                    'status'    => 'unverified',
                    'title'     => 'Payment needs attention',
                    'message'   => 'The submitted payment reference could not be verified. Please contact support before sending another payment.',
                    'timestamp' => time(),
                )
            );
        }

        return array_filter( $events );
    }

    private function build_customer_history( WC_Order $order ) {
        $settings = $this->settings();
        $events   = array();

        $created = $order->get_date_created();

        if ( $created ) {
            $events[] = $this->normalize_history_event(
                array(
                    'type'      => 'placed',
                    'status'    => 'placed',
                    'title'     => 'Order placed',
                    'message'   => 'We received your order. New status, courier and payment updates will appear here.',
                    'timestamp' => $created->getTimestamp(),
                )
            );
        }

        $stored = $order->get_meta( '_rar_wow_customer_history', true );

        if ( is_array( $stored ) ) {
            foreach ( $stored as $event ) {
                $event = $this->normalize_history_event( $event );

                if ( $event ) {
                    $events[] = $event;
                }
            }
        }

        $notes = wc_get_order_notes(
            array(
                'order_id' => $order->get_id(),
                'limit'    => 100,
            )
        );

        foreach ( $notes as $note ) {
            $is_customer_note = ! empty( $note->customer_note );

            if (
                $is_customer_note &&
                'yes' === $settings['history_public_notes']
            ) {
                $event = $this->public_note_event( $note );

                if ( $event ) {
                    $events[] = $event;
                }

                continue;
            }

            if ( $is_customer_note ) {
                continue;
            }

            $status = $this->status_from_internal_note(
                isset( $note->content )
                    ? $note->content
                    : ''
            );

            if ( ! $status ) {
                continue;
            }

            $definition = $this->customer_status_definition( $status );

            $events[] = $this->normalize_history_event(
                array(
                    'type'      => 'status',
                    'status'    => $status,
                    'title'     => $definition['title'],
                    'message'   => $definition['message'],
                    'timestamp' => $this->note_timestamp( $note ),
                )
            );
        }

        foreach ( $this->payment_history_events( $order ) as $payment_event ) {
            $events[] = $payment_event;
        }

        $current_status = sanitize_key( $order->get_status() );
        $has_current    = false;

        foreach ( $events as $event ) {
            if (
                isset( $event['type'], $event['status'] ) &&
                'status' === $event['type'] &&
                $current_status === $event['status']
            ) {
                $has_current = true;
                break;
            }
        }

        if ( ! $has_current && $current_status ) {
            $definition = $this->customer_status_definition( $current_status );
            $modified   = $order->get_date_modified();

            $events[] = $this->normalize_history_event(
                array(
                    'type'      => 'status',
                    'status'    => $current_status,
                    'title'     => $definition['title'],
                    'message'   => $definition['message'],
                    'timestamp' => $modified
                        ? $modified->getTimestamp()
                        : time(),
                )
            );
        }

        $events = array_values( array_filter( $events ) );

        usort(
            $events,
            static function ( $a, $b ) {
                return absint( $a['timestamp'] ) <=> absint( $b['timestamp'] );
            }
        );

        $deduped = array();

        foreach ( $events as $event ) {
            $duplicate = false;

            foreach ( array_slice( $deduped, -3 ) as $existing ) {
                if (
                    $existing['type'] === $event['type'] &&
                    $existing['status'] === $event['status'] &&
                    $existing['title'] === $event['title'] &&
                    abs( absint( $existing['timestamp'] ) - absint( $event['timestamp'] ) ) <= 90
                ) {
                    $duplicate = true;
                    break;
                }
            }

            if ( ! $duplicate ) {
                $deduped[] = $event;
            }
        }

        if ( count( $deduped ) > 50 ) {
            $deduped = array_slice( $deduped, -50 );
        }

        return array_reverse( $deduped );
    }

    private function customer_can_view_order_panel( WC_Order $order ) {
        if ( current_user_can( 'manage_woocommerce' ) ) {
            return true;
        }

        if (
            function_exists( 'is_wc_endpoint_url' ) &&
            is_wc_endpoint_url( 'view-order' )
        ) {
            return is_user_logged_in() &&
                absint( $order->get_customer_id() ) > 0 &&
                absint( $order->get_customer_id() ) === get_current_user_id();
        }

        $nonce = isset( $_REQUEST['woocommerce-order-tracking-nonce'] )
            ? wc_clean( wp_unslash( $_REQUEST['woocommerce-order-tracking-nonce'] ) )
            : (
                isset( $_REQUEST['_wpnonce'] )
                    ? wc_clean( wp_unslash( $_REQUEST['_wpnonce'] ) )
                    : ''
            );

        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'woocommerce-order_tracking' ) ) {
            return false;
        }

        $request_order_id = isset( $_REQUEST['orderid'] )
            ? ltrim( wc_clean( wp_unslash( $_REQUEST['orderid'] ) ), '#' )
            : '';

        $request_email = isset( $_REQUEST['order_email'] )
            ? sanitize_email( wp_unslash( $_REQUEST['order_email'] ) )
            : '';

        if ( ! $request_order_id || ! $request_email ) {
            return false;
        }

        $resolved_id = apply_filters(
            'woocommerce_shortcode_order_tracking_order_id',
            $request_order_id
        );

        return absint( $resolved_id ) === $order->get_id() &&
            strtolower( (string) $order->get_billing_email() ) === strtolower( $request_email );
    }

    private function order_progress_stage( WC_Order $order, $history ) {
        $map = array(
            'placed'     => 0,
            'pending'    => 0,
            'on-hold'    => 0,
            'processing' => 0,
            'confirmed'  => 1,
            'shipped'    => 2,
            'completed'  => 3,
            'returned'   => 3,
        );

        $stage = isset( $map[ $order->get_status() ] )
            ? $map[ $order->get_status() ]
            : 0;

        foreach ( $history as $event ) {
            if (
                isset( $event['status'] ) &&
                isset( $map[ $event['status'] ] )
            ) {
                $stage = max( $stage, $map[ $event['status'] ] );
            }
        }

        return min( 3, max( 0, absint( $stage ) ) );
    }

    private function courier_eta( WC_Order $order ) {
        foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
            foreach ( array( 'rwsc_eta', '_rwsc_eta' ) as $key ) {
                $eta = trim( (string) $shipping_item->get_meta( $key, true ) );

                if ( $eta ) {
                    return $eta;
                }
            }
        }

        return '';
    }

    private function payment_summary( WC_Order $order ) {
        $status  = sanitize_key( (string) $order->get_meta( '_rar_wap_status', true ) );
        $channel = trim( (string) $order->get_meta( '_rar_wap_channel_label', true ) );
        $amount  = (float) $order->get_meta( '_rar_wap_required_amount', true );

        $amount_label = $amount > 0
            ? wp_strip_all_tags(
                wc_price(
                    $amount,
                    array( 'currency' => $order->get_currency() )
                )
            )
            : '';

        if ( 'verified' === $status ) {
            $label = 'Verified';

            if ( $amount_label ) {
                $label .= ' · ' . $amount_label;
            }

            if ( $channel ) {
                $label .= ' via ' . $channel;
            }

            return array(
                'label' => $label,
                'class' => 'is-good',
            );
        }

        if ( 'submitted' === $status ) {
            return array(
                'label' => 'Awaiting verification' . ( $channel ? ' · ' . $channel : '' ),
                'class' => 'is-waiting',
            );
        }

        if ( 'unverified' === $status ) {
            return array(
                'label' => 'Needs attention',
                'class' => 'is-alert',
            );
        }

        if ( $order->is_paid() ) {
            return array(
                'label' => 'Paid',
                'class' => 'is-good',
            );
        }

        $method = trim( (string) $order->get_payment_method_title() );

        return array(
            'label' => $method ? $method : 'Not recorded',
            'class' => '',
        );
    }

    private function history_date_label( $timestamp ) {
        if ( ! $timestamp ) {
            return '';
        }

        return wp_date(
            get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ),
            absint( $timestamp ),
            wp_timezone()
        );
    }

    public function render_customer_order_experience( $order_id ) {
        $settings = $this->settings();

        if ( 'yes' !== $settings['customer_status_panel'] ) {
            return;
        }

        $order = wc_get_order( $order_id );

        if (
            ! $order ||
            ! $this->customer_can_view_order_panel( $order )
        ) {
            return;
        }

        $history = $this->build_customer_history( $order );
        $status  = sanitize_key( $order->get_status() );
        $stage   = $this->order_progress_stage( $order, $history );
        $courier = $this->courier_label( $order );
        $eta     = $this->courier_eta( $order );
        $tracking= $this->tracking_value( $order );
        $payment = $this->payment_summary( $order );
        $created = $order->get_date_created();

        $context = (
            function_exists( 'is_wc_endpoint_url' ) &&
            is_wc_endpoint_url( 'view-order' )
        )
            ? 'account'
            : 'tracking';

        $steps = array(
            'Received',
            'Confirmed',
            'Shipped',
            'Completed',
        );

        $exception = in_array(
            $status,
            array( 'cancelled', 'returned', 'failed', 'refunded' ),
            true
        );

        ?>
        <section
            class="rar-wow-order-experience rar-wow-status-<?php echo esc_attr( $status ); ?>"
            data-rar-context="<?php echo esc_attr( $context ); ?>"
            aria-labelledby="rar-wow-order-status-title-<?php echo absint( $order->get_id() ); ?>"
        >
            <div class="rar-wow-customer-head">
                <div>
                    <span class="rar-wow-eyebrow">
                        Order #<?php echo esc_html( $order->get_order_number() ); ?>
                    </span>
                    <h2 id="rar-wow-order-status-title-<?php echo absint( $order->get_id() ); ?>">
                        Order Status
                    </h2>
                    <p>
                        Live order progress, payment and courier updates in one place.
                    </p>
                </div>

                <span class="rar-wow-current-badge <?php echo $exception ? 'is-exception' : ''; ?>">
                    <?php echo esc_html( wc_get_order_status_name( $status ) ); ?>
                </span>
            </div>

            <div class="rar-wow-progress" role="list" aria-label="Order progress">
                <?php foreach ( $steps as $index => $label ) : ?>
                    <?php
                    $done   = $index <= $stage;
                    $active = $index === $stage && ! $exception;
                    ?>
                    <div
                        class="rar-wow-progress-step <?php echo $done ? 'is-done' : ''; ?> <?php echo $active ? 'is-active' : ''; ?>"
                        role="listitem"
                    >
                        <span class="rar-wow-progress-node" aria-hidden="true">
                            <?php echo $done ? '&#10003;' : esc_html( $index + 1 ); ?>
                        </span>
                        <span class="rar-wow-progress-label">
                            <?php echo esc_html( $label ); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ( $exception ) : ?>
                <div class="rar-wow-exception-note">
                    <?php
                    $definition = $this->customer_status_definition( $status );
                    echo esc_html( $definition['message'] );
                    ?>
                </div>
            <?php endif; ?>

            <div class="rar-wow-order-summary">
                <div class="rar-wow-summary-item">
                    <span>Placed</span>
                    <strong>
                        <?php
                        echo $created
                            ? esc_html( $this->history_date_label( $created->getTimestamp() ) )
                            : '—';
                        ?>
                    </strong>
                </div>

                <div class="rar-wow-summary-item">
                    <span>Courier</span>
                    <strong><?php echo esc_html( $courier ? $courier : 'Not assigned yet' ); ?></strong>
                    <?php if ( $eta ) : ?>
                        <small><?php echo esc_html( $eta ); ?></small>
                    <?php endif; ?>
                </div>

                <div class="rar-wow-summary-item">
                    <span>Tracking</span>
                    <strong class="rar-wow-tracking-value">
                        <?php echo esc_html( $tracking ? $tracking : 'Not assigned yet' ); ?>
                    </strong>
                </div>

                <div class="rar-wow-summary-item <?php echo esc_attr( $payment['class'] ); ?>">
                    <span>Payment</span>
                    <strong><?php echo esc_html( $payment['label'] ); ?></strong>
                </div>
            </div>

            <?php if ( 'yes' === $settings['customer_order_history'] ) : ?>
                <div class="rar-wow-history-head">
                    <div>
                        <h3>Order History</h3>
                        <p>Newest updates first</p>
                    </div>
                    <span><?php echo esc_html( count( $history ) ); ?> updates</span>
                </div>

                <ol class="rar-wow-history-list">
                    <?php foreach ( $history as $event ) : ?>
                        <?php
                        $event_status = isset( $event['status'] )
                            ? sanitize_key( $event['status'] )
                            : '';
                        $event_type   = isset( $event['type'] )
                            ? sanitize_key( $event['type'] )
                            : 'update';
                        ?>
                        <li class="rar-wow-history-event rar-wow-event-<?php echo esc_attr( $event_type ); ?> rar-wow-event-status-<?php echo esc_attr( $event_status ); ?>">
                            <span class="rar-wow-history-dot" aria-hidden="true"></span>
                            <div class="rar-wow-history-content">
                                <div class="rar-wow-history-title-row">
                                    <strong><?php echo esc_html( $event['title'] ); ?></strong>
                                    <time datetime="<?php echo esc_attr( gmdate( 'c', absint( $event['timestamp'] ) ) ); ?>">
                                        <?php echo esc_html( $this->history_date_label( $event['timestamp'] ) ); ?>
                                    </time>
                                </div>

                                <?php if ( ! empty( $event['message'] ) ) : ?>
                                    <p><?php echo esc_html( $event['message'] ); ?></p>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>

                <div class="rar-wow-history-security">
                    Only customer-safe updates are shown here. Private admin notes and internal operational logs remain hidden.
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    private function settings() {
        return wp_parse_args(
            (array) get_option( 'rar_wow_settings', array() ),
            array(
                'admin_email'     => '',
                'brand_name'      => get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'Store',
                'support_text'          => 'Need help? Reply to this email and our team will assist you.',
                'customer_emails'       => 'yes',
                'admin_alerts'          => 'yes',
                'customer_status_panel' => 'yes',
                'customer_order_history'=> 'yes',
                'history_public_notes'  => 'yes',
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
                    'intro'         => 'আপনার order confirm করা হয়েছে। Thank you for your order! আমরা এখন আপনার পণ্য carefully prepare করছি। খুব শিগগিরই courier-এর কাছে handover করা হবে।',
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
                    <?php echo esc_html( $settings['brand_name'] ); ?> — Order update
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
        $courier = '';

        foreach (
            array(
                '_rwsc_selected_courier',
                '_nabiad_courier',
            ) as $meta_key
        ) {
            $courier = trim(
                (string) $order->get_meta(
                    $meta_key,
                    true
                )
            );

            if ( $courier ) {
                break;
            }
        }

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
                '_rwsc_tracking_id',
                '_tracking_number',
                '_redx_tracking_id',
                '_redx_parcel_id',
                '_pathao_tracking_id',
                '_pathao_consignment_id',
                '_steadfast_tracking_id',
                '_steadfast_consignment_id',
                '_paperfly_tracking_id',
                '_sundarban_tracking_id',
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
                : ( get_bloginfo( 'name' ) ? get_bloginfo( 'name' ) : 'Store' ),
            'support_text' => isset( $input['support_text'] )
                ? sanitize_text_field( $input['support_text'] )
                : '',
            'customer_emails' => ! empty( $input['customer_emails'] )
                ? 'yes'
                : 'no',
            'admin_alerts' => ! empty( $input['admin_alerts'] )
                ? 'yes'
                : 'no',
            'customer_status_panel' => ! empty( $input['customer_status_panel'] )
                ? 'yes'
                : 'no',
            'customer_order_history' => ! empty( $input['customer_order_history'] )
                ? 'yes'
                : 'no',
            'history_public_notes' => ! empty( $input['history_public_notes'] )
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

                        <tr>
                            <th scope="row">Customer order status panel</th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="rar_wow_settings[customer_status_panel]"
                                        value="1"
                                        <?php checked( $settings['customer_status_panel'], 'yes' ); ?>
                                    >
                                    Show professional order status/progress on View Order and Order Tracking results
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Customer order history</th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="rar_wow_settings[customer_order_history]"
                                        value="1"
                                        <?php checked( $settings['customer_order_history'], 'yes' ); ?>
                                    >
                                    Show full customer-safe order history below the status panel
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Customer-visible notes</th>
                            <td>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="rar_wow_settings[history_public_notes]"
                                        value="1"
                                        <?php checked( $settings['history_public_notes'], 'yes' ); ?>
                                    >
                                    Include WooCommerce notes that were explicitly marked visible to the customer
                                </label>
                                <p class="description">
                                    Private admin notes are never printed. Historical status transitions are reconstructed only from trusted status-change records.
                                </p>
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
