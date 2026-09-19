<?php
defined( 'ABSPATH' ) || exit;

class WC_PawaPay_Initiation_Command {

    /**
     * @param list<string> $allowed_providers
     * @param list<string> $currency_choices
     */
    public function __construct(
        public int $order_id,
        public string $order_currency,
        public float $order_total,
        public string $phone,
        public string $mno,
        public string $posted_currency,
        public array $allowed_providers,
        public array $currency_choices,
        public int $customer_id = 0,
        public string $site = '',
        public string $plugin_version = ''
    ) {}
}

class WC_PawaPay_Initiation_Result {

    public function __construct(
        public string $outcome,
        public string $woo_result,
        public string $notice = '',
        public string $notice_type = 'error',
        public bool $empty_cart = false,
        public bool $set_pending = false,
        public ?WC_PawaPay_Attempt $attempt = null,
        public string $phone = '',
        public string $note = ''
    ) {}
}

/**
 * Server-side payment initiation. Amounts come from the Woo order only.
 */
class WC_PawaPay_Payment_Service {

    /** @var callable(float, string, string): (?float) */
    private $convert;

    /** @var callable(float, string, string): string */
    private $format_amount;

    /** @var callable(): string */
    private $uuid;

    public function __construct(
        private WC_PawaPay_Attempt_Repository $repository,
        private WC_PawaPay_Client $client,
        private WC_PawaPay_Initiation_Lock $lock,
        callable $convert,
        callable $format_amount,
        ?callable $uuid = null
    ) {
        $this->convert       = $convert;
        $this->format_amount = $format_amount;
        $this->uuid          = $uuid ?? [ 'WC_PawaPay_API', 'generate_uuid' ];
    }

    public function initiate( WC_PawaPay_Initiation_Command $command ): WC_PawaPay_Initiation_Result {
        $phone_error = WC_PawaPay_Initiation_Policy::validate_phone( $command->phone );
        if ( $phone_error !== '' ) {
            return $this->fail( 'invalid', $phone_error );
        }

        $provider_error = WC_PawaPay_Initiation_Policy::validate_provider( $command->mno, $command->allowed_providers );
        if ( $provider_error !== '' ) {
            return $this->fail( 'invalid', $provider_error );
        }

        $currency  = WC_PawaPay_Initiation_Policy::resolve_currency(
            $command->posted_currency,
            $command->order_currency,
            $command->currency_choices
        );
        $converted = ( $this->convert )( $command->order_total, $command->order_currency, $currency );
        if ( $converted === null ) {
            return $this->fail(
                'invalid',
                'No exchange rate is available for that payment currency. Add a rate in PawaPay settings or install a currency switcher.'
            );
        }

        $amount = ( $this->format_amount )( $converted, $currency, $command->mno );
        $hash   = WC_PawaPay_Attempt::hash_msisdn( $command->phone );

        foreach ( $this->repository->find_for_order( $command->order_id ) as $existing ) {
            if ( $existing->is_completed() ) {
                return $this->fail(
                    'blocked',
                    'This order is already paid. Do not send another payment.'
                );
            }
        }

        $active = $this->repository->find_active_for_order( $command->order_id );
        $reuse  = WC_PawaPay_Initiation_Policy::find_reusable( $active, $hash, $command->mno, $amount, $currency );

        if ( $reuse ) {
            return $this->reuse( $reuse, $command->phone );
        }
        if ( $active !== [] ) {
            return $this->fail(
                'blocked',
                'A payment request is already in progress for this order. Please wait for confirmation. Do not pay again.'
            );
        }

        if ( ! $this->lock->acquire( $command->order_id ) ) {
            $active = $this->repository->find_active_for_order( $command->order_id );
            $reuse  = WC_PawaPay_Initiation_Policy::find_reusable( $active, $hash, $command->mno, $amount, $currency );
            if ( $reuse ) {
                return $this->reuse( $reuse, $command->phone );
            }
            return $this->fail(
                'blocked',
                'A payment request is already starting. Please wait a moment.'
            );
        }

        try {
            $attempt = $this->create_attempt( $command, $currency, $amount, $converted );
            if ( ! $this->repository->insert( $attempt ) ) {
                return $this->fail( 'invalid', 'Could not start this payment. Please try again.' );
            }

            $response = $this->client->initiate_deposit(
                $attempt->deposit_id(),
                $amount,
                $currency,
                $command->mno,
                $command->phone,
                'Order ' . $command->order_id,
                $this->metadata( $command )
            );

            return $this->finish( $attempt, $command->phone, $response );
        } finally {
            $this->lock->release( $command->order_id );
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private function finish( WC_PawaPay_Attempt $attempt, string $phone, array $response ): WC_PawaPay_Initiation_Result {
        $outcome = WC_PawaPay_Initiation_Policy::classify_response( $response );

        if ( $outcome === 'accepted' ) {
            $this->repository->mark_status( $attempt->deposit_id(), WC_PawaPay_Attempt::STATUS_ACCEPTED );
            $fresh = $this->repository->find_by_deposit_id( $attempt->deposit_id() ) ?? $attempt;
            return new WC_PawaPay_Initiation_Result(
                outcome: 'accepted',
                woo_result: 'success',
                notice: '',
                notice_type: 'success',
                empty_cart: true,
                set_pending: true,
                attempt: $fresh,
                phone: $phone,
                note: sprintf(
                    'PawaPay deposit initiated. Deposit ID: %s | Phone: %s | MNO: %s | Amount: %s %s',
                    $fresh->deposit_id(),
                    $fresh->masked_msisdn(),
                    $fresh->provider(),
                    $fresh->payment_amount(),
                    $fresh->payment_currency()
                )
            );
        }

        if ( $outcome === 'rejected' ) {
            $code = (string) ( $response['rejectionReason']['rejectionCode'] ?? '' );
            $msg  = (string) ( $response['rejectionReason']['rejectionMessage'] ?? ( $response['message'] ?? 'Payment rejected.' ) );
            $this->repository->mark_status( $attempt->deposit_id(), WC_PawaPay_Attempt::STATUS_FAILED, [
                'failure_code'    => $code,
                'failure_message' => $msg,
            ] );
            $fresh  = $this->repository->find_by_deposit_id( $attempt->deposit_id() ) ?? $attempt;
            $notice = trim( $code . ( $msg !== '' ? ' — ' . $msg : '' ) );
            return new WC_PawaPay_Initiation_Result(
                outcome: 'rejected',
                woo_result: 'failure',
                notice: 'Payment declined: ' . $notice,
                notice_type: 'error',
                attempt: $fresh,
                phone: $phone,
                note: 'PawaPay rejected: ' . $notice
            );
        }

        $msg = (string) ( $response['error'] ?? ( $response['errorMessage'] ?? ( $response['message'] ?? 'PawaPay did not confirm immediately.' ) ) );
        $this->repository->mark_status( $attempt->deposit_id(), WC_PawaPay_Attempt::STATUS_UNKNOWN, [
            'failure_code'    => (string) ( $response['error_type'] ?? 'UNKNOWN' ),
            'failure_message' => $msg,
        ] );
        $fresh = $this->repository->find_by_deposit_id( $attempt->deposit_id() ) ?? $attempt;

        return new WC_PawaPay_Initiation_Result(
            outcome: 'unknown',
            woo_result: 'success',
            notice: 'We sent a payment request and are waiting for confirmation. Do not pay again.',
            notice_type: 'notice',
            empty_cart: true,
            set_pending: true,
            attempt: $fresh,
            phone: $phone,
            note: sprintf(
                'PawaPay initiation is unconfirmed (%s). Deposit ID: %s | Phone: %s. Reconcile; do not treat as failed.',
                $fresh->failure_code() ?: 'UNKNOWN',
                $fresh->deposit_id(),
                $fresh->masked_msisdn()
            )
        );
    }

    private function reuse( WC_PawaPay_Attempt $attempt, string $phone ): WC_PawaPay_Initiation_Result {
        return new WC_PawaPay_Initiation_Result(
            outcome: 'reuse',
            woo_result: 'success',
            notice: 'This payment is already in progress. Waiting for confirmation.',
            notice_type: 'notice',
            empty_cart: true,
            set_pending: true,
            attempt: $attempt,
            phone: $phone,
            note: ''
        );
    }

    private function fail( string $outcome, string $notice ): WC_PawaPay_Initiation_Result {
        return new WC_PawaPay_Initiation_Result(
            outcome: $outcome,
            woo_result: 'failure',
            notice: $notice,
            notice_type: 'error'
        );
    }

    private function create_attempt(
        WC_PawaPay_Initiation_Command $command,
        string $currency,
        string $amount,
        float $converted
    ): WC_PawaPay_Attempt {
        $rate = ( strtoupper( $command->order_currency ) === $currency || $command->order_total <= 0.0 )
            ? '1'
            : WC_PawaPay_Attempt::format_decimal( $converted / $command->order_total );

        return WC_PawaPay_Attempt::create( [
            'order_id'         => $command->order_id,
            'deposit_id'       => ( $this->uuid )(),
            'provider'         => $command->mno,
            'msisdn'           => $command->phone,
            'order_currency'   => strtoupper( $command->order_currency ),
            'order_amount'     => (string) $command->order_total,
            'payment_currency' => $currency,
            'payment_amount'   => $amount,
            'exchange_rate'    => $rate,
            'status'           => WC_PawaPay_Attempt::STATUS_INITIATING,
        ] );
    }

    /**
     * @return array<string, string>
     */
    private function metadata( WC_PawaPay_Initiation_Command $command ): array {
        $meta = [
            'orderId'  => (string) $command->order_id,
            'site'     => $command->site,
        ];
        if ( $command->customer_id > 0 ) {
            $meta['customerId'] = (string) $command->customer_id;
        }
        if ( $command->plugin_version !== '' ) {
            $meta['pluginVersion'] = $command->plugin_version;
        }
        return $meta;
    }
}
