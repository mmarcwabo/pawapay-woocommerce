<?php
defined( 'ABSPATH' ) || exit;

/**
 * Site currency + PawaPay charge currency.
 *
 * Display totals stay in WooCommerce (and Aelia/WOOCS if present).
 * The customer may choose a charge currency the operator supports;
 * we convert the order total into that currency before calling PawaPay.
 */
class WC_PawaPay_Currency {

    public static function shop_base_currency(): string {
        return strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) );
    }

    public static function active_currency(): string {
        return strtoupper( (string) get_woocommerce_currency() );
    }

    /**
     * Currencies the store can already show/price in.
     *
     * @return list<string>
     */
    public static function site_currencies(): array {
        $list = [
            self::shop_base_currency(),
            self::active_currency(),
        ];

        $aelia = apply_filters( 'wc_aelia_cs_enabled_currencies', [] );
        if ( is_array( $aelia ) ) {
            $list = array_merge( $list, $aelia );
        }

        global $WOOCS;
        if ( isset( $WOOCS ) && is_object( $WOOCS ) && method_exists( $WOOCS, 'get_currencies' ) ) {
            $woocs = $WOOCS->get_currencies();
            if ( is_array( $woocs ) ) {
                $list = array_merge( $list, array_keys( $woocs ) );
            }
        }

        $list = apply_filters( 'woocommerce_pawapay_site_currencies', $list );

        return self::normalize_list( $list );
    }

    /**
     * Currencies PawaPay operators in this plugin know about.
     *
     * @return list<string>
     */
    public static function catalog_currencies(): array {
        $list = [];
        foreach ( WC_PawaPay_Providers::all() as $provider ) {
            $list = array_merge( $list, array_keys( $provider['currencies'] ) );
        }
        return self::normalize_list( $list );
    }

    /**
     * Admin choices: site currencies plus catalog currencies.
     *
     * @return array<string, string>
     */
    public static function admin_choices(): array {
        $wc_names = function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : [];
        $codes    = array_merge( self::site_currencies(), self::catalog_currencies() );
        $out      = [];

        foreach ( self::normalize_list( $codes ) as $code ) {
            $label = $wc_names[ $code ] ?? $code;
            $out[ $code ] = $code . ' — ' . $label;
        }

        return $out;
    }

    /**
     * @param list<string> $plugin_enabled
     * @return list<string>
     */
    public static function choices_for_operator( string $mno, array $plugin_enabled ): array {
        $operator = WC_PawaPay_Providers::supported_currencies( $mno );
        $plugin   = self::normalize_list( $plugin_enabled );

        if ( ! $plugin ) {
            $plugin = self::site_currencies() ?: [ self::active_currency() ];
        }

        if ( $operator ) {
            $plugin = array_values( array_intersect( $plugin, $operator ) );
        }

        // Allow a plugin-enabled currency even if Woo has no switcher (e.g. charge CDF on a USD shop).
        return $plugin;
    }

    /**
     * Convert an amount. Returns null if no rate is available.
     */
    public static function convert( float $amount, string $from, string $to, array $manual_rates = [] ): ?float {
        $from = strtoupper( $from );
        $to   = strtoupper( $to );

        if ( $from === $to ) {
            return $amount;
        }

        $filtered = apply_filters( 'woocommerce_pawapay_convert_amount', null, $amount, $from, $to );
        if ( is_numeric( $filtered ) ) {
            return (float) $filtered;
        }

        if ( has_filter( 'wc_aelia_cs_convert' ) ) {
            return (float) apply_filters( 'wc_aelia_cs_convert', $amount, $from, $to );
        }

        global $WOOCS;
        if ( isset( $WOOCS ) && is_object( $WOOCS ) && method_exists( $WOOCS, 'convert_from_to_currency' ) ) {
            return (float) $WOOCS->convert_from_to_currency( $amount, $from, $to );
        }

        $rates = self::parse_rates( $manual_rates, self::shop_base_currency() );
        if ( ! isset( $rates[ $from ], $rates[ $to ] ) || $rates[ $from ] <= 0 || $rates[ $to ] <= 0 ) {
            return null;
        }

        return $amount / $rates[ $from ] * $rates[ $to ];
    }

    /**
     * @param array<string, float>|list<string> $manual_rates
     * @return array<string, float>
     */
    public static function parse_rates( array $manual_rates, string $base ): array {
        $rates = [ strtoupper( $base ) => 1.0 ];

        foreach ( $manual_rates as $key => $value ) {
            if ( is_int( $key ) && is_string( $value ) && str_contains( $value, '=' ) ) {
                [ $code, $rate ] = array_map( 'trim', explode( '=', $value, 2 ) );
            } else {
                $code = (string) $key;
                $rate = $value;
            }
            $code = strtoupper( $code );
            if ( $code !== '' && is_numeric( $rate ) && (float) $rate > 0 ) {
                $rates[ $code ] = (float) $rate;
            }
        }

        return $rates;
    }

    public static function parse_rates_textarea( string $text, string $base ): array {
        $lines = preg_split( '/\r\n|\r|\n/', $text ) ?: [];
        return self::parse_rates( $lines, $base );
    }

    /**
     * @param list<mixed> $list
     * @return list<string>
     */
    public static function normalize_list( array $list ): array {
        $out = [];
        foreach ( $list as $code ) {
            $code = strtoupper( trim( (string) $code ) );
            if ( $code !== '' ) {
                $out[] = $code;
            }
        }
        return array_values( array_unique( $out ) );
    }
}
