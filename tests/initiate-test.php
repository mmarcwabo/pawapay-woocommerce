<?php
/**
 * Initiation lock, reuse, and frozen amounts (no WordPress bootstrap).
 */
define( 'ABSPATH', __DIR__ );
define( 'WC_PAWAPAY_MSISDN_HASH_KEY', 'test-hash-secret' );

require dirname( __DIR__ ) . '/includes/class-wc-pawapay-client.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-api.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-attempt.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-migrator.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-attempt-repository.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-initiation-policy.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-initiation-lock.php';
require dirname( __DIR__ ) . '/includes/class-wc-pawapay-payment-service.php';

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

class WC_PawaPay_Fake_Client implements WC_PawaPay_Client {
    public int $initiate_calls = 0;
    /** @var list<array<string, mixed>> */
    public array $queue = [];
    /** @var list<string> */
    public array $deposit_ids = [];

    public function initiate_deposit(
        string $deposit_id,
        string $amount,
        string $currency,
        string $mno,
        string $msisdn,
        string $description = 'Order payment',
        array $metadata = []
    ): array {
        $this->initiate_calls++;
        $this->deposit_ids[] = $deposit_id;
        return array_shift( $this->queue ) ?? [ 'status' => 'ACCEPTED', '_http_code' => 200 ];
    }

    public function check_deposit_status( string $deposit_id ): array {
        return [ 'status' => 'ACCEPTED', '_http_code' => 200 ];
    }

    public function get_active_configuration(): array {
        return [ 'error' => 'not used' ];
    }
}

function pawapay_command( array $over = [] ): WC_PawaPay_Initiation_Command {
    $defaults = [
        'order_id'           => 1042,
        'order_currency'     => 'USD',
        'order_total'        => 30.0,
        'phone'              => '243812345678',
        'mno'                => 'VODACOM_MPESA_COD',
        'posted_currency'    => 'USD',
        'allowed_providers'  => [ 'VODACOM_MPESA_COD', 'AIRTEL_COD' ],
        'currency_choices'   => [ 'USD', 'CDF' ],
        'customer_id'        => 9,
        'site'               => 'https://shop.test',
        'plugin_version'     => '1.4.0',
    ];
    $data = array_merge( $defaults, $over );
    return new WC_PawaPay_Initiation_Command(
        $data['order_id'],
        $data['order_currency'],
        $data['order_total'],
        $data['phone'],
        $data['mno'],
        $data['posted_currency'],
        $data['allowed_providers'],
        $data['currency_choices'],
        $data['customer_id'],
        $data['site'],
        $data['plugin_version']
    );
}

function pawapay_service( WC_PawaPay_Fake_Client $client, ?WC_PawaPay_Attempt_Repository $repo = null, ?callable $convert = null ): WC_PawaPay_Payment_Service {
    return new WC_PawaPay_Payment_Service(
        $repo ?? WC_PawaPay_Attempt_Repository::memory(),
        $client,
        WC_PawaPay_Initiation_Lock::memory(),
        $convert ?? static fn( float $amount, string $from, string $to ): ?float => $from === $to ? $amount : $amount * 3000,
        static fn( float $converted, string $currency, string $mno ): string => number_format( $converted, 2, '.', '' ),
        static function (): string {
            static $n = 0;
            $n++;
            return sprintf( '00000000-0000-4000-8000-%012d', $n );
        }
    );
}

$client = new WC_PawaPay_Fake_Client();
$service = pawapay_service( $client );
$first = $service->initiate( pawapay_command() );
pawapay_assert( $first->outcome === 'accepted', 'first initiate accepted' );
pawapay_assert( $client->initiate_calls === 1, 'one live deposit' );
pawapay_assert( $first->attempt && $first->attempt->payment_amount() === '30.00', 'amount frozen from order' );
pawapay_assert( $first->attempt && $first->attempt->order_amount() === '30', 'order total stored' );
pawapay_assert( $first->woo_result === 'success', 'accepted is Woo success' );
pawapay_assert( str_contains( $first->note, '********5678' ), 'note uses masked phone' );
pawapay_assert( ! str_contains( $first->note, '243812345678' ), 'note has no full MSISDN' );

$second = $service->initiate( pawapay_command() );
pawapay_assert( $second->outcome === 'reuse', 'double submit reuses attempt' );
pawapay_assert( $client->initiate_calls === 1, 'double submit does not POST again' );
pawapay_assert( $second->attempt && $second->attempt->deposit_id() === $first->attempt->deposit_id(), 'same deposit id reused' );

$other_phone = $service->initiate( pawapay_command( [ 'phone' => '243999000111' ] ) );
pawapay_assert( $other_phone->outcome === 'blocked', 'active attempt blocks another number' );
pawapay_assert( $client->initiate_calls === 1, 'blocked path does not POST' );

$bad_phone = pawapay_service( new WC_PawaPay_Fake_Client() )->initiate( pawapay_command( [ 'phone' => '12' ] ) );
pawapay_assert( $bad_phone->outcome === 'invalid', 'invalid phone rejected' );
pawapay_assert( $bad_phone->woo_result === 'failure', 'invalid phone is Woo failure' );

$no_rate_client = new WC_PawaPay_Fake_Client();
$no_rate = pawapay_service(
    $no_rate_client,
    null,
    static fn( float $amount, string $from, string $to ): ?float => null
)->initiate( pawapay_command( [ 'posted_currency' => 'CDF' ] ) );
pawapay_assert( $no_rate->outcome === 'invalid', 'missing rate rejected' );
pawapay_assert( $no_rate_client->initiate_calls === 0, 'missing rate never calls PawaPay' );

$posted_amount_ignored = new WC_PawaPay_Fake_Client();
$cmd = pawapay_command();
$from_order = pawapay_service( $posted_amount_ignored )->initiate( $cmd );
pawapay_assert( $from_order->attempt && $from_order->attempt->payment_amount() === '30.00', 'posted amount is not a command field' );

$timeout_client = new WC_PawaPay_Fake_Client();
$timeout_client->queue[] = WC_PawaPay_API::normalize_transport_failure( 'Operation timed out', 'http_request_failed' );
$timeout = pawapay_service( $timeout_client )->initiate( pawapay_command() );
pawapay_assert( $timeout->outcome === 'unknown', 'timeout is UNKNOWN' );
pawapay_assert( $timeout->woo_result === 'success', 'timeout does not fail the Woo result' );
pawapay_assert( $timeout->attempt && $timeout->attempt->status() === WC_PawaPay_Attempt::STATUS_UNKNOWN, 'attempt stays UNKNOWN' );
pawapay_assert( $timeout->set_pending === true, 'timeout keeps order pending path' );

$shared_repo = WC_PawaPay_Attempt_Repository::memory();
$shared_client = new WC_PawaPay_Fake_Client();
$shared_client->queue[] = WC_PawaPay_API::normalize_transport_failure( 'Operation timed out', 'http_request_failed' );
$shared = pawapay_service( $shared_client, $shared_repo );
$unknown = $shared->initiate( pawapay_command() );
$unknown_retry = $shared->initiate( pawapay_command() );
pawapay_assert( $unknown->outcome === 'unknown', 'shared timeout UNKNOWN' );
pawapay_assert( $unknown_retry->outcome === 'reuse', 'UNKNOWN attempt is reused' );
pawapay_assert( $shared_client->initiate_calls === 1, 'UNKNOWN retry does not create a second deposit' );

$reject_client = new WC_PawaPay_Fake_Client();
$reject_client->queue[] = [
    'status'          => 'REJECTED',
    '_http_code'      => 200,
    'rejectionReason' => [ 'rejectionCode' => 'INVALID_MSISDN', 'rejectionMessage' => 'bad' ],
];
$reject_client->queue[] = [ 'status' => 'ACCEPTED', '_http_code' => 200 ];
$reject_repo = WC_PawaPay_Attempt_Repository::memory();
$reject_service = pawapay_service( $reject_client, $reject_repo );
$rejected = $reject_service->initiate( pawapay_command() );
$after_fail = $reject_service->initiate( pawapay_command() );
pawapay_assert( $rejected->outcome === 'rejected', 'PawaPay REJECTED is attempt failure' );
pawapay_assert( $rejected->woo_result === 'failure', 'REJECTED stays on checkout' );
pawapay_assert( $after_fail->outcome === 'accepted', 'FAILED attempt allows a new deposit' );
pawapay_assert( $reject_client->initiate_calls === 2, 'retry after FAILED posts once more' );
pawapay_assert(
    $rejected->attempt && $after_fail->attempt && $rejected->attempt->deposit_id() !== $after_fail->attempt->deposit_id(),
    'retry uses a new deposit id'
);

pawapay_assert( WC_PawaPay_Initiation_Policy::validate_phone( '' ) !== '', 'empty phone invalid' );
pawapay_assert( WC_PawaPay_Initiation_Policy::validate_phone( '243812345678' ) === '', 'canonical phone valid' );
pawapay_assert( WC_PawaPay_Initiation_Policy::classify_response( [ 'status' => 'ACCEPTED' ] ) === 'accepted', 'classify ACCEPTED' );
pawapay_assert( WC_PawaPay_Initiation_Policy::resolve_currency( 'CDF', 'USD', [ 'USD', 'CDF' ] ) === 'CDF', 'posted currency choice kept' );
pawapay_assert( WC_PawaPay_Initiation_Policy::resolve_currency( 'EUR', 'USD', [ 'USD', 'CDF' ] ) === 'USD', 'invalid posted currency ignored' );
pawapay_assert( WC_PawaPay_Initiation_Policy::resolve_currency( 'EUR', 'USD', [] ) === 'USD', 'empty choices keep order currency' );
pawapay_assert( WC_PawaPay_Initiation_Policy::classify_response( [ 'error' => 'nope', '_http_code' => 400 ] ) === 'unknown', 'ambiguous 4xx is UNKNOWN' );

$paid_repo = WC_PawaPay_Attempt_Repository::memory();
$paid_client = new WC_PawaPay_Fake_Client();
$paid_service = pawapay_service( $paid_client, $paid_repo );
$paid = $paid_service->initiate( pawapay_command() );
$paid_repo->mark_status( $paid->attempt->deposit_id(), WC_PawaPay_Attempt::STATUS_COMPLETED );
$paid_again = $paid_service->initiate( pawapay_command() );
pawapay_assert( $paid_again->outcome === 'blocked', 'COMPLETED attempt blocks a new deposit' );
pawapay_assert( $paid_client->initiate_calls === 1, 'paid order does not POST again' );

$lock = WC_PawaPay_Initiation_Lock::memory();
pawapay_assert( $lock->acquire( 7 ) === true, 'lock acquire' );
pawapay_assert( $lock->acquire( 7 ) === false, 'lock blocks second acquire' );
$lock->release( 7 );
pawapay_assert( $lock->acquire( 7 ) === true, 'lock release allows retry' );

exit( $failed === 0 ? 0 : 1 );
