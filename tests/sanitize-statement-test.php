<?php
/**
 * Statement description sanitizer (no WordPress bootstrap).
 */
define( 'ABSPATH', __DIR__ );

require dirname( __DIR__ ) . '/includes/class-wc-pawapay-client.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-api.php';

$cases = [
    [ 'Order #42', 'Order 42' ],
    [ 'Order 42', 'Order 42' ],
    [ 'ab', 'Order payment' ],
    [ 'This description is way too long for pawapay', 'This description is wa' ],
    [ 'Pay!! now', 'Pay now' ],
];

$failed = 0;
foreach ( $cases as [ $input, $expected ] ) {
    $got = WC_PawaPay_API::sanitize_statement_description( $input );
    if ( $got !== $expected ) {
        fwrite( STDERR, "FAIL: '$input' => '$got' (expected '$expected')\n" );
        $failed++;
        continue;
    }
    if ( ! preg_match( '/^[a-zA-Z0-9 ]{4,22}$/', $got ) ) {
        fwrite( STDERR, "FAIL: '$got' does not match PawaPay pattern\n" );
        $failed++;
        continue;
    }
    echo "OK: $input => $got\n";
}

$masked = WC_PawaPay_API::mask_msisdn( '243893456789' );
if ( $masked !== '********6789' ) {
    fwrite( STDERR, "FAIL: mask_msisdn => '$masked'\n" );
    $failed++;
} else {
    echo "OK: mask_msisdn\n";
}

$redacted = WC_PawaPay_API::redact_for_log( [
    'payer' => [ 'address' => [ 'value' => '243893456789' ] ],
] );
if ( ( $redacted['payer']['address']['value'] ?? '' ) !== '********6789' ) {
    fwrite( STDERR, "FAIL: redact_for_log\n" );
    $failed++;
} else {
    echo "OK: redact_for_log\n";
}

exit( $failed === 0 ? 0 : 1 );
