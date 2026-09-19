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
     * Apply a trusted PawaPay status. Unsigned webhook JSON must not call this with source "webhook".
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public static function apply( WC_Order $order, array $payload, string $source = 'webhook', array $options = [] ): string {
        $data       = self::extract_deposit_payload( $payload );
        $status     = self::extract_status( $data );
        $deposit_id = sanitize_text_field( (string) ( $data['depositId'] ?? $order->get_meta( '_pawapay_deposit_id' ) ) );
        $logger     = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;
        $attempt    = ( $deposit_id !== '' && class_exists( 'WC_PawaPay_Attempt_Repository' ) )
            ? WC_PawaPay_Attempt_Repository::instance()->find_by_deposit_id( $deposit_id )
            : null;

        $requested = self::extract_amount( $data, 'requestedAmount' );
        $deposited = self::extract_amount( $data, 'depositedAmount' );
        $currency  = sanitize_text_field( (string) ( $data['currency'] ?? '' ) );

        $decision = WC_PawaPay_Completion_Policy::decide( [
            'source'             => $source,
            'pawapay_status'     => $status,
            'payment_method'     => $order->get_payment_method(),
            'order_paid'         => $order->is_paid(),
            'attempt_status'     => $attempt ? $attempt->status() : '',
            'frozen_amount'      => $attempt ? $attempt->payment_amount() : (string) $order->get_meta( '_pawapay_amount' ),
            'frozen_currency'    => $attempt ? $attempt->payment_currency() : (string) $order->get_meta( '_pawapay_currency' ),
            'requested_amount'   => $requested,
            'requested_currency' => $currency,
        ] );

        $extras = self::attempt_extras( $data );

        if ( $decision === 'reject_untrusted' ) {
            self::log( $logger, 'warning', $order, $deposit_id, 'Untrusted source refused to change payment state (' . $source . ').' );
            return $order->get_status();
        }

        if ( $decision === 'reject_method' ) {
            self::log( $logger, 'warning', $order, $deposit_id, 'Payment method is not pawapay.' );
            return $order->get_status();
        }

        if ( $decision === 'reject_amount' ) {
            self::log( $logger, 'warning', $order, $deposit_id, 'Amount or currency did not match the frozen attempt.' );
            if ( $attempt ) {
                WC_PawaPay_Attempt_Repository::instance()->mark_status(
                    $deposit_id,
                    WC_PawaPay_Attempt::STATUS_UNKNOWN,
                    $extras + [ 'failure_code' => 'AMOUNT_MISMATCH' ]
                );
            }
            $order->add_order_note( 'PawaPay reported COMPLETED but the amount/currency did not match this payment attempt. Manual review required.' );
            return $order->get_status();
        }

        if ( $decision === 'already' ) {
            if ( $attempt && ! $attempt->is_completed() ) {
                WC_PawaPay_Attempt_Repository::instance()->claim_completion( $deposit_id, $extras );
            }
            return $order->get_status();
        }

        if ( $decision === 'record_failed' ) {
            if ( $attempt ) {
                WC_PawaPay_Attempt_Repository::instance()->mark_status(
                    $deposit_id,
                    WC_PawaPay_Attempt::STATUS_FAILED,
                    $extras
                );
            }
            $code = sanitize_text_field( (string) ( $data['rejectionReason']['rejectionCode'] ?? ( $data['failureReason']['failureCode'] ?? 'UNKNOWN' ) ) );
            $msg  = sanitize_text_field( (string) ( $data['rejectionReason']['rejectionMessage'] ?? ( $data['failureReason']['failureMessage'] ?? 'Payment failed.' ) ) );
            $order->add_order_note( sprintf( 'PawaPay attempt FAILED (%s). [%s] %s The order stays payable.', $source, $code, $msg ) );
            if ( ! empty( $options['fail_woo_on_failed'] ) ) {
                $order->update_status( 'failed', sprintf( 'PawaPay payment FAILED (%s). [%s] %s', $source, $code, $msg ) );
            }
            self::log( $logger, 'info', $order, $deposit_id, 'Attempt failed via ' . $source . ': ' . $code );
            return $order->get_status();
        }

        if ( $decision === 'ignore' ) {
            if ( $attempt && in_array( $status, [ 'ACCEPTED', 'SUBMITTED', 'ENQUEUED' ], true ) ) {
                WC_PawaPay_Attempt_Repository::instance()->mark_status(
                    $deposit_id,
                    WC_PawaPay_Attempt::from_pawapay_status( $status ),
                    $extras
                );
            }
            return $order->get_status();
        }

        if ( $decision === 'complete' && $attempt ) {
            $claim = WC_PawaPay_Attempt_Repository::instance()->claim_completion( $deposit_id, $extras );
            if ( $claim === 'already' && $order->is_paid() ) {
                return $order->get_status();
            }
            if ( $claim === 'missing' ) {
                return $order->get_status();
            }
        }

        if ( in_array( $decision, [ 'complete', 'complete_order_only' ], true ) ) {
            $order->update_meta_data( '_pawapay_requested_amount', $requested );
            $order->update_meta_data( '_pawapay_deposited_amount', $deposited );
            if ( $requested !== '' && $deposited !== '' && $requested !== $deposited ) {
                $order->update_meta_data( '_pawapay_amount_discrepancy', 'yes' );
            }
            if ( $order->needs_payment() ) {
                $order->payment_complete( $deposit_id );
            }
            $amount_note = $requested !== '' ? $requested : $deposited;
            if ( $requested !== '' && $deposited !== '' && $requested !== $deposited ) {
                $amount_note = sprintf( '%s (deposited %s)', $requested, $deposited );
            }
            $order->add_order_note( sprintf(
                'PawaPay payment COMPLETED (%s). Deposit ID: %s | Amount: %s %s',
                $source,
                $deposit_id,
                $amount_note,
                $currency
            ) );
            self::log( $logger, 'info', $order, $deposit_id, 'COMPLETED via ' . $source );
        }

        return $order->get_status();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private static function attempt_extras( array $data ): array {
        $provider_tx = $data['providerTransactionId']
            ?? ( $data['correspondentIds']['providerTransactionId'] ?? ( $data['financialTransactionId'] ?? '' ) );

        return [
            'provider_transaction_id' => sanitize_text_field( (string) $provider_tx ),
            'failure_code'            => sanitize_text_field( (string) ( $data['rejectionReason']['rejectionCode'] ?? ( $data['failureReason']['failureCode'] ?? '' ) ) ),
            'failure_message'         => sanitize_text_field( (string) ( $data['rejectionReason']['rejectionMessage'] ?? ( $data['failureReason']['failureMessage'] ?? '' ) ) ),
        ];
    }

    /**
     * @param object|null $logger
     */
    private static function log( $logger, string $level, WC_Order $order, string $deposit_id, string $message ): void {
        if ( ! $logger || ! method_exists( $logger, $level ) ) {
            return;
        }
        $logger->{$level}(
            sprintf( '[PawaPay][Order %d][Deposit %s] %s', $order->get_id(), $deposit_id, $message ),
            [ 'source' => 'wc-pawapay' ]
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function sync_from_api( WC_Order $order, WC_PawaPay_Client $api, string $source = 'poll', array $options = [] ): string {
        return self::sync_deposit(
            $order,
            (string) $order->get_meta( '_pawapay_deposit_id' ),
            $api,
            $source,
            $options
        );
    }

    /**
     * GET one deposit id. Used by poll (latest meta) and reconciliation (attempt id).
     *
     * @param array<string, mixed> $options
     */
    public static function sync_deposit(
        WC_Order $order,
        string $deposit_id,
        WC_PawaPay_Client $api,
        string $source = 'poll',
        array $options = []
    ): string {
        $deposit_id = trim( $deposit_id );
        if ( $deposit_id === '' ) {
            return $order->get_status();
        }

        $payload = $api->check_deposit_status( $deposit_id );
        $code    = (int) ( $payload['_http_code'] ?? 0 );
        if ( isset( $payload['error'] ) || ( $code > 0 && ( $code < 200 || $code >= 300 ) ) ) {
            return $order->get_status();
        }

        $data = self::extract_deposit_payload( $payload );
        if ( empty( $data['depositId'] ) ) {
            if ( isset( $payload[0] ) && is_array( $payload[0] ) ) {
                $payload[0]['depositId'] = $deposit_id;
            } elseif ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
                $payload['data']['depositId'] = $deposit_id;
            } else {
                $payload['depositId'] = $deposit_id;
            }
        }

        return self::apply( $order, $payload, $source, $options );
    }
}
