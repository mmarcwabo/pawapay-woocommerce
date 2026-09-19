<?php
defined( 'ABSPATH' ) || exit;

/**
 * PawaPay transport contract.
 *
 * Application code depends on this, not on a v1 or v2 URL.
 * Live traffic uses WC_PawaPay_API (Merchant API v1 POST /deposits).
 */
interface WC_PawaPay_Client {

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
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function check_deposit_status( string $deposit_id ): array;
}
