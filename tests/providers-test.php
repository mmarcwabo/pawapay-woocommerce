<?php
define( 'ABSPATH', __DIR__ );

require dirname( __DIR__ ) . '/includes/class-wc-pawapay-providers.php';

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

pawapay_assert( WC_PawaPay_Providers::normalize_code( 'AIRTEL_OAPI_COD' ) === 'AIRTEL_COD', 'legacy Airtel alias' );
pawapay_assert( WC_PawaPay_Providers::normalize_code( 'VODACOM_COD' ) === 'VODACOM_MPESA_COD', 'legacy Vodacom alias' );
pawapay_assert( WC_PawaPay_Providers::country_for_msisdn( '243893456789' ) === 'COD', 'DRC prefix' );
pawapay_assert( WC_PawaPay_Providers::country_for_msisdn( '256700000000' ) === 'UGA', 'Uganda prefix' );
pawapay_assert( WC_PawaPay_Providers::amount_decimals( 'VODACOM_MPESA_COD', 'CDF' ) === 0, 'Vodacom CDF no decimals' );
pawapay_assert( WC_PawaPay_Providers::amount_decimals( 'ORANGE_COD', 'USD' ) === 2, 'Orange USD 2 decimals' );
pawapay_assert( isset( WC_PawaPay_Providers::provider_choices( [ 'COD' ] )['ORANGE_COD'] ), 'COD filter includes Orange' );
pawapay_assert( ! isset( WC_PawaPay_Providers::provider_choices( [ 'COD' ] )['MTN_MOMO_UGA'] ), 'COD filter excludes Uganda' );
pawapay_assert( WC_PawaPay_Providers::compose_msisdn( '973456789', 'COD' ) === '243973456789', 'prefix local DRC number' );
pawapay_assert( WC_PawaPay_Providers::compose_msisdn( '243973456789', 'COD' ) === '243973456789', 'keep full DRC MSISDN' );
pawapay_assert( WC_PawaPay_Providers::compose_msisdn( '0973456789', 'COD' ) === '243973456789', 'strip leading zero' );
pawapay_assert( WC_PawaPay_Providers::flag_emoji( 'COD' ) !== '', 'DRC flag emoji' );

exit( $failed === 0 ? 0 : 1 );
