<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles the asynchronous deposit callback from PawaPay.
 *
 * PawaPay POSTs JSON to: {home}/pawapay-webhook/
 * Must return HTTP 200 and be idempotent.
 */
class WC_PawaPay_Webhook {

    public static function register_endpoint(): void {
        add_rewrite_endpoint( 'pawapay-webhook', EP_ROOT );
    }

    public static function handle(): void {
        global $wp_query;

        if ( ! isset( $wp_query->query_vars['pawapay-webhook'] ) ) {
            return;
        }

        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
            status_header( 405 );
            exit( 'Method Not Allowed' );
        }

        self::process_callback();
    }

    private static function process_callback(): void {
        $raw_body = file_get_contents( 'php://input' );
        $data     = json_decode( $raw_body, true );
        $logger   = wc_get_logger();

        if ( ! $data ) {
            $logger->warning( '[PawaPay Webhook] Invalid JSON received.', [ 'source' => 'wc-pawapay' ] );
            status_header( 400 );
            exit( 'Bad Request' );
        }

        $deposit_id = sanitize_text_field( $data['depositId'] ?? '' );
        $status     = sanitize_text_field( $data['status'] ?? '' );

        $logger->info(
            sprintf( '[PawaPay Webhook] depositId=%s status=%s', $deposit_id, $status ),
            [ 'source' => 'wc-pawapay' ]
        );

        if ( empty( $deposit_id ) || empty( $status ) ) {
            $logger->warning( '[PawaPay Webhook] Missing depositId or status.', [ 'source' => 'wc-pawapay' ] );
            status_header( 400 );
            exit( 'Bad Request' );
        }

        $orders = wc_get_orders( [
            'meta_key'   => '_pawapay_deposit_id',
            'meta_value' => $deposit_id,
            'limit'      => 1,
        ] );

        if ( empty( $orders ) ) {
            $logger->warning( '[PawaPay Webhook] No order found for deposit: ' . $deposit_id, [ 'source' => 'wc-pawapay' ] );
            status_header( 200 );
            exit( 'OK' );
        }

        $order = $orders[0];

        $current_status = $order->get_status();
        if ( in_array( $current_status, [ 'completed', 'processing', 'failed', 'cancelled' ], true ) ) {
            $logger->info( sprintf(
                '[PawaPay Webhook] Order #%s already in status "%s" — skipping duplicate callback.',
                $order->get_id(),
                $current_status
            ), [ 'source' => 'wc-pawapay' ] );
            status_header( 200 );
            exit( 'OK' );
        }

        switch ( $status ) {

            case 'COMPLETED':
                $order->payment_complete( $deposit_id );
                $order->add_order_note( sprintf(
                    'PawaPay payment COMPLETED. Deposit ID: %s | MNO: %s | Amount: %s %s',
                    $deposit_id,
                    sanitize_text_field( $data['correspondent'] ?? 'N/A' ),
                    sanitize_text_field( $data['amount'] ?? '' ),
                    sanitize_text_field( $data['currency'] ?? '' )
                ) );
                $logger->info( '[PawaPay Webhook] Order #' . $order->get_id() . ' COMPLETED.', [ 'source' => 'wc-pawapay' ] );
                break;

            case 'FAILED':
                $reject_code    = sanitize_text_field( $data['rejectionReason']['rejectionCode'] ?? 'UNKNOWN' );
                $reject_message = sanitize_text_field( $data['rejectionReason']['rejectionMessage'] ?? 'Payment failed.' );
                $order->update_status( 'failed', sprintf(
                    'PawaPay payment FAILED. Reason: [%s] %s',
                    $reject_code,
                    $reject_message
                ) );
                $logger->info( '[PawaPay Webhook] Order #' . $order->get_id() . ' FAILED: ' . $reject_code, [ 'source' => 'wc-pawapay' ] );
                break;

            default:
                $logger->error( sprintf(
                    '[PawaPay Webhook] Unknown status "%s" for Order #%s — leaving as pending for manual review.',
                    $status,
                    $order->get_id()
                ), [ 'source' => 'wc-pawapay' ] );
                $order->add_order_note( 'PawaPay returned unknown status: ' . $status . '. Manual review required.' );
                break;
        }

        status_header( 200 );
        exit( 'OK' );
    }
}
