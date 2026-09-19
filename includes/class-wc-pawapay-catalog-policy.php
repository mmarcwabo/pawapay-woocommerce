<?php
defined( 'ABSPATH' ) || exit;

/**
 * /active-conf merge rules and cautious MSISDN→operator hints.
 * Never invent a provider when prefixes overlap or the payload is empty.
 */
class WC_PawaPay_Catalog_Policy {

    public const CACHE_TTL       = 1800;
    public const ERROR_CACHE_TTL = 300;

    /**
     * Conservative unique prefixes only. DRC number portability makes this UNKNOWN
     * outside these sandbox-style prefixes. The customer can always override.
     *
     * @return array<string, list<string>>
     */
    public static function operator_prefixes(): array {
        return [
            'ORANGE_COD'         => [ '24389' ],
            'AIRTEL_COD'         => [ '24397' ],
            'VODACOM_MPESA_COD'  => [ '24381' ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public static function correspondent_codes( array $payload ): array {
        if ( isset( $payload['error'] ) ) {
            return [];
        }

        $countries = $payload['countries'] ?? null;
        if ( ! is_array( $countries ) ) {
            return [];
        }

        $codes = [];
        foreach ( $countries as $country ) {
            if ( ! is_array( $country ) ) {
                continue;
            }
            $list = $country['correspondents'] ?? ( $country['providers'] ?? [] );
            if ( ! is_array( $list ) ) {
                continue;
            }
            foreach ( $list as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $code = WC_PawaPay_Providers::normalize_code( (string) ( $row['correspondent'] ?? ( $row['code'] ?? '' ) ) );
                if ( $code !== '' ) {
                    $codes[] = $code;
                }
            }
        }

        return array_values( array_unique( $codes ) );
    }

    /**
     * @param list<string> $static_codes
     * @param list<string> $live_codes
     * @return list<string>
     */
    public static function merge_enabled( array $static_codes, array $live_codes ): array {
        $static_codes = array_values( array_filter( $static_codes, 'is_string' ) );
        $live_codes   = array_values( array_filter( $live_codes, 'is_string' ) );

        if ( $live_codes === [] ) {
            return $static_codes;
        }

        $keep = array_values( array_intersect( $static_codes, $live_codes ) );
        return $keep !== [] ? $keep : $static_codes;
    }

    /**
     * @param list<string> $allowed
     */
    public static function detect_provider( string $msisdn, array $allowed = [] ): ?string {
        $digits = preg_replace( '/\D+/', '', $msisdn ) ?? '';
        if ( $digits === '' ) {
            return null;
        }

        $hits = [];
        foreach ( self::operator_prefixes() as $code => $prefixes ) {
            if ( $allowed && ! in_array( $code, $allowed, true ) ) {
                continue;
            }
            foreach ( $prefixes as $prefix ) {
                if ( $prefix !== '' && str_starts_with( $digits, $prefix ) ) {
                    $hits[ $code ] = true;
                }
            }
        }

        return count( $hits ) === 1 ? array_key_first( $hits ) : null;
    }

    /**
     * @param list<string> $allowed
     * @return array<string, list<string>>
     */
    public static function hints_for( array $allowed ): array {
        $out = [];
        foreach ( self::operator_prefixes() as $code => $prefixes ) {
            if ( $allowed && ! in_array( $code, $allowed, true ) ) {
                continue;
            }
            $out[ $code ] = $prefixes;
        }
        return $out;
    }
}
