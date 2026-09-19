<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles the asynchronous deposit callback from PawaPay.
 *
 * Pretty permalink: {home}/pawapay-webhook/
 * REST fallback:    {home}/wp-json/pawapay/v1/deposits
 * Must return HTTP 200 and be idempotent.
 */
class WC_PawaPay_Webhook {

    public static function register_endpoint(): void {
        add_rewrite_endpoint( 'pawapay-webhook', EP_ROOT );
    }

    public static function register_rest(): void {
        register_rest_route(
            'pawapay/v1',
            '/deposits',
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'handle_rest' ],
                'permission_callback' => '__return_true',
            ]
        );
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

        $raw_body = file_get_contents( 'php://input' );
        $data     = json_decode( (string) $raw_body, true );
        if ( ! is_array( $data ) ) {
            wc_get_logger()->warning( '[PawaPay Webhook] Invalid JSON received.', [ 'source' => 'wc-pawapay' ] );
            status_header( 400 );
            exit( 'Bad Request' );
        }

        $ok = self::apply_payload( $data );
        status_header( $ok ? 200 : 400 );
        exit( $ok ? 'OK' : 'Bad Request' );
    }

    public static function handle_rest( WP_REST_Request $request ) {
        $data = $request->get_json_params();
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'pawapay_bad_json', 'Bad Request', [ 'status' => 400 ] );
        }

        $ok = self::apply_payload( $data );
        if ( ! $ok ) {
            return new WP_Error( 'pawapay_bad_payload', 'Bad Request', [ 'status' => 400 ] );
        }

        return new WP_REST_Response( 'OK', 200 );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function apply_payload( array $data ): bool {
        $payload    = WC_PawaPay_Deposit::extract_deposit_payload( $data );
        $deposit_id = sanitize_text_field( $payload['depositId'] ?? ( $data['depositId'] ?? '' ) );
        $status     = WC_PawaPay_Deposit::extract_status( $data );
        $logger     = wc_get_logger();

        $logger->info(
            sprintf( '[PawaPay Webhook] depositId=%s status=%s', $deposit_id, $status ),
            [ 'source' => 'wc-pawapay' ]
        );

        if ( $deposit_id === '' || $status === '' ) {
            $logger->warning( '[PawaPay Webhook] Missing depositId or status.', [ 'source' => 'wc-pawapay' ] );
            return false;
        }

        $order = WC_PawaPay_Deposit::find_order( $deposit_id );
        if ( ! $order ) {
            $logger->warning( '[PawaPay Webhook] No order found for deposit: ' . $deposit_id, [ 'source' => 'wc-pawapay' ] );
            return true;
        }

        WC_PawaPay_Deposit::apply( $order, $data, 'webhook' );
        return true;
    }
}
