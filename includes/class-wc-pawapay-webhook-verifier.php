<?php
defined( 'ABSPATH' ) || exit;

/**
 * Callback authenticity checks.
 *
 * Content-Digest and Signature-Date are verified here.
 * Full RFC 9421 ECDSA verification is accepted only when a public key is supplied
 * and openssl can verify; otherwise signed mode still requires GET /deposits/{id}.
 */
class WC_PawaPay_Webhook_Verifier {

    public const DATE_TOLERANCE = 300;

    /**
     * @param array<string, string> $headers
     */
    public static function header( array $headers, string $name ): string {
        foreach ( $headers as $key => $value ) {
            if ( strtolower( (string) $key ) === strtolower( $name ) ) {
                return is_array( $value ) ? (string) ( $value[0] ?? '' ) : (string) $value;
            }
        }
        return '';
    }

    public static function content_digest( string $body, string $algorithm = 'sha-256' ): string {
        $algo = strtolower( $algorithm ) === 'sha-512' ? 'sha512' : 'sha256';
        $name = $algo === 'sha512' ? 'sha-512' : 'sha-256';
        return $name . '=:' . base64_encode( hash( $algo, $body, true ) ) . ':';
    }

    public static function digest_matches( string $body, string $header ): bool {
        $header = trim( $header );
        if ( $header === '' || ! preg_match( '/^(sha-256|sha-512)=:(.+):$/i', $header, $m ) ) {
            return false;
        }

        $alg      = strtolower( $m[1] ) === 'sha-512' ? 'sha-512' : 'sha-256';
        $computed = self::content_digest( $body, $alg );
        $given    = $alg . '=:' . $m[2] . ':';
        return hash_equals( $computed, $given );
    }

    public static function signature_date_is_fresh( string $date, int $now = 0, int $tolerance = self::DATE_TOLERANCE ): bool {
        $date = trim( $date );
        if ( $date === '' ) {
            return false;
        }
        $ts = strtotime( $date );
        if ( $ts === false ) {
            return false;
        }
        $now = $now > 0 ? $now : time();
        return abs( $now - $ts ) <= $tolerance;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function has_signature_headers( array $headers ): bool {
        return self::header( $headers, 'signature' ) !== ''
            && self::header( $headers, 'signature-input' ) !== ''
            && self::header( $headers, 'content-digest' ) !== '';
    }

    /**
     * @param array<string, string> $headers
     * @return array{ok: bool, reason: string}
     */
    public static function verify_signed_gate( string $body, array $headers, int $now = 0 ): array {
        $digest = self::header( $headers, 'content-digest' );
        if ( ! self::digest_matches( $body, $digest ) ) {
            return [ 'ok' => false, 'reason' => 'digest' ];
        }

        $date = self::header( $headers, 'signature-date' );
        if ( $date === '' ) {
            $date = self::header( $headers, 'date' );
        }
        if ( ! self::signature_date_is_fresh( $date, $now ) ) {
            return [ 'ok' => false, 'reason' => 'date' ];
        }

        if ( ! self::has_signature_headers( $headers ) ) {
            return [ 'ok' => false, 'reason' => 'signature' ];
        }

        return [ 'ok' => true, 'reason' => 'gate' ];
    }
}
