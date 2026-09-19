<?php
defined( 'ABSPATH' ) || exit;

/**
 * Who may complete an order, and whether the PawaPay payload matches the attempt.
 */
class WC_PawaPay_Completion_Policy {

    /**
     * @return list<string>
     */
    public static function trusted_sources(): array {
        return [ 'poll', 'admin', 'reconciliation', 'status-lookup', 'verified-callback' ];
    }

    public static function is_trusted_source( string $source ): bool {
        return in_array( $source, self::trusted_sources(), true );
    }

    public static function normalize_money( string $value ): string {
        $value = trim( $value );
        if ( $value === '' || ! is_numeric( $value ) ) {
            return $value;
        }

        $formatted = number_format( (float) $value, 8, '.', '' );
        $trimmed   = rtrim( rtrim( $formatted, '0' ), '.' );
        return $trimmed === '' ? '0' : $trimmed;
    }

    public static function amounts_match( string $frozen, string $reported ): bool {
        if ( $frozen === '' || $reported === '' ) {
            return false;
        }
        return self::normalize_money( $frozen ) === self::normalize_money( $reported );
    }

    public static function currencies_match( string $frozen, string $reported ): bool {
        $frozen   = strtoupper( trim( $frozen ) );
        $reported = strtoupper( trim( $reported ) );
        if ( $frozen === '' || $reported === '' ) {
            return false;
        }
        return $frozen === $reported;
    }

    /**
     * @param array{
     *   source: string,
     *   pawapay_status: string,
     *   payment_method: string,
     *   order_paid: bool,
     *   attempt_status: string,
     *   frozen_amount: string,
     *   frozen_currency: string,
     *   requested_amount: string,
     *   requested_currency: string
     * } $ctx
     */
    public static function decide( array $ctx ): string {
        $method = (string) ( $ctx['payment_method'] ?? '' );
        if ( $method !== '' && $method !== 'pawapay' ) {
            return 'reject_method';
        }

        $status = strtoupper( (string) ( $ctx['pawapay_status'] ?? '' ) );
        $source = (string) ( $ctx['source'] ?? '' );
        $paid   = ! empty( $ctx['order_paid'] );
        $attempt_status = (string) ( $ctx['attempt_status'] ?? '' );

        if ( $paid && $attempt_status === WC_PawaPay_Attempt::STATUS_COMPLETED ) {
            return 'already';
        }

        if ( in_array( $status, [ 'FAILED', 'REJECTED' ], true ) ) {
            if ( ! self::is_trusted_source( $source ) ) {
                return 'reject_untrusted';
            }
            return 'record_failed';
        }

        if ( in_array( $status, [ 'ACCEPTED', 'SUBMITTED', 'ENQUEUED' ], true ) ) {
            return 'ignore';
        }

        if ( $status !== 'COMPLETED' ) {
            return 'ignore';
        }

        if ( ! self::is_trusted_source( $source ) ) {
            return 'reject_untrusted';
        }

        $frozen_amount = (string) ( $ctx['frozen_amount'] ?? '' );
        $frozen_ccy    = (string) ( $ctx['frozen_currency'] ?? '' );
        $requested     = (string) ( $ctx['requested_amount'] ?? '' );
        $reported_ccy  = (string) ( $ctx['requested_currency'] ?? '' );

        if ( ! self::amounts_match( $frozen_amount, $requested ) ) {
            return 'reject_amount';
        }
        if ( ! self::currencies_match( $frozen_ccy, $reported_ccy ) ) {
            return 'reject_amount';
        }

        if ( $attempt_status === WC_PawaPay_Attempt::STATUS_COMPLETED && $paid ) {
            return 'already';
        }

        if ( $paid ) {
            return 'already';
        }

        if ( $attempt_status === WC_PawaPay_Attempt::STATUS_COMPLETED ) {
            return 'complete_order_only';
        }

        return 'complete';
    }
}
