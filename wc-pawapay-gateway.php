<?php
/**
 * Plugin Name: PawaPay Mobile Money Gateway for WooCommerce
 * Plugin URI:  https://github.com/mmarcwabo/pawapay-woocommerce
 * Description: WooCommerce gateway for PawaPay mobile money deposits. Configure API keys, deposit callbacks, countries, and operators.
 * Version:     1.2.0
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

define( 'WC_PAWAPAY_VERSION', '1.2.0' );
define( 'WC_PAWAPAY_PLUGIN_FILE', __FILE__ );
define( 'WC_PAWAPAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_PAWAPAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_PAWAPAY_GITHUB_REPO', 'https://github.com/mmarcwabo/pawapay-woocommerce/' );

add_action( 'plugins_loaded', 'wc_pawapay_init', 11 );

function wc_pawapay_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="error"><p><strong>PawaPay Gateway</strong> requires WooCommerce to be active.</p></div>';
        } );
        return;
    }

    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-providers.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-currency.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-api.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-deposit.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-gateway.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-webhook.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-thankyou.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-i18n.php';

    WC_PawaPay_I18n::load();
    WC_PawaPay_Thankyou::init();

    add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
        $gateways[] = 'WC_PawaPay_Gateway';
        return $gateways;
    } );

    add_action( 'init', [ 'WC_PawaPay_Webhook', 'register_endpoint' ] );
    add_action( 'template_redirect', [ 'WC_PawaPay_Webhook', 'handle' ] );
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

function wc_pawapay_missing_github_token_notice(): void {
    if ( ! current_user_can( 'update_plugins' ) || wc_pawapay_github_token() !== '' ) {
        return;
    }

    echo '<div class="notice notice-warning"><p>';
    echo esc_html__( 'PawaPay updates need a GitHub token because the plugin repo is private. Add WC_PAWAPAY_GITHUB_TOKEN to wp-config.php (above “That’s all, stop editing”) or paste a fine-grained token with Contents: Read on mmarcwabo/pawapay-woocommerce in WooCommerce → Settings → Payments → PawaPay.', 'wc-pawapay' );
    echo '</p></div>';
}

add_action( 'plugins_loaded', 'wc_pawapay_init_update_checker', 0 );
add_action( 'admin_notices', 'wc_pawapay_missing_github_token_notice' );

register_activation_hook( __FILE__, function () {
    add_rewrite_endpoint( 'pawapay-webhook', EP_ROOT );
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
