<?php
defined( 'ABSPATH' ) || exit;

/**
 * Public callback: never take paid/failed state from the posted JSON.
 * Confirm with GET /deposits/{id}. Signed mode only gates authenticity.
 */
class WC_PawaPay_Callback_Processor {

    public function __construct(
        private WC_PawaPay_Client $client,
        private bool $require_signed = false
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     * @return array{ok: bool, http: int, reason: string, deposit_id: string, lookup: array<string, mixed>|null}
     */
    public function resolve( array $data, string $raw_body, array $headers ): array {
        $payload    = WC_PawaPay_Deposit::extract_deposit_payload( $data );
        $deposit_id = trim( (string) ( $payload['depositId'] ?? ( $data['depositId'] ?? '' ) ) );

        if ( $deposit_id === '' ) {
            return [ 'ok' => false, 'http' => 400, 'reason' => 'missing_deposit', 'deposit_id' => '', 'lookup' => null ];
        }

        if ( $this->require_signed ) {
            $gate = WC_PawaPay_Webhook_Verifier::verify_signed_gate( $raw_body, $headers );
            if ( ! $gate['ok'] ) {
                return [ 'ok' => false, 'http' => 401, 'reason' => 'unsigned_or_invalid', 'deposit_id' => $deposit_id, 'lookup' => null ];
            }
        }

        $lookup = $this->client->check_deposit_status( $deposit_id );
        $code   = (int) ( $lookup['_http_code'] ?? 0 );
        if ( isset( $lookup['error'] ) || ( $code > 0 && ( $code < 200 || $code >= 300 ) ) ) {
            return [ 'ok' => false, 'http' => 503, 'reason' => 'status_lookup_failed', 'deposit_id' => $deposit_id, 'lookup' => null ];
        }

        return [ 'ok' => true, 'http' => 200, 'reason' => 'lookup', 'deposit_id' => $deposit_id, 'lookup' => $lookup ];
    }
}
