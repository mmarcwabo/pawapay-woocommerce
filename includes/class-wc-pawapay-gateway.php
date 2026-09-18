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

        wp_localize_script(
            'wc-pawapay-checkout',
            'wcPawapayCheckout',
            [
                'shopCurrency'      => WC_PawaPay_Currency::active_currency(),
                'enabledCurrencies' => $this->enabled_currencies(),
                'providers'         => $providers,
                'currencyNames'     => $wc_names,
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
            'webhook_url'        => [
                'title'       => __( 'Deposit callback URL', 'wc-pawapay' ),
                'type'        => 'title',
                'description' => '<strong>' . esc_html__( 'Copy this URL into PawaPay Dashboard → Callback URLs → Deposits only:', 'wc-pawapay' ) . '</strong><br><code>' . esc_html( home_url( '/pawapay-webhook/' ) ) . '</code>',
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
        echo '<div id="pawapay-fields">';

        if ( $this->description ) {
            echo '<p>' . esc_html( $this->description ) . '</p>';
        }

        $this->render_country_field();
        $this->render_phone_field();
        $this->render_mno_cards();
        $this->render_currency_field();

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
        echo '<p class="form-row form-row-wide">';
        echo '<label for="pawapay_country">' . esc_html__( 'Country', 'wc-pawapay' ) . ' <span class="required">*</span></label>';
        echo '<select id="pawapay_country" name="pawapay_country" class="input-text">';
        foreach ( $countries as $code ) {
            $label = $catalog[ $code ]['name'] ?? $code;
            echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select></p>';
    }

    private function render_phone_field(): void {
        echo '<p class="form-row form-row-wide">
            <label for="pawapay_phone">' . esc_html__( 'Mobile money number', 'wc-pawapay' ) . ' <span class="required">*</span></label>
            <input type="tel"
                   id="pawapay_phone"
                   name="pawapay_phone"
                   placeholder="243810000000"
                   autocomplete="tel"
                   class="input-text"
                   style="width:100%;" />
            <small>' . esc_html__( 'International format without + (e.g. 243810000000)', 'wc-pawapay' ) . '</small>
        </p>';
    }

    private function render_mno_cards(): void {
        $mnos = $this->get_mno_list();
        if ( empty( $mnos ) ) {
            return;
        }

        $first = reset( $mnos );

        echo '<div class="form-row form-row-wide pawapay-mno-row">';
        echo '<label>' . esc_html__( 'Mobile money operator', 'wc-pawapay' ) . ' <span class="required">*</span></label>';
        echo '<input type="hidden" id="pawapay_mno" name="pawapay_mno" value="' . esc_attr( $first['code'] ) . '">';
        echo '<div class="pawapay-mno-grid">';

        $is_first = true;
        foreach ( $mnos as $row ) {
            $selected = $is_first ? ' pawapay-selected' : '';
            echo '<button type="button" class="pawapay-mno-card' . $selected . '" data-mno="' . esc_attr( $row['code'] ) . '" data-country="' . esc_attr( $row['country'] ) . '" data-currencies="' . esc_attr( wp_json_encode( $row['currencies'] ) ) . '">';
            if ( ! empty( $row['logo'] ) ) {
                echo '<img src="' . esc_url( $row['logo'] ) . '" alt="' . esc_attr( $row['label'] ) . '">';
            }
            echo '<span>' . esc_html( $row['label'] ) . '</span>';
            echo '</button>';
            $is_first = false;
        }

        echo '</div></div>';
    }

    private function render_currency_field(): void {
        $mnos = $this->get_mno_list();
        if ( empty( $mnos ) ) {
            return;
        }

        $first   = reset( $mnos );
        $choices = WC_PawaPay_Currency::choices_for_operator( $first['code'], $this->enabled_currencies() );
        if ( ! $choices ) {
            $choices = $first['currencies'] ?: [ WC_PawaPay_Currency::active_currency() ];
        }

        $names   = function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : [];
        $active  = WC_PawaPay_Currency::active_currency();
        $current = in_array( $active, $choices, true ) ? $active : ( $choices[0] ?? '' );

        echo '<p class="form-row form-row-wide" id="pawapay-currency-row">';
        echo '<label for="pawapay_currency">' . esc_html__( 'Payment currency', 'wc-pawapay' ) . ' <span class="required">*</span></label>';
        echo '<select id="pawapay_currency" name="pawapay_currency" class="input-text">';
        foreach ( $choices as $code ) {
            $label = isset( $names[ $code ] ) ? $code . ' — ' . $names[ $code ] : $code;
            echo '<option value="' . esc_attr( $code ) . '"' . selected( $current, $code, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<small>' . esc_html__( 'Only currencies enabled for this shop and for the selected operator.', 'wc-pawapay' ) . '</small>';
        echo '</p>';
    }

    public function validate_fields(): bool {
        $phone = sanitize_text_field( wp_unslash( $_POST['pawapay_phone'] ?? '' ) );
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
        $phone      = sanitize_text_field( wp_unslash( $_POST['pawapay_phone'] ?? '' ) );
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
            wc_add_notice( __( 'Payment error: ', 'wc-pawapay' ) . esc_html( $msg ), 'error' );
            $order->add_order_note( 'PawaPay error: ' . $msg );
            return [ 'result' => 'failure' ];
        }

        if ( $status === 'ACCEPTED' ) {
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
        wc_add_notice( __( 'Payment declined: ', 'wc-pawapay' ) . esc_html( $notice ), 'error' );
        $order->add_order_note( 'PawaPay rejected: ' . $notice );

        return [ 'result' => 'failure' ];
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
        return new \WP_Error( 'pawapay_refund', __( 'Refunds must be processed from the PawaPay dashboard.', 'wc-pawapay' ) );
    }

    public function get_api(): WC_PawaPay_API {
        return new WC_PawaPay_API(
            (string) $this->get_option( 'api_token' ),
            $this->get_option( 'sandbox' ) === 'yes',
            $this->get_option( 'debug' ) === 'yes'
        );
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
