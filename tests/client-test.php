<?php
/**
 * PawaPay client contract + v1 path/error normalization (no WordPress bootstrap).
 */
define( 'ABSPATH', __DIR__ );

require dirname( __DIR__ ) . '/includes/class-wc-pawapay-client.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-api.php';

$failed = 0;
function pawapay_assert( $ok, $message ) {
    global $failed;
    if ( $ok ) {
        echo "OK: $message\n";
        return;
    }
    $failed++;
    fwrite( STDERR, "FAIL: $message\n" );
}

pawapay_assert( is_subclass_of( 'WC_PawaPay_API', 'WC_PawaPay_Client' ), 'v1 client implements interface' );
pawapay_assert( WC_PawaPay_API::API_VERSION === 'v1', 'declared version is v1' );
pawapay_assert( WC_PawaPay_API::deposit_path() === '/deposits', 'v1 deposit path' );
pawapay_assert( WC_PawaPay_API::active_conf_path() === '/active-conf', 'v1 active-conf path' );
pawapay_assert( ! str_contains( WC_PawaPay_API::active_conf_path(), '/v2/' ), 'active-conf is not v2' );
pawapay_assert( ! str_contains( WC_PawaPay_API::deposit_path(), '/v2/' ), 'deposit path is not v2' );
pawapay_assert( WC_PawaPay_API::deposit_status_path( 'abc-123' ) === '/deposits/abc-123', 'v1 status path' );
pawapay_assert( ! str_contains( WC_PawaPay_API::deposit_status_path( 'abc-123' ), '/v2/' ), 'status path is not v2' );
pawapay_assert(
    WC_PawaPay_API::deposit_status_path( '../v2/deposits' ) === '/deposits/' . rawurlencode( '../v2/deposits' ),
    'hostile deposit_id is encoded'
);
pawapay_assert(
    ! str_contains( WC_PawaPay_API::deposit_status_path( '../v2/deposits' ), '/v2/deposits' ),
    'encoded path cannot become /v2/deposits'
);

$payload = WC_PawaPay_API::build_deposit_payload(
    'dep-1',
    '30.00',
    'USD',
    'ORANGE_COD',
    '243893456789',
    'Order #1042',
    [ 'orderId' => '1042', 'site' => 'https://example.test' ]
);
pawapay_assert( ( $payload['statementDescription'] ?? '' ) === 'Order 1042', 'payload sanitizes statement' );
pawapay_assert( ( $payload['correspondent'] ?? '' ) === 'ORANGE_COD', 'v1 correspondent field' );
pawapay_assert( ( $payload['payer']['address']['value'] ?? '' ) === '243893456789', 'payload keeps MSISDN for the request' );
pawapay_assert( count( $payload['metadata'] ) === 2, 'metadata included' );

$too_many = [];
for ( $i = 0; $i < 12; $i++ ) {
    $too_many[ 'k' . $i ] = (string) $i;
}
$capped = WC_PawaPay_API::build_deposit_payload( 'dep-2', '1', 'USD', 'ORANGE_COD', '243800000000', 'Order 1', $too_many );
pawapay_assert( count( $capped['metadata'] ) === 10, 'metadata capped at 10' );

$timeout = WC_PawaPay_API::normalize_transport_failure( 'cURL error 28: Operation timed out after 30000 milliseconds', 'http_request_failed' );
pawapay_assert( $timeout['error_type'] === WC_PawaPay_API::ERROR_TIMEOUT, 'timeout message is TIMEOUT' );
pawapay_assert( $timeout['_http_code'] === 0, 'transport failure has no HTTP code' );
pawapay_assert( isset( $timeout['error'] ) && $timeout['error'] !== '', 'transport failure keeps error text' );

$code_timeout = WC_PawaPay_API::normalize_transport_failure( 'request failed', 'http_request_timeout' );
pawapay_assert( $code_timeout['error_type'] === WC_PawaPay_API::ERROR_TIMEOUT, 'WP timeout code is TIMEOUT' );

$connection = WC_PawaPay_API::normalize_transport_failure( 'cURL error 7: Failed to connect', 'http_request_failed' );
pawapay_assert( $connection['error_type'] === WC_PawaPay_API::ERROR_CONNECTION, 'connect failure is CONNECTION' );

$invalid = WC_PawaPay_API::normalize_http_response( 200, null );
pawapay_assert( $invalid['error_type'] === WC_PawaPay_API::ERROR_INVALID_JSON, 'null body is invalid JSON' );
pawapay_assert( isset( $invalid['error'] ), 'invalid JSON has error key' );

$server = WC_PawaPay_API::normalize_http_response( 503, [ 'message' => 'unavailable' ] );
pawapay_assert( $server['error_type'] === WC_PawaPay_API::ERROR_HTTP, '5xx is HTTP error' );
pawapay_assert( $server['error'] === 'unavailable', '5xx uses message as error' );
pawapay_assert( $server['_http_code'] === 503, '5xx keeps status' );

$ok = WC_PawaPay_API::normalize_http_response( 200, [ 'status' => 'ACCEPTED' ] );
pawapay_assert( ( $ok['status'] ?? '' ) === 'ACCEPTED', 'success keeps PawaPay status' );
pawapay_assert( ! isset( $ok['error'] ), 'success has no error key' );

$redacted = WC_PawaPay_API::redact_for_log( [
    'Authorization' => 'Bearer secret-token',
    'api_token'     => 'secret-token',
    'payer'         => [ 'address' => [ 'value' => '243893456789' ] ],
] );
pawapay_assert( $redacted['Authorization'] === '[redacted]', 'Authorization redacted' );
pawapay_assert( $redacted['api_token'] === '[redacted]', 'api_token redacted' );
pawapay_assert( $redacted['payer']['address']['value'] === '********6789', 'MSISDN masked in redact' );
pawapay_assert( ! str_contains( wp_json_or_serialize( $redacted ), 'secret-token' ), 'redact output has no token' );

exit( $failed === 0 ? 0 : 1 );

function wp_json_or_serialize( array $value ): string {
    $json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
    return is_string( $json ) ? $json : serialize( $value );
}
