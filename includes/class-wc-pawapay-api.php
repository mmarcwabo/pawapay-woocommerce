<?php
defined( 'ABSPATH' ) || exit;

/**
 * PawaPay Merchant API v1 client.
 *
 * Live deposits use POST /deposits (not /v2/deposits).
 */
class WC_PawaPay_API implements WC_PawaPay_Client {

    public const SANDBOX_URL     = 'https://api.sandbox.pawapay.io';
    public const PRODUCTION_URL  = 'https://api.pawapay.io';
    public const REQUEST_TIMEOUT = 30;
    public const API_VERSION     = 'v1';

    public const ERROR_CONNECTION   = 'connection';
    public const ERROR_TIMEOUT      = 'timeout';
    public const ERROR_HTTP         = 'http';
    public const ERROR_INVALID_JSON = 'invalid_json';

    private string $api_token;
    private string $base_url;
    private bool   $debug;

    public function __construct( string $api_token, bool $sandbox = false, bool $debug = false ) {
        $this->api_token = $api_token;
        $this->base_url  = $sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
        $this->debug     = $debug;
    }

    public static function deposit_path(): string {
        return '/deposits';
    }

    public static function deposit_status_path( string $deposit_id ): string {
        return '/deposits/' . rawurlencode( $deposit_id );
    }

    /**
     * PawaPay v1 statementDescription: 4–22 alphanumeric characters and spaces.
     */
    public static function sanitize_statement_description( string $description ): string {
        $clean = preg_replace( '/[^a-zA-Z0-9 ]/', ' ', $description ) ?? '';
        $clean = trim( (string) preg_replace( '/\s+/', ' ', $clean ) );

        if ( strlen( $clean ) < 4 ) {
            $clean = 'Order payment';
        }

        return substr( $clean, 0, 22 );
    }

    /**
     * @param array<string, string> $metadata
     * @return array<string, mixed>
     */
    public static function build_deposit_payload(
        string $deposit_id,
        string $amount,
        string $currency,
        string $mno,
        string $msisdn,
        string $description = 'Order payment',
        array $metadata = []
    ): array {
        $payload = [
            'depositId'            => $deposit_id,
            'amount'               => $amount,
            'currency'             => $currency,
            'correspondent'        => $mno,
            'payer'                => [
                'type'    => 'MSISDN',
                'address' => [ 'value' => $msisdn ],
            ],
            'customerTimestamp'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'statementDescription' => self::sanitize_statement_description( $description ),
        ];

        if ( $metadata === [] ) {
            return $payload;
        }

        $meta_array = [];
        foreach ( array_slice( $metadata, 0, 10 ) as $key => $value ) {
            $meta_array[] = [ 'fieldName' => (string) $key, 'fieldValue' => (string) $value ];
        }
        $payload['metadata'] = $meta_array;

        return $payload;
    }

    public static function is_timeout_message( string $message, string $code = '' ): bool {
        if ( $code === 'http_request_timeout' ) {
            return true;
        }
        return (bool) preg_match( '/timed?\s*out/i', $message );
    }

    /**
     * @return array{error: string, error_type: string, _http_code: int}
     */
    public static function normalize_transport_failure( string $message, string $code = '' ): array {
        return [
            'error'       => $message,
            'error_type'  => self::is_timeout_message( $message, $code ) ? self::ERROR_TIMEOUT : self::ERROR_CONNECTION,
            '_http_code'  => 0,
        ];
    }

    /**
     * @param mixed $data
     * @return array<string, mixed>
     */
    public static function normalize_http_response( int $code, $data ): array {
        if ( ! is_array( $data ) ) {
            return [
                'error'      => 'Invalid JSON from PawaPay.',
                'error_type' => self::ERROR_INVALID_JSON,
                '_http_code' => $code,
            ];
        }

        $data['_http_code'] = $code;
        if ( $code >= 500 ) {
            $data['error_type'] = $data['error_type'] ?? self::ERROR_HTTP;
            if ( ! isset( $data['error'] ) ) {
                $data['error'] = $data['errorMessage'] ?? ( $data['message'] ?? 'PawaPay server error.' );
            }
        }

        return $data;
    }

    /**
     * @param array<string, string> $metadata
     * @return array<string, mixed>
     */
    public function initiate_deposit(
        string $deposit_id,
        string $amount,
        string $currency,
        string $mno,
        string $msisdn,
        string $description = 'Order payment',
        array $metadata = []
    ): array {
        return $this->request(
            'POST',
            self::deposit_path(),
            self::build_deposit_payload( $deposit_id, $amount, $currency, $mno, $msisdn, $description, $metadata )
        );
    }

    public function check_deposit_status( string $deposit_id ): array {
        return $this->request( 'GET', self::deposit_status_path( $deposit_id ) );
    }

    public function get_availability(): array {
        return $this->request( 'GET', '/availability' );
    }

    public static function active_conf_path(): string {
        return '/active-conf';
    }

    public function get_active_configuration(): array {
        return $this->request( 'GET', self::active_conf_path() );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function request( string $method, string $endpoint, array $body = [] ): array {
        $url  = $this->base_url . $endpoint;
        $args = [
            'method'      => $method,
            'timeout'     => self::REQUEST_TIMEOUT,
            'redirection' => 0,
            'headers'     => [
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];

        if ( $method === 'POST' && $body !== [] ) {
            $args['body'] = wp_json_encode( $body );
        }

        if ( $this->debug ) {
            wc_get_logger()->debug(
                '[PawaPay] → ' . $method . ' ' . $url . ' | Payload: ' . wp_json_encode( self::redact_for_log( $body ) ),
                [ 'source' => 'wc-pawapay' ]
            );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $normalized = self::normalize_transport_failure(
                $response->get_error_message(),
                (string) $response->get_error_code()
            );
            $this->log( 'WP_Error: ' . $normalized['error'] );
            return $normalized;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $this->debug ) {
            $logged = is_array( $data )
                ? wp_json_encode( self::redact_for_log( $data ) )
                : '[unparsed body omitted]';
            wc_get_logger()->debug(
                '[PawaPay] ← HTTP ' . $code . ' | Body: ' . $logged,
                [ 'source' => 'wc-pawapay' ]
            );
        }

        return self::normalize_http_response( $code, $data );
    }

    private function log( string $message ): void {
        wc_get_logger()->error( '[PawaPay] ' . $message, [ 'source' => 'wc-pawapay' ] );
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @return array<string, mixed>|list<mixed>
     */
    public static function redact_for_log( array $payload ): array {
        foreach ( $payload as $key => $value ) {
            if ( self::is_secret_key( (string) $key ) ) {
                $payload[ $key ] = '[redacted]';
                continue;
            }
            if ( is_array( $value ) ) {
                $payload[ $key ] = self::redact_for_log( $value );
            }
        }

        if ( isset( $payload['payer']['address']['value'] ) ) {
            $payload['payer']['address']['value'] = self::mask_msisdn( (string) $payload['payer']['address']['value'] );
        }

        return $payload;
    }

    public static function is_secret_key( string $key ): bool {
        return in_array( strtolower( $key ), [
            'authorization',
            'api_token',
            'apitoken',
            'access_token',
            'accesstoken',
            'client_secret',
            'token',
            'password',
            'secret',
        ], true );
    }

    public static function mask_msisdn( string $msisdn ): string {
        $digits = preg_replace( '/\D+/', '', $msisdn ) ?? '';
        if ( strlen( $digits ) <= 4 ) {
            return '****';
        }
        return str_repeat( '*', strlen( $digits ) - 4 ) . substr( $digits, -4 );
    }

    public static function generate_uuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }
}
