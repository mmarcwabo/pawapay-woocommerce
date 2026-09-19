<?php
/**
 * /active-conf merge + cautious operator detection (no WordPress bootstrap).
 */
define( 'ABSPATH', __DIR__ );

require dirname( __DIR__ ) . '/includes/class-wc-pawapay-providers.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-catalog-policy.php';

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

$payload = [
    'countries' => [
        [
            'country' => 'COD',
            'correspondents' => [
                [ 'correspondent' => 'ORANGE_COD', 'currency' => 'CDF' ],
                [ 'correspondent' => 'AIRTEL_OAPI_COD', 'currency' => 'USD' ],
            ],
        ],
    ],
];
$codes = WC_PawaPay_Catalog_Policy::correspondent_codes( $payload );
pawapay_assert( in_array( 'ORANGE_COD', $codes, true ), 'parses Orange' );
pawapay_assert( in_array( 'AIRTEL_COD', $codes, true ), 'normalizes legacy Airtel' );

pawapay_assert( WC_PawaPay_Catalog_Policy::correspondent_codes( [ 'error' => 'timeout' ] ) === [], 'error payload is empty' );
pawapay_assert( WC_PawaPay_Catalog_Policy::correspondent_codes( [] ) === [], 'empty payload is empty' );

$static = [ 'ORANGE_COD', 'AIRTEL_COD', 'VODACOM_MPESA_COD' ];
pawapay_assert(
    WC_PawaPay_Catalog_Policy::merge_enabled( $static, [] ) === $static,
    'empty live falls back to static'
);
pawapay_assert(
    WC_PawaPay_Catalog_Policy::merge_enabled( $static, [ 'ORANGE_COD' ] ) === [ 'ORANGE_COD' ],
    'live intersection keeps boarded operators'
);
pawapay_assert(
    WC_PawaPay_Catalog_Policy::merge_enabled( $static, [ 'MTN_MOMO_UGA' ] ) === $static,
    'empty intersection does not blank checkout'
);

$enabled = [ 'ORANGE_COD', 'AIRTEL_COD', 'VODACOM_MPESA_COD' ];
pawapay_assert( WC_PawaPay_Catalog_Policy::detect_provider( '243893456789', $enabled ) === 'ORANGE_COD', 'unique Orange prefix' );
pawapay_assert( WC_PawaPay_Catalog_Policy::detect_provider( '243973456789', $enabled ) === 'AIRTEL_COD', 'unique Airtel prefix' );
pawapay_assert( WC_PawaPay_Catalog_Policy::detect_provider( '243813456789', $enabled ) === 'VODACOM_MPESA_COD', 'unique Vodacom prefix' );
pawapay_assert( WC_PawaPay_Catalog_Policy::detect_provider( '243893456789', [ 'AIRTEL_COD' ] ) === null, 'does not invent a disabled operator' );
pawapay_assert( WC_PawaPay_Catalog_Policy::detect_provider( '256700000000', $enabled ) === null, 'unknown prefix is not guessed' );
pawapay_assert( WC_PawaPay_Catalog_Policy::detect_provider( '', $enabled ) === null, 'empty MSISDN is not guessed' );

$after_live = WC_PawaPay_Catalog_Policy::merge_enabled( $static, [ 'ORANGE_COD' ] );
$with_extra = array_merge( $after_live, [ 'CUSTOM_EXTRA' ] );
pawapay_assert( in_array( 'CUSTOM_EXTRA', $with_extra, true ), 'extras survive live-conf filter' );
pawapay_assert( in_array( 'ORANGE_COD', $with_extra, true ), 'boarded operator kept with extras' );

if ( $failed > 0 ) {
    fwrite( STDERR, "$failed assertion(s) failed\n" );
    exit( 1 );
}
echo "All catalog tests passed\n";
