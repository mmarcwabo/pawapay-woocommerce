<?php
defined( 'ABSPATH' ) || exit;

/**
 * PawaPay deposit callback.
 *
 * Pretty permalink: {home}/pawapay-webhook/
 * REST fallback:    {home}/wp-json/pawapay/v1/deposits
 *
 * The posted status is a hint. Paid state comes from GET /deposits/{id}.
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

        $raw = (string) file_get_contents( 'php://input' );
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            wc_get_logger()->warning( '[PawaPay Webhook] Invalid JSON received.', [ 'source' => 'wc-pawapay' ] );
            status_header( 400 );
            exit( 'Bad Request' );
        }

        $result = self::process( $data, $raw, self::request_headers() );
        status_header( $result['http'] );
        exit( $result['ok'] ? 'OK' : 'Bad Request' );
    }

    public static function handle_rest( WP_REST_Request $request ) {
        $raw  = $request->get_body();
        $data = $request->get_json_params();
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'pawapay_bad_json', 'Bad Request', [ 'status' => 400 ] );
        }

        $headers = [];
        foreach ( $request->get_headers() as $key => $value ) {
            $name             = strtolower( str_replace( '_', '-', (string) $key ) );
            $headers[ $name ] = is_array( $value ) ? (string) ( $value[0] ?? '' ) : (string) $value;
        }

        $result = self::process( $data, $raw, $headers );
        if ( ! $result['ok'] ) {
            return new WP_Error( 'pawapay_callback', $result['reason'], [ 'status' => $result['http'] ] );
        }

        return new WP_REST_Response( 'OK', 200 );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @return array{ok: bool, http: int, reason: string}
     */
    public static function process( array $data, string $raw, array $headers ): array {
        $gateway = self::gateway();
        if ( ! $gateway ) {
            return [ 'ok' => false, 'http' => 503, 'reason' => 'gateway_unavailable' ];
        }

        $require = $gateway->get_option( 'verify_signed_callbacks' ) === 'yes';
        $processor = new WC_PawaPay_Callback_Processor( $gateway->get_api(), $require );
        $resolved  = $processor->resolve( $data, $raw, $headers );

        $logger = wc_get_logger();
        $logger->info(
            sprintf(
                '[PawaPay Webhook] depositId=%s hint=%s result=%s',
                $resolved['deposit_id'],
                WC_PawaPay_Deposit::extract_status( $data ),
                $resolved['reason']
            ),
            [ 'source' => 'wc-pawapay' ]
        );

        if ( ! $resolved['ok'] ) {
            return [ 'ok' => false, 'http' => $resolved['http'], 'reason' => $resolved['reason'] ];
        }

        $lookup = $resolved['lookup'];
        if ( ! is_array( $lookup ) ) {
            return [ 'ok' => true, 'http' => 200, 'reason' => $resolved['reason'] ];
        }

        $order = WC_PawaPay_Deposit::find_order( $resolved['deposit_id'] );
        if ( ! $order ) {
            return [ 'ok' => true, 'http' => 200, 'reason' => 'unknown_deposit' ];
        }

        WC_PawaPay_Deposit::apply(
            $order,
            $lookup,
            'status-lookup',
            [ 'fail_woo_on_failed' => $gateway->get_option( 'fail_woo_on_failed_deposit' ) === 'yes' ]
        );

        return [ 'ok' => true, 'http' => 200, 'reason' => 'applied' ];
    }

    /**
     * @return array<string, string>
     */
    private static function request_headers(): array {
        $headers = [];
        foreach ( $_SERVER as $key => $value ) {
            if ( str_starts_with( (string) $key, 'HTTP_' ) ) {
                $name             = strtolower( str_replace( '_', '-', substr( (string) $key, 5 ) ) );
                $headers[ $name ] = (string) $value;
            }
        }
        if ( isset( $_SERVER['CONTENT_TYPE'] ) ) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        return $headers;
    }

    private static function gateway(): ?WC_PawaPay_Gateway {
        if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
            return null;
        }
        $gateway = WC()->payment_gateways()->payment_gateways()['pawapay'] ?? null;
        return $gateway instanceof WC_PawaPay_Gateway ? $gateway : null;
    }
}
