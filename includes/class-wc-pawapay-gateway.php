<?php
defined( 'ABSPATH' ) || exit;

class WC_PawaPay_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'pawapay';
        $this->icon               = WC_PAWAPAY_PLUGIN_URL . 'assets/pawapay-logo.png';
        $this->has_fields         = true;
        $this->method_title       = 'PawaPay Mobile Money';
        $this->method_description = __( 'Collect mobile money deposits through PawaPay. Configure API keys, deposit callbacks, countries, and operators.', 'wc-pawapay' );
        $this->supports           = [ 'products', 'refunds' ];

        $this->init_form_fields();
        $this->init_settings();
        $this->maybe_upgrade_settings();

        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_assets' ] );
        add_filter( 'woocommerce_order_actions', [ $this, 'order_actions' ] );
        add_action( 'woocommerce_order_action_pawapay_sync', [ $this, 'sync_order_from_action' ] );
    }

    public function enqueue_checkout_assets(): void {
        if ( ! is_checkout() ) {
            return;
        }

        wp_enqueue_style(
            'wc-pawapay-checkout',
            WC_PAWAPAY_PLUGIN_URL . 'assets/css/pawapay-checkout.css',
            [],
            WC_PAWAPAY_VERSION
        );

        wp_enqueue_script(
            'wc-pawapay-checkout',
            WC_PAWAPAY_PLUGIN_URL . 'assets/js/pawapay-checkout.js',
            [ 'jquery' ],
            WC_PAWAPAY_VERSION,
            true
        );

        $providers = [];
        foreach ( $this->get_mno_list() as $row ) {
            $providers[ $row['code'] ] = $row['currencies'];
        }

        $wc_names = function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : [];
        $prefixes = [];
        foreach ( WC_PawaPay_Providers::countries() as $code => $row ) {
            $prefixes[ $code ] = [
                'prefix' => $row['prefix'],
                'flag'   => WC_PawaPay_Providers::flag_emoji( $code ),
            ];
        }

        wp_localize_script(
            'wc-pawapay-checkout',
            'wcPawapayCheckout',
            [
                'shopCurrency'      => WC_PawaPay_Currency::active_currency(),
                'enabledCurrencies' => $this->enabled_currencies(),
                'providers'         => $providers,
                'currencyNames'     => $wc_names,
                'prefixes'          => $prefixes,
                'formattedTotal'    => $this->formatted_checkout_total(),
            ]
        );
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'            => [
                'title'   => __( 'Enable/Disable', 'woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable PawaPay Mobile Money', 'wc-pawapay' ),
                'default' => 'no',
            ],
            'title'              => [
                'title'       => __( 'Title', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Payment method title shown to customers.', 'wc-pawapay' ),
                'default'     => __( 'Mobile Money', 'wc-pawapay' ),
                'desc_tip'    => true,
            ],
            'description'        => [
                'title'   => __( 'Description', 'woocommerce' ),
                'type'    => 'textarea',
                'default' => __( 'Pay with your mobile money wallet.', 'wc-pawapay' ),
            ],
            'sandbox'            => [
                'title'   => __( 'Sandbox', 'wc-pawapay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Use PawaPay sandbox (testing)', 'wc-pawapay' ),
                'default' => 'yes',
            ],
            'api_token'          => [
                'title'       => __( 'API token', 'wc-pawapay' ),
                'type'        => 'password',
                'description' => __( 'Found in PawaPay Dashboard → Developers. Sandbox and production tokens are different.', 'wc-pawapay' ),
                'desc_tip'    => true,
            ],
            'enabled_currencies' => [
                'title'       => __( 'Charge currencies', 'wc-pawapay' ),
                'type'        => 'multiselect',
                'class'       => 'wc-enhanced-select',
                'options'     => WC_PawaPay_Currency::admin_choices(),
                'description' => __( 'Currencies this gateway may send to PawaPay. The list is the shop/switcher currencies plus PawaPay catalog currencies. Checkout then keeps only those the selected operator supports.', 'wc-pawapay' ),
                'default'     => [],
            ],
            'exchange_rates'     => [
                'title'       => __( 'Exchange rates', 'wc-pawapay' ),
                'type'        => 'textarea',
                'description' => __( 'Used only when the charge currency differs from the order and no Aelia/WOOCS converter is present. One per line, units of that currency per 1 shop-base unit. Example: CDF=2800', 'wc-pawapay' ),
                'default'     => '',
            ],
            'countries'          => [
                'title'       => __( 'Supported countries', 'wc-pawapay' ),
                'type'        => 'multiselect',
                'class'       => 'wc-enhanced-select',
                'options'     => WC_PawaPay_Providers::country_choices(),
                'description' => __( 'Countries where customers can pay. Leave empty to show every selected operator.', 'wc-pawapay' ),
                'default'     => [],
            ],
            'providers'          => [
                'title'       => __( 'Supported mobile money operators', 'wc-pawapay' ),
                'type'        => 'multiselect',
                'class'       => 'wc-enhanced-select',
                'options'     => WC_PawaPay_Providers::provider_choices(),
                'description' => __( 'Operators shown at checkout. Enable the matching countries above.', 'wc-pawapay' ),
                'default'     => [],
            ],
            'extra_mnos'         => [
                'title'       => __( 'Custom operators', 'wc-pawapay' ),
                'type'        => 'textarea',
                'description' => __( 'Optional extra operators, one per line: LABEL|PROVIDER_CODE', 'wc-pawapay' ),
                'default'     => '',
            ],
            'debug'              => [
                'title'   => __( 'Debug log', 'wc-pawapay' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable logging (WooCommerce → Status → Logs)', 'wc-pawapay' ),
                'default' => 'no',
            ],
            'github_token'       => [
                'title'       => __( 'GitHub update token', 'wc-pawapay' ),
                'type'        => 'password',
                'description' => __( 'Optional. Only needed for a private fork. Fine-grained PAT with Contents: Read. WC_PAWAPAY_GITHUB_TOKEN in wp-config overrides this field. Leave empty for the public official repo.', 'wc-pawapay' ),
                'desc_tip'    => true,
            ],
            'webhook_url'        => [
                'title'       => __( 'Deposit callback URL', 'wc-pawapay' ),
                'type'        => 'title',
                'description' => '<strong>' . esc_html__( 'Copy one of these URLs into PawaPay Dashboard → Callback URLs → Deposits only:', 'wc-pawapay' ) . '</strong><br><code>' . esc_html( home_url( '/pawapay-webhook/' ) ) . '</code><br><code>' . esc_html( rest_url( 'pawapay/v1/deposits' ) ) . '</code><br>' . esc_html__( 'Use the REST URL if the pretty permalink 404s.', 'wc-pawapay' ),
            ],
        ];
    }

    /**
     * Keep 1.0.x textarea MNO settings working after upgrade.
     */
    private function maybe_upgrade_settings(): void {
        $version = (string) $this->get_option( 'settings_version' );
        if ( $version === '1.1.0' ) {
            return;
        }

        $legacy = $this->get_option( 'mnos', '' );
        if ( is_string( $legacy ) && $legacy !== '' && empty( $this->get_option( 'providers' ) ) ) {
            $codes     = [];
            $countries = [];
            foreach ( explode( "\n", $legacy ) as $line ) {
                $line = trim( $line );
                if ( ! str_contains( $line, '|' ) ) {
                    continue;
                }
                [ , $code ] = explode( '|', $line, 2 );
                $code       = WC_PawaPay_Providers::normalize_code( $code );
                $codes[]    = $code;
                $provider   = WC_PawaPay_Providers::find( $code );
                if ( $provider ) {
                    $countries[] = $provider['country'];
                }
            }
            if ( $codes ) {
                $this->update_option( 'providers', array_values( array_unique( $codes ) ) );
            }
            if ( $countries ) {
                $this->update_option( 'countries', array_values( array_unique( $countries ) ) );
            }
        }

        if ( empty( $this->get_option( 'enabled_currencies' ) ) ) {
            $seed = [
                WC_PawaPay_Currency::shop_base_currency(),
                WC_PawaPay_Currency::active_currency(),
                strtoupper( (string) $this->get_option( 'default_currency', '' ) ),
            ];
            $this->update_option( 'enabled_currencies', WC_PawaPay_Currency::normalize_list( $seed ) );
        }

        $this->update_option( 'settings_version', '1.1.0' );
    }

    public function payment_fields(): void {
        echo '<div id="pawapay-fields" class="pawapay-panel">';
        echo '<div class="pawapay-panel-head">';
        echo '<div><span class="pawapay-kicker">' . esc_html__( 'Payment to', 'wc-pawapay' ) . '</span><strong>' . esc_html( $this->checkout_shop_name() ) . '</strong></div>';
        echo '<div class="pawapay-head-for">';
        echo '<span class="pawapay-kicker">' . esc_html__( 'For', 'wc-pawapay' ) . '</span>';
        $this->render_item_list();
        echo '</div></div>';

        $this->render_amount_row();
        $this->render_country_field();
        $this->render_phone_field();
        $this->render_mno_cards();

        echo '<p class="pawapay-powered">' . esc_html__( 'Powered by PawaPay', 'wc-pawapay' ) . '</p>';
        echo '</div>';
    }

    private function render_country_field(): void {
        $countries = $this->enabled_countries();
        if ( count( $countries ) < 2 ) {
            if ( count( $countries ) === 1 ) {
                echo '<input type="hidden" id="pawapay_country" name="pawapay_country" value="' . esc_attr( $countries[0] ) . '">';
            }
            return;
        }

        $catalog = WC_PawaPay_Providers::countries();
        echo '<div class="pawapay-field">';
        echo '<label for="pawapay_country">' . esc_html__( 'Country', 'wc-pawapay' ) . '</label>';
        echo '<select id="pawapay_country" name="pawapay_country" class="pawapay-select">';
        foreach ( $countries as $code ) {
            $label = $catalog[ $code ]['name'] ?? $code;
            echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select></div>';
    }

    private function render_amount_row(): void {
        $mnos = $this->get_mno_list();
        $first = $mnos ? reset( $mnos ) : null;
        $choices = $first
            ? WC_PawaPay_Currency::choices_for_operator( $first['code'], $this->enabled_currencies() )
            : $this->enabled_currencies();
        if ( ! $choices ) {
            $choices = [ WC_PawaPay_Currency::active_currency() ];
        }

        $active  = WC_PawaPay_Currency::active_currency();
        $current = in_array( $active, $choices, true ) ? $active : ( $choices[0] ?? $active );

        echo '<div class="pawapay-field pawapay-amount-row">';
        echo '<label for="pawapay_currency">' . esc_html__( 'Amount', 'wc-pawapay' ) . '</label>';
        echo '<div class="pawapay-amount-line">';
        echo '<strong id="pawapay-amount-value">' . wp_kses_post( $this->formatted_checkout_total() ) . '</strong>';
        echo '<select id="pawapay_currency" name="pawapay_currency" class="pawapay-select pawapay-currency-select"' . ( count( $choices ) < 2 ? ' hidden' : '' ) . '>';
        foreach ( $choices as $code ) {
            echo '<option value="' . esc_attr( $code ) . '"' . selected( $current, $code, false ) . '>' . esc_html( $code ) . '</option>';
        }
        echo '</select>';
        echo '</div></div>';
    }

    private function render_phone_field(): void {
        $countries = $this->enabled_countries();
        $country   = $countries[0] ?? 'COD';
        $catalog   = WC_PawaPay_Providers::countries();
        $prefix    = $catalog[ $country ]['prefix'] ?? '243';
        $flag      = WC_PawaPay_Providers::flag_emoji( $country );

        echo '<div class="pawapay-field">';
        echo '<label for="pawapay_phone_local">' . esc_html__( 'Phone number', 'wc-pawapay' ) . ' <span class="required">*</span></label>';
        echo '<div class="pawapay-phone">';
        echo '<span class="pawapay-prefix" id="pawapay-prefix" data-prefix="' . esc_attr( $prefix ) . '">';
        echo '<span class="pawapay-flag" id="pawapay-flag">' . esc_html( $flag ) . '</span>';
        echo '<span id="pawapay-prefix-text">+' . esc_html( $prefix ) . '</span>';
        echo '</span>';
        echo '<input type="tel" id="pawapay_phone_local" name="pawapay_phone_local" inputmode="numeric" autocomplete="tel-national" placeholder="' . esc_attr__( 'Enter your phone number', 'wc-pawapay' ) . '" class="pawapay-phone-input">';
        echo '</div>';
        echo '<input type="hidden" id="pawapay_phone" name="pawapay_phone" value="">';
        echo '</div>';
    }

    private function render_mno_cards(): void {
        $mnos = $this->get_mno_list();
        if ( empty( $mnos ) ) {
            return;
        }

        $first = reset( $mnos );

        echo '<div class="pawapay-field pawapay-mno-row">';
        echo '<label>' . esc_html__( 'Operator', 'wc-pawapay' ) . ' <span class="required">*</span></label>';
        echo '<input type="hidden" id="pawapay_mno" name="pawapay_mno" value="' . esc_attr( $first['code'] ) . '">';
        echo '<div class="pawapay-mno-grid">';

        $is_first = true;
        foreach ( $mnos as $row ) {
            $selected = $is_first ? ' pawapay-selected' : '';
            echo '<button type="button" class="pawapay-mno-card' . $selected . '" data-mno="' . esc_attr( $row['code'] ) . '" data-country="' . esc_attr( $row['country'] ) . '" data-currencies="' . esc_attr( wp_json_encode( $row['currencies'] ) ) . '">';
            if ( ! empty( $row['logo'] ) ) {
                echo '<img src="' . esc_url( $row['logo'] ) . '" alt="' . esc_attr( $row['label'] ) . '">';
            }
            echo '<span>' . esc_html( $this->mno_display_label( $row['label'] ) ) . '</span>';
            echo '</button>';
            $is_first = false;
        }

        echo '</div></div>';
    }

    public function validate_fields(): bool {
        $phone = $this->posted_msisdn();
        $mno   = WC_PawaPay_Providers::normalize_code( sanitize_text_field( wp_unslash( $_POST['pawapay_mno'] ?? '' ) ) );

        if ( $phone === '' ) {
            wc_add_notice( __( 'Please enter your mobile money number.', 'wc-pawapay' ), 'error' );
            return false;
        }

        if ( ! preg_match( '/^\d{7,15}$/', $phone ) ) {
            wc_add_notice( __( 'Invalid number. Use international format without + (e.g. 243810000000).', 'wc-pawapay' ), 'error' );
            return false;
        }

        if ( $mno === '' ) {
            wc_add_notice( __( 'Please select your mobile money operator.', 'wc-pawapay' ), 'error' );
            return false;
        }

        $allowed = array_column( $this->get_mno_list(), 'code' );
        if ( ! in_array( $mno, $allowed, true ) ) {
            wc_add_notice( __( 'That operator is not enabled for this store.', 'wc-pawapay' ), 'error' );
            return false;
        }

        $currency = strtoupper( sanitize_text_field( wp_unslash( $_POST['pawapay_currency'] ?? '' ) ) );
        $choices  = WC_PawaPay_Currency::choices_for_operator( $mno, $this->enabled_currencies() );
        if ( $choices && ( $currency === '' || ! in_array( $currency, $choices, true ) ) ) {
            wc_add_notice( __( 'Please choose a payment currency supported by this operator.', 'wc-pawapay' ), 'error' );
            return false;
        }

        $from = WC_PawaPay_Currency::active_currency();
        if ( $currency && WC_PawaPay_Currency::convert( 1.0, $from, $currency, $this->manual_rates() ) === null ) {
            wc_add_notice( __( 'No exchange rate is available for that payment currency. Add a rate in PawaPay settings or install a currency switcher.', 'wc-pawapay' ), 'error' );
            return false;
        }

        return true;
    }

    public function process_payment( $order_id ): array {
        $order      = wc_get_order( $order_id );
        $phone      = $this->posted_msisdn();
        $mno        = WC_PawaPay_Providers::normalize_code( sanitize_text_field( wp_unslash( $_POST['pawapay_mno'] ?? '' ) ) );
        $deposit_id = WC_PawaPay_API::generate_uuid();
        $currency   = $this->resolve_currency( $order, $mno );
        $converted  = $this->convert_order_amount( $order, $currency );
        if ( $converted === null ) {
            wc_add_notice( __( 'No exchange rate is available for that payment currency. Add a rate in PawaPay settings or install a currency switcher.', 'wc-pawapay' ), 'error' );
            return [ 'result' => 'failure' ];
        }
        $amount = $this->format_amount( $converted, $currency, $mno );

        $metadata = [
            'orderId'    => (string) $order_id,
            'customerId' => (string) $order->get_customer_id(),
            'site'       => home_url(),
        ];

        $order->update_meta_data( '_pawapay_deposit_id', $deposit_id );
        $order->update_meta_data( '_pawapay_phone', $phone );
        $order->update_meta_data( '_pawapay_mno', $mno );
        $order->update_meta_data( '_pawapay_currency', $currency );
        $order->update_meta_data( '_pawapay_amount', $amount );
        $order->save();

        $this->record_attempt(
            $order,
            $deposit_id,
            $phone,
            $mno,
            $currency,
            $amount,
            $converted,
            WC_PawaPay_Attempt::STATUS_INITIATING
        );

        $response = $this->get_api()->initiate_deposit(
            $deposit_id,
            $amount,
            $currency,
            $mno,
            $phone,
            'Order ' . $order_id,
            $metadata
        );

        $status = $response['status'] ?? null;

        if ( isset( $response['error'] ) || ( $response['_http_code'] ?? 0 ) >= 500 ) {
            $msg = $response['error'] ?? ( $response['errorMessage'] ?? ( $response['message'] ?? 'PawaPay API error.' ) );
            WC_PawaPay_Attempt_Repository::instance()->mark_status( $deposit_id, WC_PawaPay_Attempt::STATUS_UNKNOWN, [
                'failure_code'    => 'CONNECTION',
                'failure_message' => is_string( $msg ) ? $msg : 'PawaPay API error.',
            ] );
            wc_add_notice( __( 'Payment error: ', 'wc-pawapay' ) . esc_html( $msg ), 'error' );
            $order->add_order_note( 'PawaPay error: ' . $msg );
            return [ 'result' => 'failure' ];
        }

        if ( $status === 'ACCEPTED' ) {
            WC_PawaPay_Attempt_Repository::instance()->mark_status( $deposit_id, WC_PawaPay_Attempt::STATUS_ACCEPTED );
            $order->update_status( 'pending', __( 'Waiting for mobile money confirmation via PawaPay.', 'wc-pawapay' ) );
            $order->add_order_note( sprintf(
                'PawaPay deposit initiated. Deposit ID: %s | Phone: %s | MNO: %s | Amount: %s %s',
                $deposit_id,
                $phone,
                $mno,
                $amount,
                $currency
            ) );
            WC()->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            ];
        }

        $reject_code = $response['rejectionReason']['rejectionCode'] ?? '';
        $reject_msg  = $response['rejectionReason']['rejectionMessage'] ?? ( $response['message'] ?? 'Payment rejected.' );
        $notice      = trim( $reject_code . ( $reject_msg ? ' — ' . $reject_msg : '' ) );
        WC_PawaPay_Attempt_Repository::instance()->mark_status( $deposit_id, WC_PawaPay_Attempt::STATUS_FAILED, [
            'failure_code'    => (string) $reject_code,
            'failure_message' => (string) $reject_msg,
        ] );
        wc_add_notice( __( 'Payment declined: ', 'wc-pawapay' ) . esc_html( $notice ), 'error' );
        $order->add_order_note( 'PawaPay rejected: ' . $notice );

        return [ 'result' => 'failure' ];
    }

    /**
     * Dual-write a new attempt. 1.x order meta remains the latest-deposit pointer.
     * Insert failure must not block checkout.
     */
    private function record_attempt(
        WC_Order $order,
        string $deposit_id,
        string $phone,
        string $mno,
        string $currency,
        string $amount,
        float $converted,
        string $status
    ): void {
        $order_total    = (float) $order->get_total();
        $order_currency = strtoupper( $order->get_currency() );
        $exchange_rate  = ( $order_currency === $currency || $order_total <= 0.0 )
            ? '1'
            : WC_PawaPay_Attempt::format_decimal( $converted / $order_total );

        $attempt = WC_PawaPay_Attempt::create( [
            'order_id'         => (int) $order->get_id(),
            'deposit_id'       => $deposit_id,
            'provider'         => $mno,
            'msisdn'           => $phone,
            'order_currency'   => $order_currency,
            'order_amount'     => (string) $order->get_total(),
            'payment_currency' => $currency,
            'payment_amount'   => $amount,
            'exchange_rate'    => $exchange_rate,
            'status'           => $status,
        ] );

        WC_PawaPay_Attempt_Repository::instance()->insert( $attempt );
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
        return new \WP_Error( 'pawapay_refund', __( 'Refunds must be processed from the PawaPay dashboard.', 'wc-pawapay' ) );
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function order_actions( array $actions ): array {
        global $theorder;
        $order = $theorder instanceof WC_Order ? $theorder : null;
        if ( $order && $order->get_payment_method() === $this->id && $order->get_meta( '_pawapay_deposit_id' ) ) {
            $actions['pawapay_sync'] = __( 'Check PawaPay status', 'wc-pawapay' );
        }
        return $actions;
    }

    public function sync_order_from_action( WC_Order $order ): void {
        WC_PawaPay_Deposit::sync_from_api( $order, $this->get_api() );
    }

    public function get_api(): WC_PawaPay_API {
        return new WC_PawaPay_API(
            (string) $this->get_option( 'api_token' ),
            $this->get_option( 'sandbox' ) === 'yes',
            $this->get_option( 'debug' ) === 'yes'
        );
    }

    private function posted_msisdn(): string {
        $posted  = (string) wp_unslash( $_POST['pawapay_phone'] ?? '' );
        $local   = (string) wp_unslash( $_POST['pawapay_phone_local'] ?? '' );
        $country = strtoupper( sanitize_text_field( wp_unslash( $_POST['pawapay_country'] ?? '' ) ) );
        if ( $country === '' ) {
            $enabled = $this->enabled_countries();
            $country = $enabled[0] ?? '';
        }

        $from_hidden = WC_PawaPay_Providers::compose_msisdn( $posted, $country );
        if ( $from_hidden !== '' ) {
            return $from_hidden;
        }

        return WC_PawaPay_Providers::compose_msisdn( $local, $country );
    }

    private function checkout_shop_name(): string {
        return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ?: 'Shop';
    }

    /**
     * @return list<array{name: string, qty: float, total: float}>
     */
    private function checkout_lines(): array {
        if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
            $order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
            if ( $order ) {
                $lines = [];
                foreach ( $order->get_items() as $item ) {
                    if ( ! $item instanceof WC_Order_Item_Product ) {
                        continue;
                    }
                    $lines[] = [
                        'name'  => wp_strip_all_tags( $item->get_name() ),
                        'qty'   => (float) $item->get_quantity(),
                        'total' => (float) $item->get_total(),
                    ];
                }
                return $lines;
            }
        }

        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return [];
        }

        $lines = [];
        foreach ( WC()->cart->get_cart() as $item ) {
            $product = $item['data'] ?? null;
            $name    = $product instanceof WC_Product ? $product->get_name() : '';
            $lines[] = [
                'name'  => wp_strip_all_tags( (string) $name ),
                'qty'   => (float) ( $item['quantity'] ?? 1 ),
                'total' => (float) ( $item['line_total'] ?? 0 ),
            ];
        }

        return $lines;
    }

    private function render_item_list(): void {
        $lines = $this->checkout_lines();
        if ( ! $lines ) {
            echo '<strong>' . esc_html__( 'Your order', 'wc-pawapay' ) . '</strong>';
            return;
        }

        echo '<ul class="pawapay-items">';
        foreach ( $lines as $line ) {
            $qty = $line['qty'] == (int) $line['qty'] ? (string) (int) $line['qty'] : (string) $line['qty'];
            echo '<li>';
            echo '<span class="pawapay-item-name">' . esc_html( sprintf( __( '%1$s × %2$s', 'wc-pawapay' ), $qty, $line['name'] ) ) . '</span>';
            echo '<span class="pawapay-item-total">' . esc_html( number_format_i18n( $line['total'], 2 ) ) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
    }

    private function formatted_checkout_total(): string {
        $total = 0.0;
        $ccy   = WC_PawaPay_Currency::active_currency();

        if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
            $order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
            if ( $order ) {
                $total = (float) $order->get_total();
                $ccy   = strtoupper( $order->get_currency() );
            }
        } elseif ( WC()->cart ) {
            $total = (float) WC()->cart->get_total( 'edit' );
        }

        return number_format_i18n( $total, 2 ) . "\u{00a0}" . $ccy;
    }

    private function mno_display_label( string $label ): string {
        $short = preg_replace( '/\s+(Money|MoMo|M-Pesa|Mpamba)$/i', '', $label );
        return is_string( $short ) && $short !== '' ? $short : $label;
    }

    private function resolve_currency( WC_Order $order, string $mno ): string {
        $posted  = strtoupper( sanitize_text_field( wp_unslash( $_POST['pawapay_currency'] ?? '' ) ) );
        $choices = WC_PawaPay_Currency::choices_for_operator( $mno, $this->enabled_currencies() );

        if ( $posted && ( ! $choices || in_array( $posted, $choices, true ) ) ) {
            return $posted;
        }

        $order_currency = strtoupper( $order->get_currency() );
        if ( $choices && in_array( $order_currency, $choices, true ) ) {
            return $order_currency;
        }

        return $choices[0] ?? $order_currency;
    }

    private function convert_order_amount( WC_Order $order, string $charge_currency ): ?float {
        return WC_PawaPay_Currency::convert(
            (float) $order->get_total(),
            strtoupper( $order->get_currency() ),
            strtoupper( $charge_currency ),
            $this->manual_rates()
        );
    }

    /**
     * @return array<string, float>
     */
    private function manual_rates(): array {
        return WC_PawaPay_Currency::parse_rates_textarea(
            (string) $this->get_option( 'exchange_rates', '' ),
            WC_PawaPay_Currency::shop_base_currency()
        );
    }

    /**
     * @return list<string>
     */
    private function enabled_currencies(): array {
        $stored = $this->get_option( 'enabled_currencies', [] );
        if ( ! is_array( $stored ) ) {
            $stored = array_filter( array_map( 'trim', explode( ',', (string) $stored ) ) );
        }

        $stored = WC_PawaPay_Currency::normalize_list( $stored );
        return $stored ?: WC_PawaPay_Currency::site_currencies();
    }

    private function format_amount( float $total, string $currency, string $mno ): string {
        $decimals = WC_PawaPay_Providers::amount_decimals( $mno, $currency );
        return number_format( $total, $decimals, '.', '' );
    }

    /**
     * @return list<string>
     */
    private function enabled_countries(): array {
        $countries = $this->get_option( 'countries', [] );
        if ( ! is_array( $countries ) ) {
            $countries = array_filter( array_map( 'trim', explode( ',', (string) $countries ) ) );
        }
        $countries = array_values( array_filter( array_map( 'strtoupper', $countries ) ) );
        if ( $countries ) {
            return $countries;
        }

        $from_mnos = [];
        foreach ( $this->get_mno_list() as $row ) {
            if ( $row['country'] !== '' ) {
                $from_mnos[] = $row['country'];
            }
        }
        return array_values( array_unique( $from_mnos ) );
    }

    /**
     * @return list<array{code: string, label: string, country: string, logo: string, currencies: list<string>}>
     */
    private function get_mno_list(): array {
        $selected  = $this->get_option( 'providers', [] );
        $countries = $this->get_option( 'countries', [] );
        if ( ! is_array( $selected ) ) {
            $selected = array_filter( array_map( 'trim', explode( ',', (string) $selected ) ) );
        }
        if ( ! is_array( $countries ) ) {
            $countries = array_filter( array_map( 'trim', explode( ',', (string) $countries ) ) );
        }
        $selected  = array_map( [ 'WC_PawaPay_Providers', 'normalize_code' ], $selected );
        $countries = array_map( 'strtoupper', $countries );

        $list = [];
        foreach ( WC_PawaPay_Providers::all() as $provider ) {
            if ( $selected && ! in_array( $provider['code'], $selected, true ) ) {
                continue;
            }
            if ( $countries && ! in_array( $provider['country'], $countries, true ) ) {
                continue;
            }
            $list[] = $this->mno_row( $provider['code'], $provider['label'], $provider['country'] );
        }

        foreach ( explode( "\n", (string) $this->get_option( 'extra_mnos', '' ) ) as $line ) {
            $line = trim( $line );
            if ( ! str_contains( $line, '|' ) ) {
                continue;
            }
            [ $label, $code ] = explode( '|', $line, 2 );
            $code             = WC_PawaPay_Providers::normalize_code( $code );
            $provider         = WC_PawaPay_Providers::find( $code );
            $list[]           = $this->mno_row( $code, trim( $label ), $provider['country'] ?? '' );
        }

        // Legacy textarea still used if nothing else is configured.
        if ( ! $list ) {
            foreach ( explode( "\n", (string) $this->get_option( 'mnos', '' ) ) as $line ) {
                $line = trim( $line );
                if ( ! str_contains( $line, '|' ) ) {
                    continue;
                }
                [ $label, $code ] = explode( '|', $line, 2 );
                $code             = WC_PawaPay_Providers::normalize_code( $code );
                $provider         = WC_PawaPay_Providers::find( $code );
                $list[]           = $this->mno_row( $code, trim( $label ), $provider['country'] ?? '' );
            }
        }

        return $list;
    }

    private function mno_row( string $code, string $label, string $country ): array {
        $file = WC_PawaPay_Providers::logo_filename( $code );
        return [
            'code'       => $code,
            'label'      => $label,
            'country'    => $country,
            'logo'       => $file ? WC_PAWAPAY_PLUGIN_URL . 'assets/mnos/' . $file : '',
            'currencies' => WC_PawaPay_Currency::choices_for_operator( $code, $this->enabled_currencies() ),
        ];
    }
}
