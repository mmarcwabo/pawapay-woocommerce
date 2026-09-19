<?php
/**
 * Completion policy, callback hint+GET, digest verification (no WordPress bootstrap).
 */
define( 'ABSPATH', __DIR__ );
define( 'WC_PAWAPAY_MSISDN_HASH_KEY', 'test-hash-secret' );

require dirname( __DIR__ ) . '/includes/class-wc-pawapay-client.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-api.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-attempt.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-migrator.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-attempt-repository.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-completion-policy.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-webhook-verifier.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-deposit.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-callback-processor.php';

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

class WC_PawaPay_Status_Fake implements WC_PawaPay_Client {
    public int $status_calls = 0;
    /** @var list<array<string, mixed>> */
    public array $queue = [];

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
        $this->status_calls++;
        return array_shift( $this->queue ) ?? [ 'status' => 'ACCEPTED', 'depositId' => $deposit_id, '_http_code' => 200 ];
    }
}

$base = [
    'source'             => 'status-lookup',
    'pawapay_status'     => 'COMPLETED',
    'payment_method'     => 'pawapay',
    'order_paid'         => false,
    'attempt_status'     => WC_PawaPay_Attempt::STATUS_ACCEPTED,
    'frozen_amount'      => '30.00',
    'frozen_currency'    => 'USD',
    'requested_amount'   => '30',
    'requested_currency' => 'USD',
];

pawapay_assert( WC_PawaPay_Completion_Policy::decide( $base ) === 'complete', 'trusted COMPLETED + matching amount' );
pawapay_assert( WC_PawaPay_Completion_Policy::decide( array_merge( $base, [ 'source' => 'webhook' ] ) ) === 'reject_untrusted', 'forged/untrusted COMPLETED refused' );
pawapay_assert( WC_PawaPay_Completion_Policy::decide( array_merge( $base, [ 'requested_amount' => '1.00' ] ) ) === 'reject_amount', 'amount mismatch refused' );
pawapay_assert( WC_PawaPay_Completion_Policy::decide( array_merge( $base, [ 'requested_currency' => 'CDF' ] ) ) === 'reject_amount', 'currency mismatch refused' );
pawapay_assert( WC_PawaPay_Completion_Policy::decide( array_merge( $base, [ 'order_paid' => true, 'attempt_status' => 'COMPLETED' ] ) ) === 'already', 'already paid is idempotent' );
pawapay_assert( WC_PawaPay_Completion_Policy::decide( array_merge( $base, [ 'attempt_status' => 'COMPLETED', 'order_paid' => false ] ) ) === 'complete_order_only', 'completed attempt can still pay the order' );
pawapay_assert( WC_PawaPay_Completion_Policy::decide( array_merge( $base, [ 'attempt_status' => 'COMPLETED', 'order_paid' => false, 'requested_amount' => '1' ] ) ) === 'reject_amount', 'complete_order_only still checks amount' );
pawapay_assert( WC_PawaPay_Completion_Policy::decide( array_merge( $base, [ 'pawapay_status' => 'FAILED' ] ) ) === 'record_failed', 'trusted FAILED records attempt only' );
pawapay_assert( WC_PawaPay_Completion_Policy::decide( array_merge( $base, [ 'pawapay_status' => 'FAILED', 'source' => 'webhook' ] ) ) === 'reject_untrusted', 'untrusted FAILED refused' );
pawapay_assert( WC_PawaPay_Completion_Policy::decide( array_merge( $base, [ 'payment_method' => 'cod' ] ) ) === 'reject_method', 'wrong method refused' );
pawapay_assert( WC_PawaPay_Completion_Policy::amounts_match( '30.00', '30' ), '30.00 matches 30' );
pawapay_assert( ! WC_PawaPay_Completion_Policy::is_trusted_source( 'webhook' ), 'webhook is not trusted' );

$body = '{"depositId":"dep-1","status":"COMPLETED"}';
$digest = WC_PawaPay_Webhook_Verifier::content_digest( $body );
pawapay_assert( WC_PawaPay_Webhook_Verifier::digest_matches( $body, $digest ), 'digest matches body' );
pawapay_assert( ! WC_PawaPay_Webhook_Verifier::digest_matches( $body, WC_PawaPay_Webhook_Verifier::content_digest( '{"tampered":1}' ) ), 'digest rejects tamper' );
pawapay_assert( WC_PawaPay_Webhook_Verifier::signature_date_is_fresh( gmdate( 'c' ), time() ), 'fresh signature-date' );
pawapay_assert( ! WC_PawaPay_Webhook_Verifier::signature_date_is_fresh( gmdate( 'c', time() - 3600 ), time() ), 'stale signature-date' );

$fresh = gmdate( 'c' );
$gate_ok = WC_PawaPay_Webhook_Verifier::verify_signed_gate( $body, [
    'content-digest'  => $digest,
    'signature-date'  => $fresh,
    'signature'       => 'sig',
    'signature-input' => 'sig=()',
] );
pawapay_assert( $gate_ok['ok'] === true, 'signed gate passes digest+date+signature headers' );
$gate_bad = WC_PawaPay_Webhook_Verifier::verify_signed_gate( $body, [
    'content-digest' => $digest,
    'signature-date' => $fresh,
] );
pawapay_assert( $gate_bad['ok'] === false, 'signed gate requires Signature header' );

$fake = new WC_PawaPay_Status_Fake();
$fake->queue[] = [
    'status'          => 'ACCEPTED',
    'depositId'       => 'dep-1',
    'requestedAmount' => '30.00',
    'currency'        => 'USD',
];
$processor = new WC_PawaPay_Callback_Processor( $fake, false );
$resolved  = $processor->resolve( [ 'depositId' => 'dep-1', 'status' => 'COMPLETED' ], $body, [] );
pawapay_assert( $resolved['ok'] === true, 'unsigned callback still looks up' );
pawapay_assert( $fake->status_calls === 1, 'unsigned callback GETs PawaPay' );
pawapay_assert( WC_PawaPay_Deposit::extract_status( $resolved['lookup'] ) === 'ACCEPTED', 'forged COMPLETED is not the lookup status' );

$signed = new WC_PawaPay_Callback_Processor( new WC_PawaPay_Status_Fake(), true );
$denied = $signed->resolve( [ 'depositId' => 'dep-1', 'status' => 'COMPLETED' ], $body, [] );
pawapay_assert( $denied['http'] === 401, 'signed mode rejects missing headers' );
pawapay_assert( $denied['lookup'] === null, 'signed reject does not GET' );

$not_ok = new WC_PawaPay_Status_Fake();
$not_ok->queue[] = [ 'message' => 'not found', '_http_code' => 404 ];
$failed_lookup = ( new WC_PawaPay_Callback_Processor( $not_ok, false ) )->resolve( [ 'depositId' => 'dep-1' ], '{}', [] );
pawapay_assert( $failed_lookup['http'] === 503, 'non-2xx GET is 503' );
pawapay_assert( $failed_lookup['lookup'] === null, 'non-2xx GET does not apply' );

$repo = WC_PawaPay_Attempt_Repository::memory();
$attempt = WC_PawaPay_Attempt::create( [
    'order_id'         => 10,
    'deposit_id'       => 'dep-complete',
    'provider'         => 'ORANGE_COD',
    'msisdn'           => '243800000000',
    'payment_currency' => 'USD',
    'payment_amount'   => '30.00',
    'status'           => WC_PawaPay_Attempt::STATUS_ACCEPTED,
] );
$repo->insert( $attempt );
pawapay_assert( $repo->claim_completion( 'dep-complete' ) === 'claimed', 'first claim wins' );
pawapay_assert( $repo->claim_completion( 'dep-complete' ) === 'already', 'duplicate COMPLETED is idempotent' );
pawapay_assert( $repo->find_by_deposit_id( 'dep-complete' )->is_completed(), 'attempt is COMPLETED' );

exit( $failed === 0 ? 0 : 1 );
