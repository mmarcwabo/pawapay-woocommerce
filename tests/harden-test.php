<?php
/**
 * Phase 9 hardening: token resolve, poll throttle, masked meta, UUID (no WordPress).
 */
define( 'ABSPATH', __DIR__ );
define( 'WC_PAWAPAY_MSISDN_HASH_KEY', 'test-hash-secret' );

require dirname( __DIR__ ) . '/includes/class-wc-pawapay-attempt.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-settings-policy.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-poll-policy.php';
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

pawapay_assert(
    WC_PawaPay_Settings_Policy::api_token( 'const-token', 'option-token' ) === 'const-token',
    'wp-config token wins'
);
pawapay_assert(
    WC_PawaPay_Settings_Policy::api_token( '  ', 'option-token' ) === 'option-token',
    'empty constant falls back to option'
);
pawapay_assert(
    WC_PawaPay_Settings_Policy::api_token( '', '' ) === '',
    'both empty stays empty'
);

$phone = '243812345678';
$meta  = WC_PawaPay_Settings_Policy::legacy_phone_meta( $phone );
pawapay_assert( $meta === '********5678', 'legacy meta is masked' );
pawapay_assert( ! str_contains( $meta, $phone ), 'legacy meta has no full MSISDN' );

pawapay_assert( WC_PawaPay_Poll_Policy::allow_lookup( 0, 100 ), 'first poll GET allowed' );
pawapay_assert( ! WC_PawaPay_Poll_Policy::allow_lookup( 100, 101 ), '1s later is throttled' );
pawapay_assert( WC_PawaPay_Poll_Policy::allow_lookup( 100, 102 ), '2s later is allowed' );

$uuid = WC_PawaPay_API::generate_uuid();
pawapay_assert( (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid ), 'uuid v4 shape' );
pawapay_assert( WC_PawaPay_API::generate_uuid() !== $uuid, 'uuids differ' );

if ( $failed > 0 ) {
    fwrite( STDERR, "$failed assertion(s) failed\n" );
    exit( 1 );
}
echo "All harden tests passed\n";
