<?php
/**
 * Reconciliation policy + repository window (no WordPress bootstrap).
 */
define( 'ABSPATH', __DIR__ );
define( 'WC_PAWAPAY_MSISDN_HASH_KEY', 'test-hash-secret' );

require dirname( __DIR__ ) . '/includes/class-wc-pawapay-client.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-attempt.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-migrator.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-attempt-repository.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-reconciliation-policy.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-reconciler.php';

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

$now = strtotime( '2026-09-19 12:00:00 UTC' );

pawapay_assert(
    ! WC_PawaPay_Reconciliation_Policy::is_due(
        gmdate( 'Y-m-d H:i:s', $now - 60 ),
        gmdate( 'Y-m-d H:i:s', $now - 60 ),
        $now
    ),
    'younger than 3 minutes is not due'
);

pawapay_assert(
    WC_PawaPay_Reconciliation_Policy::is_due(
        gmdate( 'Y-m-d H:i:s', $now - 240 ),
        gmdate( 'Y-m-d H:i:s', $now - 240 ),
        $now
    ),
    'ACCEPTED older than 3 minutes is due'
);

pawapay_assert(
    ! WC_PawaPay_Reconciliation_Policy::is_due(
        gmdate( 'Y-m-d H:i:s', $now - 240 ),
        gmdate( 'Y-m-d H:i:s', $now - 30 ),
        $now
    ),
    'recently checked attempt waits for backoff'
);

pawapay_assert(
    ! WC_PawaPay_Reconciliation_Policy::is_due(
        gmdate( 'Y-m-d H:i:s', $now - 200000 ),
        gmdate( 'Y-m-d H:i:s', $now - 4000 ),
        $now
    ),
    'older than 48 hours is not due'
);

pawapay_assert( WC_PawaPay_Reconciliation_Policy::interval_seconds( 200 ) === 180, 'first 15 min every 3 min' );
pawapay_assert( WC_PawaPay_Reconciliation_Policy::interval_seconds( 1200 ) === 600, 'first hour every 10 min' );
pawapay_assert( WC_PawaPay_Reconciliation_Policy::interval_seconds( 4000 ) === 1800, 'first 6 hours every 30 min' );
pawapay_assert( WC_PawaPay_Reconciliation_Policy::interval_seconds( 30000 ) === 3600, 'later hourly' );
pawapay_assert(
    WC_PawaPay_Reconciliation_Policy::interval_seconds( 200 ) < WC_PawaPay_Reconciliation_Policy::interval_seconds( 4000 ),
    'backoff increases'
);

function pawapay_attempt( array $data ): WC_PawaPay_Attempt {
    return WC_PawaPay_Attempt::create( $data + [
        'order_id'         => 90,
        'provider'         => 'ORANGE_COD',
        'msisdn'           => '243812345678',
        'order_currency'   => 'USD',
        'order_amount'     => '30',
        'payment_currency' => 'USD',
        'payment_amount'   => '30',
    ] );
}

$created = gmdate( 'Y-m-d H:i:s', $now - 600 );
$updated = gmdate( 'Y-m-d H:i:s', $now - 600 );

$accepted = pawapay_attempt( [
    'deposit_id' => 'dep-accepted',
    'status'     => WC_PawaPay_Attempt::STATUS_ACCEPTED,
    'created_at' => $created,
    'updated_at' => $updated,
] );
$unknown = pawapay_attempt( [
    'order_id'   => 91,
    'deposit_id' => 'dep-unknown',
    'status'     => WC_PawaPay_Attempt::STATUS_UNKNOWN,
    'created_at' => $created,
    'updated_at' => $updated,
] );
$failed_row = pawapay_attempt( [
    'order_id'   => 92,
    'deposit_id' => 'dep-failed',
    'status'     => WC_PawaPay_Attempt::STATUS_FAILED,
    'created_at' => $created,
    'updated_at' => $updated,
] );
$fresh = pawapay_attempt( [
    'order_id'   => 93,
    'deposit_id' => 'dep-fresh',
    'status'     => WC_PawaPay_Attempt::STATUS_ACCEPTED,
    'created_at' => gmdate( 'Y-m-d H:i:s', $now - 30 ),
    'updated_at' => gmdate( 'Y-m-d H:i:s', $now - 30 ),
] );
$older_live = pawapay_attempt( [
    'order_id'   => 90,
    'deposit_id' => 'dep-older-same-order',
    'status'     => WC_PawaPay_Attempt::STATUS_ACCEPTED,
    'created_at' => gmdate( 'Y-m-d H:i:s', $now - 1200 ),
    'updated_at' => gmdate( 'Y-m-d H:i:s', $now - 1200 ),
] );

$picked = WC_PawaPay_Reconciliation_Policy::select_due(
    [ $accepted, $unknown, $failed_row, $fresh, $older_live ],
    $now,
    10
);
$ids = array_map( static fn( WC_PawaPay_Attempt $a ) => $a->deposit_id(), $picked );
pawapay_assert( in_array( 'dep-accepted', $ids, true ), 'selects ACCEPTED' );
pawapay_assert( in_array( 'dep-unknown', $ids, true ), 'selects UNKNOWN' );
pawapay_assert( in_array( 'dep-older-same-order', $ids, true ), 'selects historical deposit on same order' );
pawapay_assert( ! in_array( 'dep-failed', $ids, true ), 'skips FAILED' );
pawapay_assert( ! in_array( 'dep-fresh', $ids, true ), 'skips fresh ACCEPTED' );

$capped = WC_PawaPay_Reconciliation_Policy::select_due(
    [ $accepted, $unknown, $older_live ],
    $now,
    1
);
pawapay_assert( count( $capped ) === 1, 'batch size caps GETs' );

$repo = WC_PawaPay_Attempt_Repository::memory();
foreach ( [ $accepted, $unknown, $failed_row, $fresh, $older_live ] as $row ) {
    $repo->insert( $row );
}

$window = $repo->find_in_created_window(
    WC_PawaPay_Reconciliation_Policy::eligible_statuses(),
    $now - WC_PawaPay_Reconciliation_Policy::MAX_AGE_SECONDS,
    $now - WC_PawaPay_Reconciliation_Policy::MIN_AGE_SECONDS,
    50
);
$window_ids = array_map( static fn( WC_PawaPay_Attempt $a ) => $a->deposit_id(), $window );
pawapay_assert( in_array( 'dep-accepted', $window_ids, true ), 'window includes ACCEPTED' );
pawapay_assert( ! in_array( 'dep-failed', $window_ids, true ), 'window excludes FAILED' );
pawapay_assert( ! in_array( 'dep-fresh', $window_ids, true ), 'window excludes too-new created_at' );

$due = WC_PawaPay_Reconciler::due_attempts( $repo, $now );
$due_ids = array_map( static fn( WC_PawaPay_Attempt $a ) => $a->deposit_id(), $due );
pawapay_assert( in_array( 'dep-older-same-order', $due_ids, true ), 'due includes older deposit id not latest-meta' );
pawapay_assert( in_array( 'dep-accepted', $due_ids, true ), 'due includes latest ACCEPTED' );

$before_touch = $repo->find_by_deposit_id( 'dep-accepted' )->updated_at();
pawapay_assert( $repo->touch( 'dep-accepted' ), 'touch active attempt' );
$after_touch = $repo->find_by_deposit_id( 'dep-accepted' )->updated_at();
pawapay_assert( $after_touch !== $before_touch, 'touch bumps updated_at' );

class WC_PawaPay_Reconcile_Fake implements WC_PawaPay_Client {
    public array $seen = [];

    public function initiate_deposit(
        string $deposit_id,
        string $amount,
        string $currency,
        string $mno,
        string $msisdn,
        string $description = 'Order payment',
        array $metadata = []
    ): array {
        return [ 'error' => 'not used' ];
    }

    public function check_deposit_status( string $deposit_id ): array {
        $this->seen[] = $deposit_id;
        return [ 'status' => 'COMPLETED', 'depositId' => $deposit_id, '_http_code' => 200 ];
    }
}

$fake = new WC_PawaPay_Reconcile_Fake();
$skip = WC_PawaPay_Reconciler::reconcile_attempt(
    $repo->find_by_deposit_id( 'dep-unknown' ),
    $fake,
    $repo
);
pawapay_assert( $skip === 'skipped', 'missing Woo order skips GET' );
pawapay_assert( $fake->seen === [], 'skip does not call PawaPay' );

if ( $failed > 0 ) {
    fwrite( STDERR, "$failed assertion(s) failed\n" );
    exit( 1 );
}
echo "All reconcile tests passed\n";
