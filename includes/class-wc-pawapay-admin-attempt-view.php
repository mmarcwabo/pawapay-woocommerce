<?php
defined( 'ABSPATH' ) || exit;

/**
 * Customer/admin-safe attempt row. No token, hash, or full MSISDN.
 */
class WC_PawaPay_Admin_Attempt_View {

    /**
     * @return list<string>
     */
    public static function allowed_keys(): array {
        return [
            'order_id',
            'deposit_id',
            'status',
            'masked_phone',
            'provider',
            'amount',
            'currency',
            'created_at',
            'failure_code',
        ];
    }

    /**
     * @return list<string>
     */
    public static function forbidden_keys(): array {
        return [
            'token',
            'msisdn',
            'phone',
            'msisdn_hash',
            'failure_message',
            'payload',
            'raw',
            'api_token',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row( WC_PawaPay_Attempt $attempt, string $provider_label = '' ): array {
        $masked = $attempt->masked_msisdn();
        $digits = preg_replace( '/\D+/', '', $masked ) ?? '';
        if ( strlen( $digits ) >= 7 ) {
            $masked = WC_PawaPay_Attempt::mask_msisdn( $masked );
        }

        return self::sanitize( [
            'order_id'      => $attempt->order_id(),
            'deposit_id'    => $attempt->deposit_id(),
            'status'        => $attempt->status(),
            'masked_phone'  => $masked,
            'provider'      => $provider_label !== '' ? $provider_label : $attempt->provider(),
            'amount'        => $attempt->payment_amount(),
            'currency'      => $attempt->payment_currency(),
            'created_at'    => $attempt->created_at(),
            'failure_code'  => $attempt->failure_code(),
        ] );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function sanitize( array $row ): array {
        $clean = [];
        foreach ( self::allowed_keys() as $key ) {
            if ( array_key_exists( $key, $row ) ) {
                $clean[ $key ] = $row[ $key ];
            }
        }
        return $clean;
    }
}
