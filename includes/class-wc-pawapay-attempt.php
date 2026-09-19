<?php
defined( 'ABSPATH' ) || exit;

/**
 * One PawaPay deposit for one WooCommerce order.
 *
 * Never stores a full MSISDN. Phone is hashed and masked at construction.
 */
class WC_PawaPay_Attempt {

    public const STATUS_CREATED     = 'CREATED';
    public const STATUS_INITIATING  = 'INITIATING';
    public const STATUS_ACCEPTED    = 'ACCEPTED';
    public const STATUS_PROCESSING  = 'PROCESSING';
    public const STATUS_COMPLETED   = 'COMPLETED';
    public const STATUS_FAILED      = 'FAILED';
    public const STATUS_CANCELLED   = 'CANCELLED';
    public const STATUS_UNKNOWN     = 'UNKNOWN';
    public const STATUS_EXPIRED     = 'EXPIRED';

    /**
     * @return list<string>
     */
    public static function statuses(): array {
        return [
            self::STATUS_CREATED,
            self::STATUS_INITIATING,
            self::STATUS_ACCEPTED,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_UNKNOWN,
            self::STATUS_EXPIRED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function active_statuses(): array {
        return [
            self::STATUS_CREATED,
            self::STATUS_INITIATING,
            self::STATUS_ACCEPTED,
            self::STATUS_PROCESSING,
            self::STATUS_UNKNOWN,
        ];
    }

    public static function is_final( string $status ): bool {
        return in_array( $status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
        ], true );
    }

    public static function normalize_status( string $status ): string {
        $status = strtoupper( trim( $status ) );
        return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_UNKNOWN;
    }

    /**
     * Map a PawaPay v1 deposit status onto an attempt status.
     */
    public static function from_pawapay_status( string $pawapay_status ): string {
        return match ( strtoupper( trim( $pawapay_status ) ) ) {
            'COMPLETED'            => self::STATUS_COMPLETED,
            'FAILED', 'REJECTED'   => self::STATUS_FAILED,
            'ACCEPTED'             => self::STATUS_ACCEPTED,
            'SUBMITTED', 'ENQUEUED' => self::STATUS_PROCESSING,
            default                => self::STATUS_UNKNOWN,
        };
    }

    public static function normalize_msisdn( string $msisdn ): string {
        return preg_replace( '/\D+/', '', $msisdn ) ?? '';
    }

    public static function mask_msisdn( string $msisdn ): string {
        $digits = self::normalize_msisdn( $msisdn );
        if ( strlen( $digits ) <= 4 ) {
            return $digits === '' ? '' : '****';
        }
        return str_repeat( '*', strlen( $digits ) - 4 ) . substr( $digits, -4 );
    }

    public static function hash_secret(): string {
        if ( defined( 'WC_PAWAPAY_MSISDN_HASH_KEY' ) && is_string( WC_PAWAPAY_MSISDN_HASH_KEY ) && WC_PAWAPAY_MSISDN_HASH_KEY !== '' ) {
            return WC_PAWAPAY_MSISDN_HASH_KEY;
        }
        if ( function_exists( 'wp_salt' ) ) {
            return (string) wp_salt( 'auth' );
        }
        return 'pawapay-local-hash-key';
    }

    public static function hash_msisdn( string $msisdn, ?string $secret = null ): string {
        $digits = self::normalize_msisdn( $msisdn );
        if ( $digits === '' ) {
            return '';
        }
        return hash_hmac( 'sha256', $digits, $secret ?? self::hash_secret() );
    }

    public static function format_decimal( float $value, int $precision = 8 ): string {
        $formatted = number_format( $value, $precision, '.', '' );
        $trimmed   = rtrim( rtrim( $formatted, '0' ), '.' );
        return $trimmed === '' ? '0' : $trimmed;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create( array $data ): self {
        $msisdn = (string) ( $data['msisdn'] ?? '' );
        unset( $data['msisdn'] );

        if ( $msisdn !== '' ) {
            $data['msisdn_hash']   = $data['msisdn_hash'] ?? self::hash_msisdn( $msisdn );
            $data['masked_msisdn'] = $data['masked_msisdn'] ?? self::mask_msisdn( $msisdn );
        }

        return new self( $data );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function from_row( array $row ): self {
        return new self( $row );
    }

    /**
     * Rebuild one row from 1.x order meta. Does not copy the full MSISDN.
     *
     * @param array<string, mixed> $meta
     */
    public static function from_legacy_meta( int $order_id, array $meta, bool $order_paid = false ): ?self {
        $deposit_id = trim( (string) ( $meta['_pawapay_deposit_id'] ?? '' ) );
        if ( $deposit_id === '' || $order_id <= 0 ) {
            return null;
        }

        $phone = (string) ( $meta['_pawapay_phone'] ?? '' );
        $status = $order_paid ? self::STATUS_COMPLETED : self::STATUS_UNKNOWN;

        return self::create( [
            'order_id'          => $order_id,
            'deposit_id'        => $deposit_id,
            'provider'          => (string) ( $meta['_pawapay_mno'] ?? '' ),
            'msisdn'            => $phone,
            'order_currency'    => strtoupper( (string) ( $meta['_pawapay_order_currency'] ?? '' ) ),
            'order_amount'      => (string) ( $meta['_pawapay_order_amount'] ?? '' ),
            'payment_currency'  => strtoupper( (string) ( $meta['_pawapay_currency'] ?? '' ) ),
            'payment_amount'    => (string) ( $meta['_pawapay_amount'] ?? '' ),
            'exchange_rate'     => (string) ( $meta['_pawapay_exchange_rate'] ?? '' ),
            'status'            => $status,
            'completed_at'      => $order_paid ? gmdate( 'Y-m-d H:i:s' ) : null,
        ] );
    }

    private int $id = 0;
    private int $order_id = 0;
    private string $deposit_id = '';
    private string $provider = '';
    private string $msisdn_hash = '';
    private string $masked_msisdn = '';
    private string $order_currency = '';
    private string $order_amount = '';
    private string $payment_currency = '';
    private string $payment_amount = '';
    private string $exchange_rate = '';
    private string $status = self::STATUS_CREATED;
    private string $provider_transaction_id = '';
    private string $failure_code = '';
    private string $failure_message = '';
    private string $created_at = '';
    private string $updated_at = '';
    private ?string $completed_at = null;

    /**
     * @param array<string, mixed> $data
     */
    private function __construct( array $data ) {
        $now = gmdate( 'Y-m-d H:i:s' );

        $this->id                      = (int) ( $data['id'] ?? 0 );
        $this->order_id                = (int) ( $data['order_id'] ?? 0 );
        $this->deposit_id              = trim( (string) ( $data['deposit_id'] ?? '' ) );
        $this->provider                = (string) ( $data['provider'] ?? '' );
        $this->msisdn_hash             = (string) ( $data['msisdn_hash'] ?? '' );
        $this->masked_msisdn           = (string) ( $data['masked_msisdn'] ?? '' );
        $this->order_currency          = strtoupper( (string) ( $data['order_currency'] ?? '' ) );
        $this->order_amount            = (string) ( $data['order_amount'] ?? '' );
        $this->payment_currency        = strtoupper( (string) ( $data['payment_currency'] ?? '' ) );
        $this->payment_amount          = (string) ( $data['payment_amount'] ?? '' );
        $this->exchange_rate           = (string) ( $data['exchange_rate'] ?? '' );
        $this->status                  = self::normalize_status( (string) ( $data['status'] ?? self::STATUS_CREATED ) );
        $this->provider_transaction_id = (string) ( $data['provider_transaction_id'] ?? '' );
        $this->failure_code            = (string) ( $data['failure_code'] ?? '' );
        $this->failure_message         = (string) ( $data['failure_message'] ?? '' );
        $this->created_at              = (string) ( $data['created_at'] ?? $now );
        $this->updated_at              = (string) ( $data['updated_at'] ?? $now );
        $completed                     = $data['completed_at'] ?? null;
        $this->completed_at            = $completed ? (string) $completed : null;
    }

    public function id(): int {
        return $this->id;
    }

    public function order_id(): int {
        return $this->order_id;
    }

    public function deposit_id(): string {
        return $this->deposit_id;
    }

    public function provider(): string {
        return $this->provider;
    }

    public function msisdn_hash(): string {
        return $this->msisdn_hash;
    }

    public function masked_msisdn(): string {
        return $this->masked_msisdn;
    }

    public function order_currency(): string {
        return $this->order_currency;
    }

    public function order_amount(): string {
        return $this->order_amount;
    }

    public function payment_currency(): string {
        return $this->payment_currency;
    }

    public function payment_amount(): string {
        return $this->payment_amount;
    }

    public function exchange_rate(): string {
        return $this->exchange_rate;
    }

    public function status(): string {
        return $this->status;
    }

    public function provider_transaction_id(): string {
        return $this->provider_transaction_id;
    }

    public function failure_code(): string {
        return $this->failure_code;
    }

    public function failure_message(): string {
        return $this->failure_message;
    }

    public function created_at(): string {
        return $this->created_at;
    }

    public function updated_at(): string {
        return $this->updated_at;
    }

    public function completed_at(): ?string {
        return $this->completed_at;
    }

    public function is_completed(): bool {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function is_active(): bool {
        return in_array( $this->status, self::active_statuses(), true );
    }

    public function assign_id( int $id ): void {
        $this->id = $id;
    }

    /**
     * COMPLETED rows are immutable. Other status writes refresh updated_at.
     *
     * @param array<string, mixed> $extra
     */
    public function apply_status( string $status, array $extra = [] ): bool {
        if ( $this->is_completed() ) {
            return false;
        }

        $this->status                  = self::normalize_status( $status );
        $this->updated_at              = gmdate( 'Y-m-d H:i:s' );
        $this->provider_transaction_id = (string) ( $extra['provider_transaction_id'] ?? $this->provider_transaction_id );
        $this->failure_code            = (string) ( $extra['failure_code'] ?? $this->failure_code );
        $this->failure_message         = (string) ( $extra['failure_message'] ?? $this->failure_message );

        if ( $this->status === self::STATUS_COMPLETED ) {
            $this->completed_at = (string) ( $extra['completed_at'] ?? $this->updated_at );
        }

        return true;
    }

    /**
     * Persistable columns only. Full MSISDN is never included.
     *
     * @return array<string, mixed>
     */
    public function to_row(): array {
        return [
            'order_id'                 => $this->order_id,
            'deposit_id'               => $this->deposit_id,
            'provider'                 => $this->provider,
            'msisdn_hash'              => $this->msisdn_hash,
            'masked_msisdn'            => $this->masked_msisdn,
            'order_currency'           => $this->order_currency,
            'order_amount'             => $this->order_amount,
            'payment_currency'         => $this->payment_currency,
            'payment_amount'           => $this->payment_amount,
            'exchange_rate'            => $this->exchange_rate,
            'status'                   => $this->status,
            'provider_transaction_id'  => $this->provider_transaction_id,
            'failure_code'             => $this->failure_code,
            'failure_message'          => $this->failure_message,
            'created_at'               => $this->created_at,
            'updated_at'               => $this->updated_at,
            'completed_at'             => $this->completed_at,
        ];
    }
}
