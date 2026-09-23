<?php
defined( 'ABSPATH' ) || exit;

/**
 * In-app WebView checkout: hide theme chrome.
 * Live hook uses `app=1` only. Cookie query is not a UI switch.
 */
class WC_PawaPay_Checkout_Chrome {

    public static function is_in_app( string $app, string $cookie_query ): bool {
        if ( $app === '1' ) {
            return true;
        }
        return $cookie_query !== '';
    }

    public static function body_class( array $classes, string $app, string $cookie_query ): array {
        if ( self::is_in_app( $app, $cookie_query ) ) {
            $classes[] = 'pawapay-in-app';
        }
        return $classes;
    }
}
