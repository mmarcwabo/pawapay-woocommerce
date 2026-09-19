<?php
defined( 'ABSPATH' ) || exit;

/**
 * wp-config wins over stored options. No HTTP.
 */
class WC_PawaPay_Settings_Policy {

    public static function api_token( string $constant, string $option ): string {
        $constant = trim( $constant );
        return $constant !== '' ? $constant : trim( $option );
    }

    public static function legacy_phone_meta( string $phone ): string {
        return WC_PawaPay_Attempt::mask_msisdn( $phone );
    }
}
