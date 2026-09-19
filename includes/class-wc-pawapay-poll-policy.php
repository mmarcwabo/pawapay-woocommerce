<?php
defined( 'ABSPATH' ) || exit;

/**
 * Customer-safe poll DTO and adaptive delays. No HTTP.
 */
class WC_PawaPay_Poll_Policy {

    public const PHASE_WAITING = 'waiting';
    public const PHASE_PAID    = 'paid';
    public const PHASE_FAILED  = 'failed';
    public const PHASE_RETRY   = 'retry';

    /**
     * @return list<string>
     */
    public static function allowed_keys(): array {
        return [
            'phase',
            'paid',
            'reload',
            'can_retry',
            'pay_url',
            'message',
            'masked_phone',
            'provider',
            'amount',
            'currency',
        ];
    }

    /**
     * Keys that must never appear in the customer poll JSON.
     *
     * @return list<string>
     */
    public static function forbidden_keys(): array {
        return [
            'deposit_id',
            'depositId',
            'token',
            'msisdn',
            'phone',
            'failure_code',
            'failure_message',
            'payload',
            'raw',
            '_http_code',
        ];
    }

    public static function max_attempts(): int {
        return 28;
    }

    /**
     * Delay before the next poll after the given 1-based attempt index.
     */
    public static function delay_ms( int $attempt_index ): int {
        if ( $attempt_index <= 8 ) {
            return 3000;
        }
        if ( $attempt_index <= 16 ) {
            return 5000;
        }
        if ( $attempt_index <= 24 ) {
            return 10000;
        }
        return 15000;
    }

    public static function error_delay_ms( int $attempt_index ): int {
        return min( 20000, (int) round( self::delay_ms( $attempt_index ) * 1.5 ) );
    }

    public static function should_continue( int $attempt_index ): bool {
        return $attempt_index < self::max_attempts();
    }

    public static function should_poll( string $phase ): bool {
        return $phase === self::PHASE_WAITING;
    }

    public const MIN_LOOKUP_SECONDS = 2;

    public static function allow_lookup( int $last_lookup_at, int $now ): bool {
        if ( $last_lookup_at <= 0 ) {
            return true;
        }
        return ( $now - $last_lookup_at ) >= self::MIN_LOOKUP_SECONDS;
    }

    /**
     * @return array{maxAttempts: int, tiers: list<array{until: int, ms: int}>}
     */
    public static function schedule(): array {
        return [
            'maxAttempts' => self::max_attempts(),
            'tiers'       => [
                [ 'until' => 8, 'ms' => 3000 ],
                [ 'until' => 16, 'ms' => 5000 ],
                [ 'until' => 24, 'ms' => 10000 ],
                [ 'until' => 28, 'ms' => 15000 ],
            ],
        ];
    }

    public static function phase( string $woo_status, bool $order_paid, string $attempt_status ): string {
        $woo     = strtolower( $woo_status );
        $attempt = strtoupper( $attempt_status );

        if ( $order_paid || in_array( $woo, [ 'processing', 'completed' ], true ) ) {
            return self::PHASE_PAID;
        }
        if ( in_array( $woo, [ 'failed', 'cancelled', 'refunded' ], true ) ) {
            return self::PHASE_FAILED;
        }
        if ( in_array( $attempt, [
            WC_PawaPay_Attempt::STATUS_FAILED,
            WC_PawaPay_Attempt::STATUS_EXPIRED,
            WC_PawaPay_Attempt::STATUS_CANCELLED,
        ], true ) ) {
            return self::PHASE_RETRY;
        }

        return self::PHASE_WAITING;
    }

    public static function message_for( string $phase ): string {
        return match ( $phase ) {
            self::PHASE_PAID   => 'Payment confirmed. This page will refresh.',
            self::PHASE_FAILED => 'This order can no longer be paid here. Open the order or contact the shop.',
            self::PHASE_RETRY  => 'That payment did not complete. You can try another number from the pay page.',
            default            => 'Confirm the payment on your phone. Do not pay again.',
        };
    }

    public static function still_waiting_message(): string {
        return 'Still waiting for confirmation. Keep this page open or check the order later.';
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function dto( array $input ): array {
        $woo     = (string) ( $input['woo_status'] ?? '' );
        $paid    = ! empty( $input['order_paid'] );
        $attempt = (string) ( $input['attempt_status'] ?? '' );
        $pay_url = (string) ( $input['pay_url'] ?? '' );
        $phase   = self::phase( $woo, $paid, $attempt );

        $reload    = in_array( $phase, [ self::PHASE_PAID, self::PHASE_FAILED ], true )
            || in_array( strtolower( $woo ), [ 'processing', 'completed', 'cancelled' ], true );
        $can_retry = $phase === self::PHASE_RETRY && $pay_url !== '';

        return self::sanitize( [
            'phase'        => $phase,
            'paid'         => $paid || $phase === self::PHASE_PAID,
            'reload'       => $reload,
            'can_retry'    => $can_retry,
            'pay_url'      => $can_retry ? $pay_url : '',
            'message'      => self::message_for( $phase ),
            'masked_phone' => (string) ( $input['masked_phone'] ?? '' ),
            'provider'     => (string) ( $input['provider'] ?? '' ),
            'amount'       => (string) ( $input['amount'] ?? '' ),
            'currency'     => strtoupper( (string) ( $input['currency'] ?? '' ) ),
        ] );
    }

    /**
     * @param array<string, mixed> $dto
     * @return array<string, mixed>
     */
    public static function sanitize( array $dto ): array {
        $clean = [];
        foreach ( self::allowed_keys() as $key ) {
            if ( ! array_key_exists( $key, $dto ) ) {
                continue;
            }
            $clean[ $key ] = $dto[ $key ];
        }
        return $clean;
    }
}
