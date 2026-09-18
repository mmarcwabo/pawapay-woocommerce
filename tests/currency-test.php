<?php
define( 'ABSPATH', __DIR__ );

function get_option( $key, $default = false ) {
    return $key === 'woocommerce_currency' ? 'USD' : $default;
}

function get_woocommerce_currency() {
    return 'USD';
}

function apply_filters( $hook, $value ) {
    return $value;
}

function has_filter() {
    return false;
}

require dirname( __DIR__ ) . '/includes/class-wc-pawapay-providers.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-currency.php';

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

$orange = WC_PawaPay_Currency::choices_for_operator( 'ORANGE_COD', [ 'USD', 'CDF', 'EUR' ] );
pawapay_assert( $orange === [ 'USD', 'CDF' ], 'operator ∩ plugin currencies' );

$usd_only = WC_PawaPay_Currency::choices_for_operator( 'ORANGE_COD', [ 'USD' ] );
pawapay_assert( $usd_only === [ 'USD' ], 'USD-only plugin setting' );

$eur = WC_PawaPay_Currency::choices_for_operator( 'ORANGE_COD', [ 'EUR' ] );
pawapay_assert( $eur === [], 'EUR is not an Orange COD currency' );

$mtn = WC_PawaPay_Currency::choices_for_operator( 'MTN_MOMO_CIV', [ 'USD', 'XOF' ] );
pawapay_assert( $mtn === [ 'XOF' ], 'CIV MTN keeps XOF only' );

$site_fallback = WC_PawaPay_Currency::choices_for_operator( 'ORANGE_COD', [] );
pawapay_assert( $site_fallback === [ 'USD' ], 'empty plugin list uses site ∩ operator' );

$alias = WC_PawaPay_Currency::choices_for_operator( 'AIRTEL_OAPI_COD', [ 'USD', 'CDF' ] );
pawapay_assert( $alias === [ 'USD', 'CDF' ], 'legacy Airtel alias currencies' );

pawapay_assert( WC_PawaPay_Currency::convert( 10.0, 'USD', 'USD' ) === 10.0, 'same-currency convert' );

$rates = WC_PawaPay_Currency::parse_rates_textarea( "CDF=2800\nKES=130", 'USD' );
pawapay_assert( isset( $rates['USD'], $rates['CDF'] ) && $rates['USD'] === 1.0 && $rates['CDF'] === 2800.0, 'manual rates parse' );

$to_cdf = WC_PawaPay_Currency::convert( 10.0, 'USD', 'CDF', $rates );
pawapay_assert( $to_cdf === 28000.0, 'USD to CDF via manual rate' );

$to_usd = WC_PawaPay_Currency::convert( 28000.0, 'CDF', 'USD', $rates );
pawapay_assert( $to_usd === 10.0, 'CDF to USD via manual rate' );

pawapay_assert( WC_PawaPay_Currency::convert( 10.0, 'USD', 'EUR' ) === null, 'refuse convert without a rate' );

pawapay_assert( WC_PawaPay_Currency::normalize_list( [ 'usd', ' CDF ', '', 'USD' ] ) === [ 'USD', 'CDF' ], 'normalize currency list' );

exit( $failed === 0 ? 0 : 1 );
