<?php
/**
 * Admin attempt rows (no WordPress bootstrap).
 */
define( 'ABSPATH', __DIR__ );
define( 'WC_PAWAPAY_MSISDN_HASH_KEY', 'test-hash-secret' );

require dirname( __DIR__ ) . '/includes/class-wc-pawapay-attempt.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-migrator.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-attempt-repository.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-admin-attempt-view.php';

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

$phone = '243812345678';
$repo  = WC_PawaPay_Attempt_Repository::memory();
$first = WC_PawaPay_Attempt::create( [
    'order_id'         => 501,
    'deposit_id'       => 'dep-old',
    'provider'         => 'ORANGE_COD',
    'msisdn'           => $phone,
    'payment_currency' => 'USD',
    'payment_amount'   => '30',
    'status'           => WC_PawaPay_Attempt::STATUS_FAILED,
    'failure_code'     => 'INSUFFICIENT_BALANCE',
    'failure_message'  => 'Wallet 243812345678 has no funds',
] );
$second = WC_PawaPay_Attempt::create( [
    'order_id'         => 501,
    'deposit_id'       => 'dep-new',
    'provider'         => 'AIRTEL_COD',
    'msisdn'           => $phone,
    'payment_currency' => 'USD',
    'payment_amount'   => '30',
    'status'           => WC_PawaPay_Attempt::STATUS_ACCEPTED,
] );
$repo->insert( $first );
$repo->insert( $second );

$recent = $repo->find_recent( 20, 0, '' );
pawapay_assert( count( $recent ) === 2, 'lists both attempts' );
pawapay_assert( $recent[0]->deposit_id() === 'dep-new', 'newest first' );
pawapay_assert( $repo->count_all() === 2, 'count all' );
pawapay_assert( $repo->count_all( WC_PawaPay_Attempt::STATUS_FAILED ) === 1, 'count by status' );
pawapay_assert( $repo->find_recent( 20, 0, WC_PawaPay_Attempt::STATUS_FAILED )[0]->deposit_id() === 'dep-old', 'filter failed' );

$row = WC_PawaPay_Admin_Attempt_View::row( $first, 'Orange Money' );
pawapay_assert( $row['order_id'] === 501, 'order id kept' );
pawapay_assert( $row['masked_phone'] === '********5678', 'masked phone' );
pawapay_assert( $row['provider'] === 'Orange Money', 'friendly label' );
pawapay_assert( $row['failure_code'] === 'INSUFFICIENT_BALANCE', 'failure code kept' );
pawapay_assert( ! isset( $row['failure_message'] ), 'failure message stripped' );
pawapay_assert( ! isset( $row['msisdn_hash'] ), 'hash stripped' );

$json = json_encode( $row );
pawapay_assert( ! str_contains( (string) $json, $phone ), 'row json has no full MSISDN' );
pawapay_assert( ! str_contains( (string) $json, 'no funds' ), 'row json has no failure message' );

$leaky = WC_PawaPay_Attempt::from_row( array_merge( $first->to_row(), [
    'masked_msisdn' => $phone,
] ) );
$fixed = WC_PawaPay_Admin_Attempt_View::row( $leaky );
pawapay_assert( $fixed['masked_phone'] !== $phone, 'remasks a full number in masked column' );
pawapay_assert( ! str_contains( json_encode( $fixed ), $phone ), 'remasked row has no full MSISDN' );

foreach ( WC_PawaPay_Admin_Attempt_View::forbidden_keys() as $key ) {
    pawapay_assert( ! array_key_exists( $key, $row ), "forbidden $key absent" );
}

if ( $failed > 0 ) {
    fwrite( STDERR, "$failed assertion(s) failed\n" );
    exit( 1 );
}
echo "All admin tests passed\n";
