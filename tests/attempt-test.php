<?php
/**
 * Payment attempt domain + in-memory repository (no WordPress bootstrap).
 */
define( 'ABSPATH', __DIR__ );
define( 'WC_PAWAPAY_MSISDN_HASH_KEY', 'test-hash-secret' );

require dirname( __DIR__ ) . '/includes/class-wc-pawapay-attempt.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-migrator.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-attempt-repository.php';

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
pawapay_assert( WC_PawaPay_Attempt::mask_msisdn( $phone ) === '********5678', 'mask last 4' );
pawapay_assert( WC_PawaPay_Attempt::hash_msisdn( $phone ) === WC_PawaPay_Attempt::hash_msisdn( '+243 812 345 678' ), 'hash ignores formatting' );
pawapay_assert( WC_PawaPay_Attempt::hash_msisdn( $phone ) !== WC_PawaPay_Attempt::hash_msisdn( '243999999999' ), 'hash differs per number' );

$attempt = WC_PawaPay_Attempt::create( [
    'order_id'         => 1042,
    'deposit_id'       => '11111111-1111-4111-8111-111111111111',
    'provider'         => 'VODACOM_MPESA_COD',
    'msisdn'           => $phone,
    'order_currency'   => 'USD',
    'order_amount'     => '30.00',
    'payment_currency' => 'CDF',
    'payment_amount'   => '90000',
    'exchange_rate'    => '3000',
    'status'           => WC_PawaPay_Attempt::STATUS_INITIATING,
] );

$row = $attempt->to_row();
pawapay_assert( ! array_key_exists( 'msisdn', $row ), 'to_row has no msisdn key' );
pawapay_assert( ! in_array( $phone, $row, true ), 'to_row does not store full MSISDN' );
pawapay_assert( $row['masked_msisdn'] === '********5678', 'masked stored' );
pawapay_assert( $row['msisdn_hash'] === WC_PawaPay_Attempt::hash_msisdn( $phone ), 'hash stored' );

$repo = WC_PawaPay_Attempt_Repository::memory();
pawapay_assert( $repo->insert( $attempt ) === true, 'first insert' );
pawapay_assert( $repo->insert( $attempt ) === false, 'duplicate deposit_id rejected' );

$retry = WC_PawaPay_Attempt::create( [
    'order_id'         => 1042,
    'deposit_id'       => '22222222-2222-4222-8222-222222222222',
    'provider'         => 'AIRTEL_COD',
    'msisdn'           => '243999000111',
    'order_currency'   => 'USD',
    'order_amount'     => '30.00',
    'payment_currency' => 'USD',
    'payment_amount'   => '30.00',
    'exchange_rate'    => '1',
    'status'           => WC_PawaPay_Attempt::STATUS_INITIATING,
] );
pawapay_assert( $repo->insert( $retry ) === true, 'second attempt same order' );

$found = $repo->find_by_deposit_id( '11111111-1111-4111-8111-111111111111' );
pawapay_assert( $found instanceof WC_PawaPay_Attempt, 'find by deposit_id' );
pawapay_assert( $found && $found->order_id() === 1042, 'found attempt belongs to order' );

$all = $repo->find_for_order( 1042 );
pawapay_assert( count( $all ) === 2, 'order keeps both attempts' );

$repo->mark_status( $retry->deposit_id(), WC_PawaPay_Attempt::STATUS_COMPLETED );
$completed = $repo->find_by_deposit_id( $retry->deposit_id() );
pawapay_assert( $completed && $completed->is_completed(), 'mark completed' );
pawapay_assert( $completed && $completed->completed_at() !== null, 'completed_at set' );

$repo->mark_status( $retry->deposit_id(), WC_PawaPay_Attempt::STATUS_FAILED, [
    'failure_code' => 'SHOULD_NOT_STICK',
] );
$still = $repo->find_by_deposit_id( $retry->deposit_id() );
pawapay_assert( $still && $still->is_completed() && $still->failure_code() === '', 'completed attempt is immutable' );

$active = $repo->find_active_for_order( 1042 );
pawapay_assert( count( $active ) === 1, 'active list excludes completed' );
pawapay_assert( $active[0]->deposit_id() === $attempt->deposit_id(), 'active is the first attempt' );

$legacy = $repo->backfill_from_meta( 777, [
    '_pawapay_deposit_id' => 'legacy-deposit-777',
    '_pawapay_phone'      => '243893456789',
    '_pawapay_mno'        => 'ORANGE_COD',
    '_pawapay_currency'   => 'USD',
    '_pawapay_amount'     => '25.00',
], false );
pawapay_assert( $legacy instanceof WC_PawaPay_Attempt, 'backfill from 1.x meta' );
pawapay_assert( $legacy && $legacy->status() === WC_PawaPay_Attempt::STATUS_UNKNOWN, 'unpaid backfill is UNKNOWN' );
pawapay_assert( $legacy && ! in_array( '243893456789', $legacy->to_row(), true ), 'backfill does not store full MSISDN' );
pawapay_assert( $repo->backfill_from_meta( 777, [
    '_pawapay_deposit_id' => 'legacy-deposit-777',
    '_pawapay_phone'      => '243893456789',
], true ) === $legacy, 'second backfill reuses row' );

pawapay_assert( WC_PawaPay_Attempt::from_legacy_meta( 1, [] ) === null, 'backfill requires deposit_id' );
pawapay_assert( WC_PawaPay_Attempt::from_pawapay_status( 'COMPLETED' ) === WC_PawaPay_Attempt::STATUS_COMPLETED, 'map COMPLETED' );
pawapay_assert( WC_PawaPay_Attempt::from_pawapay_status( 'REJECTED' ) === WC_PawaPay_Attempt::STATUS_FAILED, 'map REJECTED' );
pawapay_assert( WC_PawaPay_Attempt::from_pawapay_status( 'ENQUEUED' ) === WC_PawaPay_Attempt::STATUS_PROCESSING, 'map ENQUEUED' );
pawapay_assert( WC_PawaPay_Attempt::from_pawapay_status( '' ) === WC_PawaPay_Attempt::STATUS_UNKNOWN, 'empty PawaPay status is UNKNOWN' );
pawapay_assert( WC_PawaPay_Attempt::format_decimal( 3000.0 ) === '3000', 'format rate' );

$sql = WC_PawaPay_Migrator::schema_sql( 'wp_pawapay_transactions' );
pawapay_assert( str_contains( $sql, 'UNIQUE KEY deposit_id' ), 'unique deposit_id' );
pawapay_assert( str_contains( $sql, 'KEY order_id' ), 'index order_id' );
pawapay_assert( str_contains( $sql, 'KEY status' ), 'index status' );
pawapay_assert( str_contains( $sql, 'KEY created_at' ), 'index created_at' );
pawapay_assert( str_contains( $sql, 'msisdn_hash' ), 'hash column' );
pawapay_assert( str_contains( $sql, 'masked_msisdn' ), 'mask column' );
pawapay_assert( ! preg_match( '/\nmsisdn\s/', $sql ), 'no full MSISDN column' );
pawapay_assert( WC_PawaPay_Migrator::table_name( 'wp_' ) === 'wp_pawapay_transactions', 'table name' );
pawapay_assert( WC_PawaPay_Migrator::should_skip_upgrade( 1, true ) === true, 'skip when version and table exist' );
pawapay_assert( WC_PawaPay_Migrator::should_skip_upgrade( 1, false ) === false, 'retry when version lies and table is missing' );
pawapay_assert( WC_PawaPay_Migrator::should_skip_upgrade( 0, false ) === false, 'install when version is 0' );

exit( $failed === 0 ? 0 : 1 );
