<?php
defined( 'ABSPATH' ) || exit;

class WC_PawaPay_Catalog {

    public const TRANSIENT = 'wc_pawapay_active_conf';

    /** @var list<string>|null */
    private static ?array $request_codes = null;

    /**
     * @return list<string>
     */
    public static function live_codes( WC_PawaPay_Client $api, string $scope = 'live' ): array {
        if ( self::$request_codes !== null ) {
            return self::$request_codes;
        }

        $key = self::TRANSIENT . '_' . ( $scope === 'sandbox' ? 'sandbox' : 'live' );

        if ( function_exists( 'get_transient' ) ) {
            $cached = get_transient( $key );
            if ( is_array( $cached ) ) {
                self::$request_codes = array_values( array_filter( $cached, 'is_string' ) );
                return self::$request_codes;
            }
        }

        $payload = $api->get_active_configuration();
        $codes   = WC_PawaPay_Catalog_Policy::correspondent_codes( is_array( $payload ) ? $payload : [] );
        $ttl     = isset( $payload['error'] )
            ? WC_PawaPay_Catalog_Policy::ERROR_CACHE_TTL
            : WC_PawaPay_Catalog_Policy::CACHE_TTL;

        if ( function_exists( 'set_transient' ) ) {
            set_transient( $key, $codes, $ttl );
        }

        self::$request_codes = $codes;
        return $codes;
    }
}
