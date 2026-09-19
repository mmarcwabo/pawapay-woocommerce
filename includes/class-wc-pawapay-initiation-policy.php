<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pure initiation rules. No HTTP, no WooCommerce.
 */
class WC_PawaPay_Initiation_Policy {

    public static function validate_phone( string $phone ): string {
        if ( $phone === '' ) {
            return 'Please enter your mobile money number.';
        }
        if ( ! preg_match( '/^\d{7,15}$/', $phone ) ) {
            return 'Please check the Mobile Money number and try again.';
        }
        return '';
    }

    /**
     * @param list<string> $allowed
     */
    public static function validate_provider( string $mno, array $allowed ): string {
        if ( $mno === '' ) {
            return 'Please select your mobile money operator.';
        }
        if ( $allowed && ! in_array( $mno, $allowed, true ) ) {
            return 'That operator is not enabled for this store.';
        }
        return '';
    }

    /**
     * Charge currency is a customer choice. Amount is never taken from the client.
     *
     * @param list<string> $choices
     */
    public static function resolve_currency( string $posted, string $order_currency, array $choices ): string {
        $posted         = strtoupper( trim( $posted ) );
        $order_currency = strtoupper( trim( $order_currency ) );

        if ( $choices === [] ) {
            return $order_currency;
        }

        if ( $posted !== '' && in_array( $posted, $choices, true ) ) {
            return $posted;
        }
        if ( $choices !== [] && in_array( $order_currency, $choices, true ) ) {
            return $order_currency;
        }

        return $choices[0] ?? $order_currency;
    }

    public static function can_reuse(
        WC_PawaPay_Attempt $attempt,
        string $msisdn_hash,
        string $provider,
        string $payment_amount,
        string $payment_currency
    ): bool {
        if ( ! $attempt->is_active() ) {
            return false;
        }

        return $attempt->msisdn_hash() === $msisdn_hash
            && $attempt->provider() === $provider
            && $attempt->payment_amount() === $payment_amount
            && $attempt->payment_currency() === strtoupper( $payment_currency );
    }

    /**
     * @param list<WC_PawaPay_Attempt> $active
     */
    public static function find_reusable(
        array $active,
        string $msisdn_hash,
        string $provider,
        string $payment_amount,
        string $payment_currency
    ): ?WC_PawaPay_Attempt {
        foreach ( $active as $attempt ) {
            if ( self::can_reuse( $attempt, $msisdn_hash, $provider, $payment_amount, $payment_currency ) ) {
                return $attempt;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $response
     */
    public static function classify_response( array $response ): string {
        $type = (string) ( $response['error_type'] ?? '' );
        if ( in_array( $type, [
            WC_PawaPay_API::ERROR_TIMEOUT,
            WC_PawaPay_API::ERROR_CONNECTION,
            WC_PawaPay_API::ERROR_INVALID_JSON,
        ], true ) ) {
            return 'unknown';
        }

        $code   = (int) ( $response['_http_code'] ?? 0 );
        $status = strtoupper( (string) ( $response['status'] ?? '' ) );

        if ( $status === 'ACCEPTED' ) {
            return 'accepted';
        }
        if ( in_array( $status, [ 'REJECTED', 'FAILED' ], true ) ) {
            return 'rejected';
        }
        if ( $code >= 500 ) {
            return 'unknown';
        }

        return 'unknown';
    }
}
