<?php
/**
 * Poll DTO + adaptive schedule (no WordPress bootstrap).
 */
define( 'ABSPATH', __DIR__ );
define( 'WC_PAWAPAY_MSISDN_HASH_KEY', 'test-hash-secret' );

require dirname( __DIR__ ) . '/includes/class-wc-pawapay-attempt.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-poll-policy.php';

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

$waiting = WC_PawaPay_Poll_Policy::dto( [
    'woo_status'      => 'pending',
    'order_paid'      => false,
    'attempt_status'  => WC_PawaPay_Attempt::STATUS_ACCEPTED,
    'deposit_id'      => 'dep-should-never-leak',
    'msisdn'          => '243812345678',
    'masked_phone'    => '********5678',
    'provider'        => 'Orange Money',
    'amount'          => '30',
    'currency'        => 'usd',
    'pay_url'         => 'https://shop.test/checkout/order-pay/42/?pay_for_order=true&key=wc_order_test',
    'failure_code'    => 'INSUFFICIENT_BALANCE',
    'token'           => 'secret-token',
] );

pawapay_assert( $waiting['phase'] === WC_PawaPay_Poll_Policy::PHASE_WAITING, 'accepted stays waiting' );
pawapay_assert( $waiting['paid'] === false, 'waiting is not paid' );
pawapay_assert( $waiting['reload'] === false, 'waiting does not reload' );
pawapay_assert( $waiting['can_retry'] === false, 'active attempt cannot retry' );
pawapay_assert( $waiting['pay_url'] === '', 'waiting hides pay url' );
pawapay_assert( $waiting['masked_phone'] === '********5678', 'masked phone kept' );
pawapay_assert( $waiting['currency'] === 'USD', 'currency normalized' );
pawapay_assert( $waiting['message'] === 'Confirm the payment on your phone. Do not pay again.', 'waiting copy' );

$unknown = WC_PawaPay_Poll_Policy::dto( [
    'woo_status'     => 'pending',
    'order_paid'     => false,
    'attempt_status' => WC_PawaPay_Attempt::STATUS_UNKNOWN,
] );
pawapay_assert( $unknown['phase'] === WC_PawaPay_Poll_Policy::PHASE_WAITING, 'UNKNOWN stays waiting' );
pawapay_assert( $unknown['can_retry'] === false, 'UNKNOWN cannot retry' );

$paid = WC_PawaPay_Poll_Policy::dto( [
    'woo_status'     => 'processing',
    'order_paid'     => true,
    'attempt_status' => WC_PawaPay_Attempt::STATUS_COMPLETED,
] );
pawapay_assert( $paid['phase'] === WC_PawaPay_Poll_Policy::PHASE_PAID, 'processing is paid' );
pawapay_assert( $paid['reload'] === true, 'paid reloads' );
pawapay_assert( $paid['can_retry'] === false, 'paid cannot retry' );

$retry = WC_PawaPay_Poll_Policy::dto( [
    'woo_status'     => 'pending',
    'order_paid'     => false,
    'attempt_status' => WC_PawaPay_Attempt::STATUS_FAILED,
    'pay_url'        => 'https://shop.test/checkout/order-pay/42/?pay_for_order=true&key=wc_order_test',
] );
pawapay_assert( $retry['phase'] === WC_PawaPay_Poll_Policy::PHASE_RETRY, 'failed attempt can retry' );
pawapay_assert( $retry['can_retry'] === true, 'retry flag on' );
pawapay_assert( $retry['reload'] === false, 'retry does not reload' );
pawapay_assert( $retry['pay_url'] !== '', 'retry keeps Woo pay url' );
pawapay_assert( str_contains( $retry['pay_url'], 'order-pay' ), 'retry url is Woo pay page' );

$no_url = WC_PawaPay_Poll_Policy::dto( [
    'woo_status'     => 'pending',
    'order_paid'     => false,
    'attempt_status' => WC_PawaPay_Attempt::STATUS_FAILED,
] );
pawapay_assert( $no_url['can_retry'] === false, 'retry without pay url is not a button' );
pawapay_assert( $no_url['pay_url'] === '', 'empty pay url stays empty' );

$woo_failed = WC_PawaPay_Poll_Policy::dto( [
    'woo_status'     => 'failed',
    'order_paid'     => false,
    'attempt_status' => WC_PawaPay_Attempt::STATUS_FAILED,
    'pay_url'        => 'https://shop.test/checkout/order-pay/42/',
] );
pawapay_assert( $woo_failed['phase'] === WC_PawaPay_Poll_Policy::PHASE_FAILED, 'Woo failed is failed phase' );
pawapay_assert( $woo_failed['reload'] === true, 'Woo failed reloads' );
pawapay_assert( $woo_failed['can_retry'] === false, 'Woo failed does not offer plugin retry' );

$json = json_encode( $waiting, JSON_UNESCAPED_SLASHES );
pawapay_assert( is_string( $json ) && ! str_contains( $json, 'dep-should-never-leak' ), 'dto json has no deposit id' );
pawapay_assert( ! str_contains( (string) $json, '243812345678' ), 'dto json has no full MSISDN' );
pawapay_assert( ! str_contains( (string) $json, 'secret-token' ), 'dto json has no token' );
pawapay_assert( ! str_contains( (string) $json, 'INSUFFICIENT_BALANCE' ), 'dto json has no failure code' );

foreach ( WC_PawaPay_Poll_Policy::forbidden_keys() as $key ) {
    pawapay_assert( ! array_key_exists( $key, $waiting ), "forbidden key $key stripped" );
}
foreach ( array_keys( $waiting ) as $key ) {
    pawapay_assert( in_array( $key, WC_PawaPay_Poll_Policy::allowed_keys(), true ), "key $key is allowed" );
}

$dirty = WC_PawaPay_Poll_Policy::sanitize( [
    'phase'      => 'waiting',
    'deposit_id' => 'x',
    'token'      => 'y',
] );
pawapay_assert( isset( $dirty['phase'] ), 'sanitize keeps allowed' );
pawapay_assert( ! isset( $dirty['deposit_id'], $dirty['token'] ), 'sanitize drops secrets' );

pawapay_assert( WC_PawaPay_Poll_Policy::delay_ms( 1 ) === 3000, 'early poll 3s' );
pawapay_assert( WC_PawaPay_Poll_Policy::delay_ms( 8 ) === 3000, 'tier 1 last 3s' );
pawapay_assert( WC_PawaPay_Poll_Policy::delay_ms( 9 ) === 5000, 'tier 2 5s' );
pawapay_assert( WC_PawaPay_Poll_Policy::delay_ms( 17 ) === 10000, 'tier 3 10s' );
pawapay_assert( WC_PawaPay_Poll_Policy::delay_ms( 25 ) === 15000, 'tier 4 15s' );
pawapay_assert( WC_PawaPay_Poll_Policy::delay_ms( 9 ) < WC_PawaPay_Poll_Policy::delay_ms( 17 ), 'delays increase' );
pawapay_assert( WC_PawaPay_Poll_Policy::error_delay_ms( 1 ) > WC_PawaPay_Poll_Policy::delay_ms( 1 ), 'error backs off' );
pawapay_assert( WC_PawaPay_Poll_Policy::error_delay_ms( 25 ) <= 20000, 'error delay capped' );
pawapay_assert( WC_PawaPay_Poll_Policy::should_continue( 1 ), 'continues early' );
pawapay_assert( ! WC_PawaPay_Poll_Policy::should_continue( 28 ), 'stops at max' );
pawapay_assert( WC_PawaPay_Poll_Policy::schedule()['maxAttempts'] === 28, 'schedule max' );
pawapay_assert( WC_PawaPay_Poll_Policy::should_poll( WC_PawaPay_Poll_Policy::PHASE_WAITING ), 'waiting polls' );
pawapay_assert( ! WC_PawaPay_Poll_Policy::should_poll( WC_PawaPay_Poll_Policy::PHASE_FAILED ), 'failed does not poll' );
pawapay_assert( ! WC_PawaPay_Poll_Policy::should_poll( WC_PawaPay_Poll_Policy::PHASE_RETRY ), 'retry does not poll' );
pawapay_assert( ! WC_PawaPay_Poll_Policy::should_poll( WC_PawaPay_Poll_Policy::PHASE_PAID ), 'paid does not poll' );

if ( $failed > 0 ) {
    fwrite( STDERR, "$failed assertion(s) failed\n" );
    exit( 1 );
}
echo "All poll tests passed\n";
