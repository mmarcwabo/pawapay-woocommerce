<?php
defined( 'ABSPATH' ) || exit;

/**
 * PawaPay provider catalog (ISO 3166-1 alpha-3 country codes).
 *
 * @see https://docs.pawapay.io/v2/docs/providers
 */
class WC_PawaPay_Providers {

    /**
     * Legacy codes stored by earlier plugin versions.
     */
    public const ALIASES = [
        'AIRTEL_OAPI_COD' => 'AIRTEL_COD',
        'VODACOM_COD'     => 'VODACOM_MPESA_COD',
    ];

    public static function normalize_code( string $code ): string {
        $code = strtoupper( trim( $code ) );
        return self::ALIASES[ $code ] ?? $code;
    }

    /**
     * @return array<string, array{name: string, prefix: string, iso2: string}>
     */
    public static function countries(): array {
        return [
            'BEN' => [ 'name' => 'Benin', 'prefix' => '229', 'iso2' => 'BJ' ],
            'BFA' => [ 'name' => 'Burkina Faso', 'prefix' => '226', 'iso2' => 'BF' ],
            'CMR' => [ 'name' => 'Cameroon', 'prefix' => '237', 'iso2' => 'CM' ],
            'CIV' => [ 'name' => "Côte d'Ivoire", 'prefix' => '225', 'iso2' => 'CI' ],
            'COD' => [ 'name' => 'DR Congo', 'prefix' => '243', 'iso2' => 'CD' ],
            'COG' => [ 'name' => 'Republic of the Congo', 'prefix' => '242', 'iso2' => 'CG' ],
            'ETH' => [ 'name' => 'Ethiopia', 'prefix' => '251', 'iso2' => 'ET' ],
            'GAB' => [ 'name' => 'Gabon', 'prefix' => '241', 'iso2' => 'GA' ],
            'GHA' => [ 'name' => 'Ghana', 'prefix' => '233', 'iso2' => 'GH' ],
            'KEN' => [ 'name' => 'Kenya', 'prefix' => '254', 'iso2' => 'KE' ],
            'LSO' => [ 'name' => 'Lesotho', 'prefix' => '266', 'iso2' => 'LS' ],
            'MWI' => [ 'name' => 'Malawi', 'prefix' => '265', 'iso2' => 'MW' ],
            'MOZ' => [ 'name' => 'Mozambique', 'prefix' => '258', 'iso2' => 'MZ' ],
            'NGA' => [ 'name' => 'Nigeria', 'prefix' => '234', 'iso2' => 'NG' ],
            'RWA' => [ 'name' => 'Rwanda', 'prefix' => '250', 'iso2' => 'RW' ],
            'SEN' => [ 'name' => 'Senegal', 'prefix' => '221', 'iso2' => 'SN' ],
            'SLE' => [ 'name' => 'Sierra Leone', 'prefix' => '232', 'iso2' => 'SL' ],
            'TZA' => [ 'name' => 'Tanzania', 'prefix' => '255', 'iso2' => 'TZ' ],
            'UGA' => [ 'name' => 'Uganda', 'prefix' => '256', 'iso2' => 'UG' ],
            'ZMB' => [ 'name' => 'Zambia', 'prefix' => '260', 'iso2' => 'ZM' ],
        ];
    }

    public static function flag_emoji( string $country ): string {
        $iso2 = strtoupper( (string) ( self::countries()[ strtoupper( $country ) ]['iso2'] ?? '' ) );
        if ( strlen( $iso2 ) !== 2 ) {
            return '';
        }

        return mb_chr( 0x1F1E6 + ord( $iso2[0] ) - 65, 'UTF-8' )
            . mb_chr( 0x1F1E6 + ord( $iso2[1] ) - 65, 'UTF-8' );
    }

    public static function compose_msisdn( string $input, string $country ): string {
        $digits = preg_replace( '/\D+/', '', $input ) ?? '';
        $prefix = (string) ( self::countries()[ strtoupper( $country ) ]['prefix'] ?? '' );
        if ( $digits === '' ) {
            return '';
        }
        if ( $prefix !== '' && str_starts_with( $digits, $prefix ) ) {
            return $digits;
        }
        if ( str_starts_with( $digits, '0' ) ) {
            $digits = substr( $digits, 1 );
        }
        return $prefix . $digits;
    }

    /**
     * @return list<array{code: string, country: string, label: string, currencies: array<string, int>}>
     */
    public static function all(): array {
        return [
            [ 'code' => 'MTN_MOMO_BEN', 'country' => 'BEN', 'label' => 'MTN MoMo', 'currencies' => [ 'XOF' => 0 ] ],
            [ 'code' => 'MOOV_BEN', 'country' => 'BEN', 'label' => 'Moov', 'currencies' => [ 'XOF' => 0 ] ],
            [ 'code' => 'MOOV_BFA', 'country' => 'BFA', 'label' => 'Moov', 'currencies' => [ 'XOF' => 0 ] ],
            [ 'code' => 'ORANGE_BFA', 'country' => 'BFA', 'label' => 'Orange Money', 'currencies' => [ 'XOF' => 0 ] ],
            [ 'code' => 'MTN_MOMO_CMR', 'country' => 'CMR', 'label' => 'MTN MoMo', 'currencies' => [ 'XAF' => 0 ] ],
            [ 'code' => 'ORANGE_CMR', 'country' => 'CMR', 'label' => 'Orange Money', 'currencies' => [ 'XAF' => 0 ] ],
            [ 'code' => 'MTN_MOMO_CIV', 'country' => 'CIV', 'label' => 'MTN MoMo', 'currencies' => [ 'XOF' => 0 ] ],
            [ 'code' => 'ORANGE_CIV', 'country' => 'CIV', 'label' => 'Orange Money', 'currencies' => [ 'XOF' => 0 ] ],
            [ 'code' => 'WAVE_CIV', 'country' => 'CIV', 'label' => 'Wave', 'currencies' => [ 'XOF' => 0 ] ],
            [ 'code' => 'VODACOM_MPESA_COD', 'country' => 'COD', 'label' => 'Vodacom M-Pesa', 'currencies' => [ 'CDF' => 0, 'USD' => 2 ] ],
            [ 'code' => 'AIRTEL_COD', 'country' => 'COD', 'label' => 'Airtel Money', 'currencies' => [ 'CDF' => 2, 'USD' => 2 ] ],
            [ 'code' => 'ORANGE_COD', 'country' => 'COD', 'label' => 'Orange Money', 'currencies' => [ 'CDF' => 2, 'USD' => 2 ] ],
            [ 'code' => 'AIRTEL_COG', 'country' => 'COG', 'label' => 'Airtel Money', 'currencies' => [ 'XAF' => 0 ] ],
            [ 'code' => 'MTN_MOMO_COG', 'country' => 'COG', 'label' => 'MTN MoMo', 'currencies' => [ 'XAF' => 0 ] ],
            [ 'code' => 'MPESA_ETH', 'country' => 'ETH', 'label' => 'Safaricom M-Pesa', 'currencies' => [ 'ETB' => 2 ] ],
            [ 'code' => 'AIRTEL_GAB', 'country' => 'GAB', 'label' => 'Airtel Money', 'currencies' => [ 'XAF' => 2 ] ],
            [ 'code' => 'MTN_MOMO_GHA', 'country' => 'GHA', 'label' => 'MTN MoMo', 'currencies' => [ 'GHS' => 2 ] ],
            [ 'code' => 'AIRTELTIGO_GHA', 'country' => 'GHA', 'label' => 'AT Money', 'currencies' => [ 'GHS' => 2 ] ],
            [ 'code' => 'VODAFONE_GHA', 'country' => 'GHA', 'label' => 'Telecel', 'currencies' => [ 'GHS' => 2 ] ],
            [ 'code' => 'MPESA_KEN', 'country' => 'KEN', 'label' => 'M-Pesa', 'currencies' => [ 'KES' => 0 ] ],
            [ 'code' => 'MPESA_LSO', 'country' => 'LSO', 'label' => 'M-Pesa', 'currencies' => [ 'LSL' => 2 ] ],
            [ 'code' => 'AIRTEL_MWI', 'country' => 'MWI', 'label' => 'Airtel Money', 'currencies' => [ 'MWK' => 2 ] ],
            [ 'code' => 'TNM_MWI', 'country' => 'MWI', 'label' => 'TNM Mpamba', 'currencies' => [ 'MWK' => 2 ] ],
            [ 'code' => 'MOVITEL_MOZ', 'country' => 'MOZ', 'label' => 'Movitel', 'currencies' => [ 'MZN' => 0 ] ],
            [ 'code' => 'VODACOM_MOZ', 'country' => 'MOZ', 'label' => 'Vodacom M-Pesa', 'currencies' => [ 'MZN' => 2 ] ],
            [ 'code' => 'AIRTEL_NGA', 'country' => 'NGA', 'label' => 'Airtel Money', 'currencies' => [ 'NGN' => 0 ] ],
            [ 'code' => 'MTN_MOMO_NGA', 'country' => 'NGA', 'label' => 'MTN MoMo', 'currencies' => [ 'NGN' => 2 ] ],
            [ 'code' => 'AIRTEL_RWA', 'country' => 'RWA', 'label' => 'Airtel Money', 'currencies' => [ 'RWF' => 0 ] ],
            [ 'code' => 'MTN_MOMO_RWA', 'country' => 'RWA', 'label' => 'MTN MoMo', 'currencies' => [ 'RWF' => 0 ] ],
            [ 'code' => 'FREE_SEN', 'country' => 'SEN', 'label' => 'Free Money', 'currencies' => [ 'XOF' => 0 ] ],
            [ 'code' => 'ORANGE_SEN', 'country' => 'SEN', 'label' => 'Orange Money', 'currencies' => [ 'XOF' => 0 ] ],
            [ 'code' => 'WAVE_SEN', 'country' => 'SEN', 'label' => 'Wave', 'currencies' => [ 'XOF' => 0 ] ],
            [ 'code' => 'ORANGE_SLE', 'country' => 'SLE', 'label' => 'Orange Money', 'currencies' => [ 'SLE' => 2 ] ],
            [ 'code' => 'AIRTEL_TZA', 'country' => 'TZA', 'label' => 'Airtel Money', 'currencies' => [ 'TZS' => 2 ] ],
            [ 'code' => 'VODACOM_TZA', 'country' => 'TZA', 'label' => 'Vodacom M-Pesa', 'currencies' => [ 'TZS' => 0 ] ],
            [ 'code' => 'TIGO_TZA', 'country' => 'TZA', 'label' => 'Tigo Pesa', 'currencies' => [ 'TZS' => 0 ] ],
            [ 'code' => 'HALOTEL_TZA', 'country' => 'TZA', 'label' => 'Halotel', 'currencies' => [ 'TZS' => 0 ] ],
            [ 'code' => 'AIRTEL_OAPI_UGA', 'country' => 'UGA', 'label' => 'Airtel Money', 'currencies' => [ 'UGX' => 0 ] ],
            [ 'code' => 'MTN_MOMO_UGA', 'country' => 'UGA', 'label' => 'MTN MoMo', 'currencies' => [ 'UGX' => 2 ] ],
            [ 'code' => 'AIRTEL_OAPI_ZMB', 'country' => 'ZMB', 'label' => 'Airtel Money', 'currencies' => [ 'ZMW' => 2 ] ],
            [ 'code' => 'MTN_MOMO_ZMB', 'country' => 'ZMB', 'label' => 'MTN MoMo', 'currencies' => [ 'ZMW' => 2 ] ],
            [ 'code' => 'ZAMTEL_ZMB', 'country' => 'ZMB', 'label' => 'Zamtel Kwacha', 'currencies' => [ 'ZMW' => 2 ] ],
        ];
    }

    public static function find( string $code ): ?array {
        $code = self::normalize_code( $code );
        foreach ( self::all() as $provider ) {
            if ( $provider['code'] === $code ) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * @return array<string, string> country_code => label
     */
    public static function country_choices(): array {
        $out = [];
        foreach ( self::countries() as $code => $row ) {
            $out[ $code ] = $row['name'] . ' (' . $code . ')';
        }
        return $out;
    }

    /**
     * @param list<string> $countries
     * @return array<string, string> provider_code => label
     */
    public static function provider_choices( array $countries = [] ): array {
        $out       = [];
        $countries = array_filter( array_map( 'strtoupper', $countries ) );

        foreach ( self::all() as $provider ) {
            if ( $countries && ! in_array( $provider['country'], $countries, true ) ) {
                continue;
            }
            $currencies = implode( ', ', array_keys( $provider['currencies'] ) );
            $out[ $provider['code'] ] = sprintf(
                '%s — %s (%s) [%s]',
                $provider['country'],
                $provider['label'],
                $provider['code'],
                $currencies
            );
        }

        return $out;
    }

    public static function country_for_msisdn( string $msisdn ): ?string {
        $msisdn = preg_replace( '/\D+/', '', $msisdn ) ?? '';
        $best   = null;
        $best_len = 0;

        foreach ( self::countries() as $code => $row ) {
            $prefix = $row['prefix'];
            if ( str_starts_with( $msisdn, $prefix ) && strlen( $prefix ) > $best_len ) {
                $best     = $code;
                $best_len = strlen( $prefix );
            }
        }

        return $best;
    }

    public static function amount_decimals( string $code, string $currency ): int {
        $provider = self::find( $code );
        if ( ! $provider ) {
            return 2;
        }

        $currency = strtoupper( $currency );
        return $provider['currencies'][ $currency ] ?? (int) reset( $provider['currencies'] );
    }

    public static function supported_currencies( string $code ): array {
        $provider = self::find( $code );
        return $provider ? array_keys( $provider['currencies'] ) : [];
    }

    public static function logo_filename( string $code ): ?string {
        $code = strtoupper( $code );
        if ( str_contains( $code, 'ORANGE' ) ) {
            return 'Orange-logo.png';
        }
        if ( str_contains( $code, 'AIRTEL' ) ) {
            return 'Airtel-Money-logo.png';
        }
        if ( str_contains( $code, 'MPESA' ) || str_contains( $code, 'VODACOM' ) ) {
            return 'Mpesa-logo.png';
        }
        return null;
    }
}
