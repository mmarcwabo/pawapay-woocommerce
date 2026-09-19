<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shared deposit status handling for webhooks and thank-you polling.
 */
class WC_PawaPay_Deposit {

    public static function find_order( string $deposit_id ): ?WC_Order {
        if ( class_exists( 'WC_PawaPay_Attempt_Repository' ) ) {
            $attempt = WC_PawaPay_Attempt_Repository::instance()->find_by_deposit_id( $deposit_id );
            if ( $attempt ) {
                $order = wc_get_order( $attempt->order_id() );
                if ( $order instanceof WC_Order ) {
                    return $order;
                }
            }
        }

        $orders = wc_get_orders( [
            'meta_key'   => '_pawapay_deposit_id',
            'meta_value' => $deposit_id,
            'limit'      => 1,
        ] );

        $order = $orders[0] ?? null;
        if ( $order instanceof WC_Order && class_exists( 'WC_PawaPay_Attempt_Repository' ) ) {
            self::backfill_attempt( $order );
        }

        return $order;
    }

    public static function backfill_attempt( WC_Order $order ): void {
        WC_PawaPay_Attempt_Repository::instance()->backfill_from_meta(
            (int) $order->get_id(),
            [
                '_pawapay_deposit_id' => $order->get_meta( '_pawapay_deposit_id' ),
                '_pawapay_phone'      => $order->get_meta( '_pawapay_phone' ),
                '_pawapay_mno'        => $order->get_meta( '_pawapay_mno' ),
                '_pawapay_currency'   => $order->get_meta( '_pawapay_currency' ),
                '_pawapay_amount'     => $order->get_meta( '_pawapay_amount' ),
            ],
            $order->is_paid()
        );
    }

    public static function extract_status( array $payload ): string {
        if ( ! empty( $payload['status'] ) && is_string( $payload['status'] ) ) {
            return strtoupper( $payload['status'] );
        }

        if ( isset( $payload[0] ) && is_array( $payload[0] ) && ! empty( $payload[0]['status'] ) ) {
            return strtoupper( (string) $payload[0]['status'] );
        }

        if ( isset( $payload['data']['status'] ) ) {
            return strtoupper( (string) $payload['data']['status'] );
        }

        return '';
    }

    public static function extract_deposit_payload( array $payload ): array {
        if ( isset( $payload[0] ) && is_array( $payload[0] ) ) {
            return $payload[0];
        }
        if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
            return $payload['data'];
        }
        return $payload;
    }

    public static function extract_amount( array $payload, string $prefer = 'requestedAmount' ): string {
        $data = self::extract_deposit_payload( $payload );
        foreach ( [ $prefer, 'requestedAmount', 'depositedAmount', 'amount' ] as $key ) {
            if ( isset( $data[ $key ] ) && $data[ $key ] !== '' && $data[ $key ] !== null ) {
                return trim( (string) $data[ $key ] );
            }
        }
        return '';
    }

    /**
     * Apply a final or intermediate PawaPay status to a Woo order.
     *
     * @return string Woo status after apply (pending|processing|failed|…)
     */
    public static function apply( WC_Order $order, array $payload, string $source = 'webhook' ): string {
        $data   = self::extract_deposit_payload( $payload );
        $status = self::extract_status( $data );
        $logger = wc_get_logger();

        $deposit_id = sanitize_text_field( $data['depositId'] ?? $order->get_meta( '_pawapay_deposit_id' ) );
        self::sync_attempt( $deposit_id, $data, $status );

        $current = $order->get_status();
        if ( in_array( $current, [ 'completed', 'processing', 'failed', 'cancelled', 'refunded' ], true ) ) {
            return $current;
        }

        switch ( $status ) {
            case 'COMPLETED':
                $requested = self::extract_amount( $data, 'requestedAmount' );
                $deposited = self::extract_amount( $data, 'depositedAmount' );
                $currency  = sanitize_text_field( $data['currency'] ?? '' );
                $order->update_meta_data( '_pawapay_requested_amount', $requested );
                $order->update_meta_data( '_pawapay_deposited_amount', $deposited );
                if ( $requested !== '' && $deposited !== '' && $requested !== $deposited ) {
                    $order->update_meta_data( '_pawapay_amount_discrepancy', 'yes' );
                }
                $order->payment_complete( $deposit_id );
                $amount_note = $requested !== '' ? $requested : $deposited;
                if ( $requested !== '' && $deposited !== '' && $requested !== $deposited ) {
                    $amount_note = sprintf( '%s (deposited %s)', $requested, $deposited );
                }
                $order->add_order_note( sprintf(
                    'PawaPay payment COMPLETED (%s). Deposit ID: %s | MNO: %s | Amount: %s %s',
                    $source,
                    $deposit_id,
                    sanitize_text_field( $data['correspondent'] ?? $order->get_meta( '_pawapay_mno' ) ?: 'N/A' ),
                    $amount_note,
                    $currency
                ) );
                $logger->info( '[PawaPay] Order #' . $order->get_id() . ' COMPLETED via ' . $source, [ 'source' => 'wc-pawapay' ] );
                break;

            case 'FAILED':
            case 'REJECTED':
                $reject_code    = sanitize_text_field( $data['rejectionReason']['rejectionCode'] ?? ( $data['failureReason']['failureCode'] ?? 'UNKNOWN' ) );
                $reject_message = sanitize_text_field( $data['rejectionReason']['rejectionMessage'] ?? ( $data['failureReason']['failureMessage'] ?? 'Payment failed.' ) );
                $order->update_status( 'failed', sprintf(
                    'PawaPay payment FAILED (%s). Reason: [%s] %s',
                    $source,
                    $reject_code,
                    $reject_message
                ) );
                $logger->info( '[PawaPay] Order #' . $order->get_id() . ' FAILED via ' . $source . ': ' . $reject_code, [ 'source' => 'wc-pawapay' ] );
                break;

            case 'ACCEPTED':
            case 'SUBMITTED':
            case 'ENQUEUED':
                $order->add_order_note( sprintf( 'PawaPay status %s (%s).', $status, $source ) );
                break;

            case '':
                $logger->warning( '[PawaPay] Empty status for order #' . $order->get_id() . ' via ' . $source, [ 'source' => 'wc-pawapay' ] );
                break;

            default:
                $order->add_order_note( 'PawaPay returned status ' . $status . ' (' . $source . '). Manual review required.' );
                $logger->error( sprintf(
                    '[PawaPay] Unknown status "%s" for order #%s via %s',
                    $status,
                    $order->get_id(),
                    $source
                ), [ 'source' => 'wc-pawapay' ] );
                break;
        }

        return $order->get_status();
    }

    /**
     * Keep the attempt row in sync. Does not decide Woo paid state.
     *
     * @param array<string, mixed> $data
     */
    private static function sync_attempt( string $deposit_id, array $data, string $pawapay_status ): void {
        if ( $deposit_id === '' || ! class_exists( 'WC_PawaPay_Attempt_Repository' ) ) {
            return;
        }

        $provider_tx = $data['providerTransactionId']
            ?? ( $data['correspondentIds']['providerTransactionId'] ?? ( $data['financialTransactionId'] ?? '' ) );

        WC_PawaPay_Attempt_Repository::instance()->mark_status(
            $deposit_id,
            WC_PawaPay_Attempt::from_pawapay_status( $pawapay_status ),
            [
                'provider_transaction_id' => sanitize_text_field( (string) $provider_tx ),
                'failure_code'            => sanitize_text_field( (string) ( $data['rejectionReason']['rejectionCode'] ?? ( $data['failureReason']['failureCode'] ?? '' ) ) ),
                'failure_message'         => sanitize_text_field( (string) ( $data['rejectionReason']['rejectionMessage'] ?? ( $data['failureReason']['failureMessage'] ?? '' ) ) ),
            ]
        );
    }

    public static function sync_from_api( WC_Order $order, WC_PawaPay_Client $api ): string {
        $deposit_id = (string) $order->get_meta( '_pawapay_deposit_id' );
        if ( $deposit_id === '' ) {
            return $order->get_status();
        }

        $payload = $api->check_deposit_status( $deposit_id );
        if ( isset( $payload['error'] ) ) {
            return $order->get_status();
        }

        return self::apply( $order, $payload, 'poll' );
    }
}
