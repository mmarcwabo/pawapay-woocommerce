<?php
/**
 * In-app checkout chrome marker (no WordPress).
 */
define( 'ABSPATH', __DIR__ );

require dirname( __DIR__ ) . '/includes/class-wc-pawapay-checkout-chrome.php';

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
    WC_PawaPay_Checkout_Chrome::is_in_app( '1', '' ) === true,
    'app=1 is in-app'
);
pawapay_assert(
    WC_PawaPay_Checkout_Chrome::is_in_app( '', 'abc' ) === true,
    'cookie query is in-app'
);
pawapay_assert(
    WC_PawaPay_Checkout_Chrome::is_in_app( '', '' ) === false,
    'browser checkout is not in-app'
);
pawapay_assert(
    WC_PawaPay_Checkout_Chrome::is_in_app( '0', '' ) === false,
    'app=0 is not in-app'
);

$classes = WC_PawaPay_Checkout_Chrome::body_class( [ 'woocommerce-checkout' ], '1', '' );
pawapay_assert(
    in_array( 'pawapay-in-app', $classes, true ),
    'body class added for app=1'
);

$browser = WC_PawaPay_Checkout_Chrome::body_class( [ 'woocommerce-checkout' ], '', '' );
pawapay_assert(
    ! in_array( 'pawapay-in-app', $browser, true ),
    'body class omitted for browser'
);

if ( $failed > 0 ) {
    exit( 1 );
}
echo "All checkout chrome tests passed.\n";
