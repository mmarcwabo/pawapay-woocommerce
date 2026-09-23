<?php
defined( 'ABSPATH' ) || exit;

class WC_PawaPay_Thankyou {

    public static function init(): void {
        add_action( 'woocommerce_thankyou_pawapay', [ self::class, 'render' ], 5 );
        add_action( 'woocommerce_before_checkout_form', [ self::class, 'render_order_pay' ], 5 );
        add_filter( 'body_class', [ self::class, 'body_class' ] );
        add_action( 'wp_ajax_wc_pawapay_poll', [ self::class, 'ajax_poll' ] );
        add_action( 'wp_ajax_nopriv_wc_pawapay_poll', [ self::class, 'ajax_poll' ] );
    }

    public static function register_rest(): void {
        register_rest_route(
            'pawapay/v1',
            '/poll',
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'rest_poll' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * In-app wait screen. Auth is Woo order key (same as thank-you ajax).
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_poll( WP_REST_Request $request ) {
        $dto = self::poll_for_customer(
            absint( $request->get_param( 'order_id' ) ),
            sanitize_text_field( (string) $request->get_param( 'order_key' ) )
        );
        if ( is_wp_error( $dto ) ) {
            return $dto;
        }

        return rest_ensure_response( $dto );
    }

    /**
     * @param list<string> $classes
     * @return list<string>
     */
    public static function body_class( array $classes ): array {
        $order = self::current_order();
        if ( ! $order ) {
            return $classes;
        }

        $dto = self::dto_for_order( $order );
        if ( ( $dto['phase'] ?? '' ) === WC_PawaPay_Poll_Policy::PHASE_WAITING ) {
            $classes[] = 'pawapay-is-waiting';
        }

        return $classes;
    }

    public static function render_order_pay(): void {
        if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
            return;
        }

        $order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        self::render( (int) $order->get_id() );
    }

    public static function render( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order || $order->get_payment_method() !== 'pawapay' ) {
            return;
        }

        $dto = self::dto_for_order( $order );
        if ( ( $dto['phase'] ?? '' ) === WC_PawaPay_Poll_Policy::PHASE_PAID ) {
            return;
        }

        self::print_card( $dto );
        if ( WC_PawaPay_Poll_Policy::should_poll( (string) ( $dto['phase'] ?? '' ) ) ) {
            self::enqueue( $order, $dto );
        }
    }

    public static function ajax_poll(): void {
        $order_id  = absint( $_POST['order_id'] ?? 0 );
        $order_key = sanitize_text_field( wp_unslash( $_POST['order_key'] ?? '' ) );
        $nonce     = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );

        if ( ! $order_id || ! wp_verify_nonce( $nonce, 'wc_pawapay_poll_' . $order_id ) ) {
            wp_send_json_error( [ 'message' => 'invalid' ], 403 );
        }

        $dto = self::poll_for_customer( $order_id, $order_key );
        if ( is_wp_error( $dto ) ) {
            $data   = $dto->get_error_data();
            $status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 404;
            wp_send_json_error( [ 'message' => $dto->get_error_code() ], $status );
        }

        wp_send_json_success( $dto );
    }

    /**
     * Server-side PawaPay GET, then a customer-safe DTO. Never returns tokens.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function poll_for_customer( int $order_id, string $order_key ) {
        if ( ! WC_PawaPay_Poll_Policy::rest_args_valid( $order_id, $order_key ) ) {
            return new WP_Error( 'pawapay_poll_invalid', 'invalid', [ 'status' => 400 ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $order_key ) || $order->get_payment_method() !== 'pawapay' ) {
            return new WP_Error( 'pawapay_poll_not_found', 'not_found', [ 'status' => 404 ] );
        }

        if ( $order->has_status( 'pending' ) && self::claim_poll_lookup( $order_id ) ) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            $gateway  = $gateways['pawapay'] ?? null;
            if ( $gateway instanceof WC_PawaPay_Gateway ) {
                WC_PawaPay_Deposit::sync_from_api(
                    $order,
                    $gateway->get_api(),
                    'poll',
                    [ 'fail_woo_on_failed' => $gateway->get_option( 'fail_woo_on_failed_deposit' ) === 'yes' ]
                );
                $order = wc_get_order( $order_id ) ?: $order;
            }
        }

        return self::dto_for_order( $order );
    }

    /**
     * @param array<string, mixed> $dto
     */
    private static function enqueue( WC_Order $order, array $dto ): void {
        wp_enqueue_style(
            'wc-pawapay-checkout',
            WC_PAWAPAY_PLUGIN_URL . 'assets/css/pawapay-checkout.css',
            [],
            WC_PAWAPAY_VERSION
        );

        wp_enqueue_script(
            'wc-pawapay-thankyou',
            WC_PAWAPAY_PLUGIN_URL . 'assets/js/pawapay-thankyou.js',
            [],
            WC_PAWAPAY_VERSION,
            true
        );

        wp_localize_script(
            'wc-pawapay-thankyou',
            'wcPawapayThankyou',
            [
                'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
                'orderId'             => $order->get_id(),
                'orderKey'            => $order->get_order_key(),
                'nonce'               => wp_create_nonce( 'wc_pawapay_poll_' . $order->get_id() ),
                'schedule'            => WC_PawaPay_Poll_Policy::schedule(),
                'timeoutMessage'      => __( WC_PawaPay_Poll_Policy::still_waiting_message(), 'wc-pawapay' ),
                'retryLabel'          => __( 'Try another number', 'wc-pawapay' ),
                'onPayPage'           => function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ),
                'initial'             => $dto,
            ]
        );
    }

    /**
     * @param array<string, mixed> $dto
     */
    private static function print_card( array $dto ): void {
        $phase    = (string) ( $dto['phase'] ?? WC_PawaPay_Poll_Policy::PHASE_WAITING );
        $title    = self::title_for( $phase );
        $provider = (string) ( $dto['provider'] ?? '' );
        $masked   = (string) ( $dto['masked_phone'] ?? '' );
        $amount   = trim( (string) ( $dto['amount'] ?? '' ) . ' ' . (string) ( $dto['currency'] ?? '' ) );

        echo '<section class="pawapay-waiting" id="pawapay-waiting" data-phase="' . esc_attr( $phase ) . '">';
        echo '<p class="pawapay-waiting-kicker">' . esc_html__( 'Mobile Money', 'wc-pawapay' ) . '</p>';
        echo '<h2 class="pawapay-waiting-title">' . esc_html( $title ) . '</h2>';

        if ( $phase === WC_PawaPay_Poll_Policy::PHASE_WAITING ) {
            echo '<p class="pawapay-waiting-copy">';
            echo esc_html( sprintf(
                __( 'Approve the payment on %1$s for the number ending %2$s.', 'wc-pawapay' ),
                $provider !== '' ? $provider : __( 'your mobile money wallet', 'wc-pawapay' ),
                self::last4( $masked )
            ) );
            echo '</p>';
        }

        if ( $amount !== '' ) {
            echo '<p class="pawapay-waiting-amount">' . esc_html( $amount ) . '</p>';
        }

        echo '<p class="pawapay-waiting-status" id="pawapay-waiting-status">' . esc_html( (string) ( $dto['message'] ?? '' ) ) . '</p>';

        $on_pay = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' );
        if ( ! $on_pay && ! empty( $dto['can_retry'] ) && ! empty( $dto['pay_url'] ) ) {
            echo '<p class="pawapay-waiting-actions">';
            echo '<a class="button" id="pawapay-retry-link" href="' . esc_url( (string) $dto['pay_url'] ) . '">';
            echo esc_html__( 'Try another number', 'wc-pawapay' );
            echo '</a></p>';
        }

        echo '</section>';
    }

    /**
     * @return array<string, mixed>
     */
    private static function dto_for_order( WC_Order $order ): array {
        $attempt  = self::latest_attempt( (int) $order->get_id() );
        $masked   = self::safe_masked_phone( $attempt, (string) $order->get_meta( '_pawapay_phone' ) );
        $provider = '';
        $amount   = (string) $order->get_total();
        $currency = strtoupper( (string) $order->get_currency() );

        if ( $attempt ) {
            $row      = WC_PawaPay_Providers::find( $attempt->provider() );
            $provider = (string) ( $row['label'] ?? $attempt->provider() );
            if ( $attempt->payment_amount() !== '' ) {
                $amount = $attempt->payment_amount();
            }
            if ( $attempt->payment_currency() !== '' ) {
                $currency = $attempt->payment_currency();
            }
        }

        $dto = WC_PawaPay_Poll_Policy::dto( [
            'woo_status'     => $order->get_status(),
            'order_paid'     => $order->is_paid(),
            'attempt_status' => $attempt ? $attempt->status() : '',
            'masked_phone'   => $masked,
            'provider'       => $provider,
            'amount'         => $amount,
            'currency'       => $currency,
            'pay_url'        => $order->get_checkout_payment_url(),
        ] );

        $dto['message'] = __( (string) $dto['message'], 'wc-pawapay' );

        return $dto;
    }

    private static function latest_attempt( int $order_id ): ?WC_PawaPay_Attempt {
        $attempts = WC_PawaPay_Attempt_Repository::instance()->find_for_order( $order_id );
        if ( ! $attempts ) {
            return null;
        }

        return $attempts[ array_key_last( $attempts ) ];
    }

    private static function current_order(): ?WC_Order {
        if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
            return null;
        }

        $order_id = 0;
        if ( is_wc_endpoint_url( 'order-received' ) ) {
            $order_id = absint( get_query_var( 'order-received' ) );
        } elseif ( is_wc_endpoint_url( 'order-pay' ) ) {
            $order_id = absint( get_query_var( 'order-pay' ) );
        }

        $order = $order_id ? wc_get_order( $order_id ) : null;
        if ( ! $order instanceof WC_Order || $order->get_payment_method() !== 'pawapay' ) {
            return null;
        }

        return $order;
    }

    private static function title_for( string $phase ): string {
        return match ( $phase ) {
            WC_PawaPay_Poll_Policy::PHASE_RETRY  => __( 'Try another number', 'wc-pawapay' ),
            WC_PawaPay_Poll_Policy::PHASE_FAILED => __( 'Payment not available', 'wc-pawapay' ),
            default                              => __( 'Confirm on your phone', 'wc-pawapay' ),
        };
    }

    private static function last4( string $masked ): string {
        $digits = preg_replace( '/\D+/', '', $masked ) ?? '';
        if ( $digits === '' ) {
            return '****';
        }

        return substr( $digits, -4 );
    }

    private static function safe_masked_phone( ?WC_PawaPay_Attempt $attempt, string $phone_meta ): string {
        $masked = $attempt ? $attempt->masked_msisdn() : '';
        $digits = preg_replace( '/\D+/', '', $masked ) ?? '';
        if ( strlen( $digits ) >= 7 ) {
            return WC_PawaPay_Attempt::mask_msisdn( $masked );
        }
        if ( $masked !== '' ) {
            return $masked;
        }

        return WC_PawaPay_Attempt::mask_msisdn( $phone_meta );
    }

    private static function claim_poll_lookup( int $order_id ): bool {
        $key  = 'wc_pawapay_poll_rl_' . $order_id;
        $last = function_exists( 'get_transient' ) ? (int) get_transient( $key ) : 0;
        $now  = time();
        if ( ! WC_PawaPay_Poll_Policy::allow_lookup( $last, $now ) ) {
            return false;
        }
        if ( function_exists( 'set_transient' ) ) {
            set_transient( $key, $now, 60 );
        }
        return true;
    }
}
