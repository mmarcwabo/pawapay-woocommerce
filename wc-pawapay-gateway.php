<?php
/**
 * Plugin Name: PawaPay Mobile Money Gateway for WooCommerce
 * Plugin URI:  https://github.com/mmarcwabo/pawapay-woocommerce
 * Description: Accept mobile money payments via PawaPay (Airtel, Orange, Vodacom).
 * Version:     1.0.1
 * Author:      Maungano
 * Author URI:  https://maungano.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-pawapay
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * Requires Plugins: woocommerce
 * Update URI:  https://github.com/mmarcwabo/pawapay-woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_PAWAPAY_VERSION', '1.0.1' );
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

    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-api.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-gateway.php';
    require_once WC_PAWAPAY_PLUGIN_DIR . 'includes/class-wc-pawapay-webhook.php';

    add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
        $gateways[] = 'WC_PawaPay_Gateway';
        return $gateways;
    } );

    add_action( 'init', [ 'WC_PawaPay_Webhook', 'register_endpoint' ] );
    add_action( 'template_redirect', [ 'WC_PawaPay_Webhook', 'handle' ] );
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

    if ( defined( 'WC_PAWAPAY_GITHUB_TOKEN' ) && WC_PAWAPAY_GITHUB_TOKEN ) {
        $update_checker->setAuthentication( WC_PAWAPAY_GITHUB_TOKEN );
    }
}

add_action( 'plugins_loaded', 'wc_pawapay_init_update_checker', 0 );

register_activation_hook( __FILE__, function () {
    add_rewrite_endpoint( 'pawapay-webhook', EP_ROOT );
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
