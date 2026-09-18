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

        $payload    = WC_PawaPay_Deposit::extract_deposit_payload( $data );
        $deposit_id = sanitize_text_field( $payload['depositId'] ?? ( $data['depositId'] ?? '' ) );
        $status     = WC_PawaPay_Deposit::extract_status( $data );

        $logger->info(
            sprintf( '[PawaPay Webhook] depositId=%s status=%s', $deposit_id, $status ),
            [ 'source' => 'wc-pawapay' ]
        );

        if ( $deposit_id === '' || $status === '' ) {
            $logger->warning( '[PawaPay Webhook] Missing depositId or status.', [ 'source' => 'wc-pawapay' ] );
            status_header( 400 );
            exit( 'Bad Request' );
        }

        $order = WC_PawaPay_Deposit::find_order( $deposit_id );
        if ( ! $order ) {
            $logger->warning( '[PawaPay Webhook] No order found for deposit: ' . $deposit_id, [ 'source' => 'wc-pawapay' ] );
            status_header( 200 );
            exit( 'OK' );
        }

        WC_PawaPay_Deposit::apply( $order, $data, 'webhook' );
        status_header( 200 );
        exit( 'OK' );
    }
}
