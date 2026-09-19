<?php
defined( 'ABSPATH' ) || exit;

class WC_PawaPay_Thankyou {

    public static function init(): void {
        add_action( 'woocommerce_thankyou_pawapay', [ self::class, 'render' ], 5 );
        add_action( 'wp_ajax_wc_pawapay_poll', [ self::class, 'ajax_poll' ] );
        add_action( 'wp_ajax_nopriv_wc_pawapay_poll', [ self::class, 'ajax_poll' ] );
    }

    public static function render( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== 'pawapay' ) {
            return;
        }

        if ( ! $order->has_status( 'pending' ) ) {
            return;
        }

        wp_enqueue_style(
            'wc-pawapay-checkout',
            WC_PAWAPAY_PLUGIN_URL . 'assets/css/pawapay-checkout.css',
            [],
            WC_PAWAPAY_VERSION
        );

        wp_enqueue_script(
            'wc-pawapay-thankyou',
            WC_PAWAPAY_PLUGIN_URL . 'assets/js/pawapay-thankyou.js',
            [],
            WC_PAWAPAY_VERSION,
            true
        );

        wp_localize_script(
            'wc-pawapay-thankyou',
            'wcPawapayThankyou',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'orderId' => $order->get_id(),
                'orderKey' => $order->get_order_key(),
                'nonce'           => wp_create_nonce( 'wc_pawapay_poll_' . $order->get_id() ),
                'timeoutMessage'  => __( 'Still waiting for PawaPay. Keep this page open or check the order later — confirmation can also arrive by webhook.', 'wc-pawapay' ),
            ]
        );

        echo '<div class="pawapay-waiting" id="pawapay-waiting">';
        echo esc_html__( 'Confirm the payment on your phone. This page updates when PawaPay confirms the deposit.', 'wc-pawapay' );
        echo '</div>';
    }

    public static function ajax_poll(): void {
        $order_id  = absint( $_POST['order_id'] ?? 0 );
        $order_key = sanitize_text_field( wp_unslash( $_POST['order_key'] ?? '' ) );
        $nonce     = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );

        if ( ! $order_id || ! wp_verify_nonce( $nonce, 'wc_pawapay_poll_' . $order_id ) ) {
            wp_send_json_error( [ 'message' => 'invalid' ], 403 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_order_key() !== $order_key || $order->get_payment_method() !== 'pawapay' ) {
            wp_send_json_error( [ 'message' => 'not_found' ], 404 );
        }

        if ( $order->has_status( 'pending' ) ) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            $gateway  = $gateways['pawapay'] ?? null;
            if ( $gateway instanceof WC_PawaPay_Gateway ) {
                WC_PawaPay_Deposit::sync_from_api(
                    $order,
                    $gateway->get_api(),
                    'poll',
                    [ 'fail_woo_on_failed' => $gateway->get_option( 'fail_woo_on_failed_deposit' ) === 'yes' ]
                );
                $order = wc_get_order( $order_id );
            }
        }

        $status = $order->get_status();
        wp_send_json_success( [
            'status' => $status,
            'paid'   => $order->is_paid(),
            'reload' => in_array( $status, [ 'processing', 'completed', 'failed', 'cancelled' ], true ),
        ] );
    }
}
