<?php
defined( 'ABSPATH' ) || exit;

/**
 * WC_PawaPay_Gateway
 * The main WooCommerce payment gateway class.
 */
class WC_PawaPay_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'pawapay';
        $this->icon               = WC_PAWAPAY_PLUGIN_URL . 'assets/pawapay-logo.png';
        $this->has_fields         = true;
        $this->method_title       = 'PawaPay Mobile Money';
        $this->method_description = 'Acceptez les paiements mobile money via Airtel, Orange et Vodacom.';
        $this->supports           = [ 'products', 'refunds' ];

        $this->init_form_fields();
        $this->init_settings();

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
            [],
            WC_PAWAPAY_VERSION,
            true
        );
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'          => [
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable PawaPay Mobile Money',
                'default' => 'no',
            ],
            'title'            => [
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'Payment method title shown to customers.',
                'default'     => 'Mobile Money (Airtel / Orange / Vodacom)',
                'desc_tip'    => true,
            ],
            'description'      => [
                'title'   => 'Description',
                'type'    => 'textarea',
                'default' => 'Payez avec votre portefeuille mobile money (Airtel Money, Orange Money, Vodacom M-Pesa).',
            ],
            'sandbox'          => [
                'title'   => 'Sandbox Mode',
                'type'    => 'checkbox',
                'label'   => 'Use PawaPay Sandbox (testing)',
                'default' => 'yes',
            ],
            'api_token'        => [
                'title'       => 'API Token',
                'type'        => 'password',
                'description' => 'Found in your PawaPay Dashboard → Developers. Sandbox and production tokens are different.',
                'desc_tip'    => true,
            ],
            'default_currency' => [
                'title'       => 'Currency Code',
                'type'        => 'text',
                'description' => 'Must match a currency enabled for the selected MNO (e.g. USD, CDF).',
                'default'     => 'USD',
                'desc_tip'    => true,
            ],
            'mnos'             => [
                'title'       => 'Supported MNOs',
                'type'        => 'textarea',
                'description' => 'One per line: LABEL|PROVIDER_CODE. DRC codes: AIRTEL_COD, ORANGE_COD, VODACOM_MPESA_COD.',
                'default'     => "Airtel Money|AIRTEL_COD\nOrange Money|ORANGE_COD\nVodacom M-Pesa|VODACOM_MPESA_COD",
                'desc_tip'    => true,
            ],
            'debug'            => [
                'title'   => 'Debug Log',
                'type'    => 'checkbox',
                'label'   => 'Enable logging (WooCommerce → Status → Logs)',
                'default' => 'no',
            ],
            'webhook_url'      => [
                'title'       => 'Callback / Webhook URL',
                'type'        => 'title',
                'description' => '<strong>Copy this URL into PawaPay Dashboard → Callback URLs → Deposits only:</strong><br><code>' . esc_html( home_url( '/pawapay-webhook/' ) ) . '</code>',
            ],
        ];
    }

    public function payment_fields(): void {
        echo '<div id="pawapay-fields">';

        if ( $this->description ) {
            echo '<p>' . esc_html( $this->description ) . '</p>';
        }

        $this->render_phone_field();
        $this->render_mno_cards();

        echo '</div>';
    }

    private function render_phone_field(): void {
        echo '<p class="form-row form-row-wide">
            <label for="pawapay_phone">' . esc_html__( 'Numéro Mobile Money', 'wc-pawapay' ) . ' <span class="required">*</span></label>
            <input type="tel"
                   id="pawapay_phone"
                   name="pawapay_phone"
                   placeholder="ex. 243810000000"
                   autocomplete="tel"
                   class="input-text"
                   style="width:100%;" />
            <small>' . esc_html__( 'Format international sans + (ex. 243810000000)', 'wc-pawapay' ) . '</small>
        </p>';
    }

    private function render_mno_cards(): void {
        $mnos     = $this->get_mno_list();
        $logo_map = $this->get_mno_logo_map();

        if ( empty( $mnos ) ) {
            return;
        }

        $first_code = array_values( $mnos )[0];

        echo '<p class="form-row form-row-wide">';
        echo '<label>' . esc_html__( 'Opérateur Mobile', 'wc-pawapay' ) . ' <span class="required">*</span></label>';
        echo '<input type="hidden" id="pawapay_mno" name="pawapay_mno" value="' . esc_attr( $first_code ) . '">';
        echo '<div class="pawapay-mno-grid">';

        $is_first = true;
        foreach ( $mnos as $label => $code ) {
            $selected = $is_first ? ' pawapay-selected' : '';
            echo '<div class="pawapay-mno-card' . $selected . '" data-mno="' . esc_attr( $code ) . '" role="button" tabindex="0">';

            if ( isset( $logo_map[ $code ] ) ) {
                echo '<img src="' . esc_url( $logo_map[ $code ] ) . '" alt="' . esc_attr( $label ) . '">';
            }

            echo '<span>' . esc_html( $label ) . '</span>';
            echo '</div>';
            $is_first = false;
        }

        echo '</div>';
        echo '</p>';
    }

    public function validate_fields(): bool {
        $phone = sanitize_text_field( $_POST['pawapay_phone'] ?? '' );
        $mno   = sanitize_text_field( $_POST['pawapay_mno'] ?? '' );

        if ( empty( $phone ) ) {
            wc_add_notice( __( 'Veuillez entrer votre numéro mobile money.', 'wc-pawapay' ), 'error' );
            return false;
        }

        if ( ! preg_match( '/^\d{7,15}$/', $phone ) ) {
            wc_add_notice( __( 'Numéro invalide. Utilisez le format international sans + (ex. 243810000000).', 'wc-pawapay' ), 'error' );
            return false;
        }

        if ( empty( $mno ) ) {
            wc_add_notice( __( 'Veuillez sélectionner votre opérateur mobile.', 'wc-pawapay' ), 'error' );
            return false;
        }

        return true;
    }

    public function process_payment( $order_id ): array {
        $order      = wc_get_order( $order_id );
        $phone      = sanitize_text_field( $_POST['pawapay_phone'] ?? '' );
        $mno        = sanitize_text_field( $_POST['pawapay_mno'] ?? '' );
        $deposit_id = WC_PawaPay_API::generate_uuid();
        $currency   = strtoupper( $this->get_option( 'default_currency', 'USD' ) );
        $amount     = $this->format_amount( (float) $order->get_total(), $currency, $mno );

        $metadata = [
            'orderId'    => (string) $order_id,
            'customerId' => (string) $order->get_customer_id(),
            'site'       => home_url(),
        ];

        $order->update_meta_data( '_pawapay_deposit_id', $deposit_id );
        $order->update_meta_data( '_pawapay_phone', $phone );
        $order->update_meta_data( '_pawapay_mno', $mno );
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
            wc_add_notice( __( 'Erreur de paiement : ', 'wc-pawapay' ) . esc_html( $msg ), 'error' );
            $order->add_order_note( 'PawaPay error: ' . $msg );
            return [ 'result' => 'failure' ];
        }

        if ( $status === 'ACCEPTED' ) {
            $order->update_status( 'pending', __( 'En attente de confirmation mobile money via PawaPay.', 'wc-pawapay' ) );
            $order->add_order_note( sprintf(
                'PawaPay deposit initiated. Deposit ID: %s | Phone: %s | MNO: %s | Amount: %s %s',
                $deposit_id, $phone, $mno, $amount, $currency
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
        wc_add_notice( __( 'Paiement refusé : ', 'wc-pawapay' ) . esc_html( $notice ), 'error' );
        $order->add_order_note( 'PawaPay rejected: ' . $notice );

        return [ 'result' => 'failure' ];
    }

    public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
        return new \WP_Error( 'pawapay_refund', __( 'Les remboursements doivent être traités manuellement depuis le tableau de bord PawaPay.', 'wc-pawapay' ) );
    }

    public function get_api(): WC_PawaPay_API {
        return new WC_PawaPay_API(
            $this->get_option( 'api_token' ),
            $this->get_option( 'sandbox' ) === 'yes',
            $this->get_option( 'debug' ) === 'yes'
        );
    }

    private function format_amount( float $total, string $currency, string $mno ): string {
        $decimals = 2;
        if ( $currency === 'CDF' && $mno === 'VODACOM_MPESA_COD' ) {
            $decimals = 0;
        }

        return number_format( $total, $decimals, '.', '' );
    }

    private function get_mno_list(): array {
        $list = [];
        foreach ( explode( "\n", $this->get_option( 'mnos', '' ) ) as $line ) {
            $line = trim( $line );
            if ( str_contains( $line, '|' ) ) {
                [ $label, $code ] = explode( '|', $line, 2 );
                $list[ trim( $label ) ] = trim( $code );
            }
        }
        return $list;
    }

    /**
     * Map MNO codes to logos. Includes legacy codes saved in older settings.
     */
    private function get_mno_logo_map(): array {
        $base = WC_PAWAPAY_PLUGIN_URL . 'assets/mnos/';
        return [
            'AIRTEL_COD'          => $base . 'Airtel-Money-logo.png',
            'AIRTEL_OAPI_COD'     => $base . 'Airtel-Money-logo.png',
            'ORANGE_COD'          => $base . 'Orange-logo.png',
            'VODACOM_MPESA_COD'   => $base . 'Mpesa-logo.png',
            'VODACOM_COD'         => $base . 'Mpesa-logo.png',
        ];
    }
}
