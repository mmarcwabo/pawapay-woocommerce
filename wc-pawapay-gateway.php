<?php
/**
 * Plugin Name: PawaPay Mobile Money Gateway for WooCommerce
 * Plugin URI:  https://github.com/mmarcwabo/pawapay-woocommerce
 * Description: WooCommerce gateway for PawaPay mobile money deposits. Configure API keys, deposit callbacks, countries, and operators.
 * Version:     2.4.2
 * Author:      Maungano
 * Author URI:  https://github.com/mmarcwabo/pawapay-woocommerce
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-pawapay
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * Requires Plugins: woocommerce
 * Update URI:  https://github.com/mmarcwabo/pawapay-woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_PAWAPAY_VERSION', '2.4.2' );
define( 'WC_PAWAPAY_PLUGIN_FILE', __FILE__ );
define( 'WC_PAWAPAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_PAWAPAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_PAWAPAY_GITHUB_REPO', 'https://github.com/mmarcwabo/pawapay-woocommerce/' );

add_action( 'before_woocommerce_init', 'wc_pawapay_declare_features' );
add_action( 'plugins_loaded', 'wc_pawapay_load_storage', 10 );
add_action( 'plugins_loaded', 'wc_pawapay_init', 11 );

/**
 * HPOS yes. Blocks checkout is untested — do not claim it.
 */
function wc_pawapay_declare_features(): void {
    if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        return;
    }

    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
        'custom_order_tables',
        WC_PAWAPAY_PLUGIN_FILE,
        true
    );
}

function wc_pawapay_load_storage(): void {
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-attempt.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-migrator.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-attempt-repository.php';
    WC_PawaPay_Migrator::maybe_upgrade();
}

function wc_pawapay_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="error"><p><strong>PawaPay Gateway</strong> requires WooCommerce to be active.</p></div>';
        } );
        return;
    }

    wc_pawapay_load_storage();

    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-providers.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-catalog-policy.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-catalog.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-currency.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-settings-policy.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-client.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-api.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-initiation-policy.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-initiation-lock.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-payment-service.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-completion-policy.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-webhook-verifier.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-callback-processor.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-deposit.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-poll-policy.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-reconciliation-policy.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-reconciler.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-checkout-chrome.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-gateway.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-webhook.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-thankyou.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-admin-attempt-view.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-admin.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-i18n.php';

    WC_PawaPay_I18n::load();
    WC_PawaPay_Thankyou::init();
    WC_PawaPay_Reconciler::init();
    WC_PawaPay_Admin::init();

    add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
        $gateways[] = 'WC_PawaPay_Gateway';
        return $gateways;
    } );

    add_action( 'init', [ 'WC_PawaPay_Webhook', 'register_endpoint' ] );
    add_action( 'rest_api_init', [ 'WC_PawaPay_Webhook', 'register_rest' ] );
    add_action( 'rest_api_init', [ 'WC_PawaPay_Thankyou', 'register_rest' ] );
    add_action( 'template_redirect', [ 'WC_PawaPay_Webhook', 'handle' ] );
}

/**
 * Merchant API token. wp-config wins over the gateway setting.
 */
function wc_pawapay_api_token( ?WC_PawaPay_Gateway $gateway = null ): string {
    $constant = ( defined( 'WC_PAWAPAY_API_TOKEN' ) && is_string( WC_PAWAPAY_API_TOKEN ) )
        ? WC_PAWAPAY_API_TOKEN
        : '';
    $option   = '';
    if ( $gateway instanceof WC_PawaPay_Gateway ) {
        $option = (string) $gateway->get_option( 'api_token' );
    } else {
        $settings = get_option( 'woocommerce_pawapay_settings', [] );
        if ( is_array( $settings ) ) {
            $option = (string) ( $settings['api_token'] ?? '' );
        }
    }

    $token = WC_PawaPay_Settings_Policy::api_token( $constant, $option );
    return trim( (string) apply_filters( 'woocommerce_pawapay_api_token', $token ) );
}

/**
 * Read token for the private GitHub repo. wp-config wins over the gateway setting.
 */
function wc_pawapay_github_token(): string {
    $token = '';
    if ( defined( 'WC_PAWAPAY_GITHUB_TOKEN' ) && is_string( WC_PAWAPAY_GITHUB_TOKEN ) ) {
        $token = WC_PAWAPAY_GITHUB_TOKEN;
    } else {
        $settings = get_option( 'woocommerce_pawapay_settings', [] );
        if ( is_array( $settings ) ) {
            $token = (string) ( $settings['github_token'] ?? '' );
        }
    }

    $token = trim( (string) apply_filters( 'woocommerce_pawapay_github_token', $token ) );
    return $token;
}

/**
 * GitHub release checker so wp-admin can offer updates.
 */
function wc_pawapay_init_update_checker(): void {
    $puc = WC_PAWAPAY_PLUGIN_DIR . 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
    if ( ! is_readable( $puc ) ) {
        return;
    }

    require_once $puc;

    $update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        WC_PAWAPAY_GITHUB_REPO,
        WC_PAWAPAY_PLUGIN_FILE,
        'pawapay-woocommerce'
    );

    $update_checker->setBranch( 'main' );

    $token = wc_pawapay_github_token();
    if ( $token !== '' ) {
        $update_checker->setAuthentication( $token );
    }
}

add_action( 'plugins_loaded', 'wc_pawapay_init_update_checker', 0 );

register_activation_hook( __FILE__, function () {
    wc_pawapay_load_storage();
    add_rewrite_endpoint( 'pawapay-webhook', EP_ROOT );
    flush_rewrite_rules();
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-reconciliation-policy.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-reconciler.php';
    WC_PawaPay_Reconciler::ensure_scheduled();
} );

register_deactivation_hook( __FILE__, function () {
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-reconciliation-policy.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-reconciler.php';
    WC_PawaPay_Reconciler::unschedule();
    flush_rewrite_rules();
} );
