<?php
defined( 'ABSPATH' ) || exit;

class WC_PawaPay_Admin {

    public const PAGE   = 'wc-pawapay-attempts';
    public const ACTION = 'wc_pawapay_check_attempt';
    public const CAP    = 'manage_woocommerce';

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'menu' ], 60 );
        add_action( 'add_meta_boxes', [ self::class, 'metaboxes' ] );
        add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle_check' ] );
        add_action( 'admin_notices', [ self::class, 'notices' ] );
    }

    public static function menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'PawaPay attempts', 'wc-pawapay' ),
            __( 'PawaPay attempts', 'wc-pawapay' ),
            self::CAP,
            self::PAGE,
            [ self::class, 'render_page' ]
        );
    }

    public static function metaboxes(): void {
        $screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];
        foreach ( $screens as $screen ) {
            add_meta_box(
                'pawapay-attempts',
                __( 'PawaPay attempts', 'wc-pawapay' ),
                [ self::class, 'render_metabox' ],
                $screen,
                'side',
                'default'
            );
        }
    }

    public static function render_page(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You are not allowed to view payment attempts.', 'wc-pawapay' ) );
        }

        $status = strtoupper( sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) ) );
        if ( $status !== '' && ! in_array( $status, WC_PawaPay_Attempt::statuses(), true ) ) {
            $status = '';
        }

        $page   = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $limit  = 20;
        $offset = ( $page - 1 ) * $limit;
        $repo   = WC_PawaPay_Attempt_Repository::instance();
        $rows   = $repo->find_recent( $limit, $offset, $status );
        $total  = $repo->count_all( $status );
        $pages  = max( 1, (int) ceil( $total / $limit ) );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'PawaPay attempts', 'wc-pawapay' ) . '</h1>';
        echo '<p>' . esc_html__( 'Each row is one PawaPay deposit. Phone numbers are masked. Check status runs a merchant GET.', 'wc-pawapay' ) . '</p>';
        self::render_table( $rows, [ 'show_order' => true ] );

        if ( $pages > 1 ) {
            echo '<p class="tablenav">';
            echo paginate_links( [
                'base'    => add_query_arg( 'paged', '%#%' ),
                'format'  => '',
                'current' => $page,
                'total'   => $pages,
            ] );
            echo '</p>';
        }
        echo '</div>';
    }

    /**
     * @param mixed $post_or_order
     */
    public static function render_metabox( $post_or_order ): void {
        $order = self::order_from_screen( $post_or_order );
        if ( ! $order instanceof WC_Order || $order->get_payment_method() !== 'pawapay' ) {
            echo '<p>' . esc_html__( 'No PawaPay attempts on this order.', 'wc-pawapay' ) . '</p>';
            return;
        }

        $rows = WC_PawaPay_Attempt_Repository::instance()->find_for_order( (int) $order->get_id() );
        if ( ! $rows ) {
            echo '<p>' . esc_html__( 'No PawaPay attempts on this order.', 'wc-pawapay' ) . '</p>';
            return;
        }

        self::render_table( $rows, [ 'show_order' => false ] );
    }

    public static function handle_check(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You are not allowed to check PawaPay status.', 'wc-pawapay' ) );
        }

        $deposit_id = sanitize_text_field( wp_unslash( $_POST['deposit_id'] ?? '' ) );
        check_admin_referer( self::ACTION . '_' . $deposit_id );

        $attempt = WC_PawaPay_Attempt_Repository::instance()->find_by_deposit_id( $deposit_id );
        $order   = $attempt ? wc_get_order( $attempt->order_id() ) : null;

        if ( ! $attempt || ! $order instanceof WC_Order ) {
            wp_safe_redirect( self::redirect_url( 'missing' ) );
            exit;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway  = $gateways['pawapay'] ?? null;
        if ( ! $gateway instanceof WC_PawaPay_Gateway ) {
            wp_safe_redirect( self::redirect_url( 'missing' ) );
            exit;
        }

        WC_PawaPay_Deposit::sync_deposit(
            $order,
            $attempt->deposit_id(),
            $gateway->get_api(),
            'admin',
            [ 'fail_woo_on_failed' => $gateway->get_option( 'fail_woo_on_failed_deposit' ) === 'yes' ]
        );

        wp_safe_redirect( self::redirect_url( 'checked', (int) $order->get_id() ) );
        exit;
    }

    public static function notices(): void {
        if ( ! current_user_can( self::CAP ) ) {
            return;
        }
        $flag = sanitize_text_field( wp_unslash( $_GET['pawapay_admin'] ?? '' ) );
        if ( $flag === 'checked' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'PawaPay status checked.', 'wc-pawapay' ) . '</p></div>';
        }
        if ( $flag === 'missing' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That payment attempt was not found.', 'wc-pawapay' ) . '</p></div>';
        }
    }

    /**
     * @param list<WC_PawaPay_Attempt> $attempts
     * @param array{show_order?: bool} $options
     */
    private static function render_table( array $attempts, array $options = [] ): void {
        $show_order = ! empty( $options['show_order'] );

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        if ( $show_order ) {
            echo '<th>' . esc_html__( 'Order', 'wc-pawapay' ) . '</th>';
        }
        echo '<th>' . esc_html__( 'Status', 'wc-pawapay' ) . '</th>';
        echo '<th>' . esc_html__( 'Phone', 'wc-pawapay' ) . '</th>';
        echo '<th>' . esc_html__( 'Operator', 'wc-pawapay' ) . '</th>';
        echo '<th>' . esc_html__( 'Amount', 'wc-pawapay' ) . '</th>';
        echo '<th>' . esc_html__( 'Created', 'wc-pawapay' ) . '</th>';
        echo '<th>' . esc_html__( 'Deposit', 'wc-pawapay' ) . '</th>';
        echo '<th></th>';
        echo '</tr></thead><tbody>';

        if ( ! $attempts ) {
            echo '<tr><td colspan="8">' . esc_html__( 'No payment attempts yet.', 'wc-pawapay' ) . '</td></tr>';
        }

        foreach ( $attempts as $attempt ) {
            $row      = WC_PawaPay_Providers::find( $attempt->provider() );
            $view     = WC_PawaPay_Admin_Attempt_View::row( $attempt, (string) ( $row['label'] ?? '' ) );
            $order_id = (int) $view['order_id'];
            $order    = $order_id ? wc_get_order( $order_id ) : null;
            $edit     = $order instanceof WC_Order ? $order->get_edit_order_url() : '';

            echo '<tr>';
            if ( $show_order ) {
                echo '<td>';
                if ( $edit !== '' ) {
                    echo '<a href="' . esc_url( $edit ) . '">#' . esc_html( (string) $order_id ) . '</a>';
                } else {
                    echo '#' . esc_html( (string) $order_id );
                }
                echo '</td>';
            }
            echo '<td>' . esc_html( (string) $view['status'] );
            if ( (string) $view['failure_code'] !== '' ) {
                echo '<br><code>' . esc_html( (string) $view['failure_code'] ) . '</code>';
            }
            echo '</td>';
            echo '<td>' . esc_html( (string) $view['masked_phone'] ) . '</td>';
            echo '<td>' . esc_html( (string) $view['provider'] ) . '</td>';
            echo '<td>' . esc_html( trim( (string) $view['amount'] . ' ' . (string) $view['currency'] ) ) . '</td>';
            echo '<td>' . esc_html( (string) $view['created_at'] ) . '</td>';
            echo '<td><code>' . esc_html( (string) $view['deposit_id'] ) . '</code></td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
            echo '<input type="hidden" name="deposit_id" value="' . esc_attr( (string) $view['deposit_id'] ) . '">';
            wp_nonce_field( self::ACTION . '_' . (string) $view['deposit_id'] );
            echo '<button type="submit" class="button button-small">' . esc_html__( 'Check status', 'wc-pawapay' ) . '</button>';
            echo '</form></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function redirect_url( string $flag, int $order_id = 0 ): string {
        $ref = wp_get_referer();
        if ( is_string( $ref ) && $ref !== '' ) {
            return add_query_arg( 'pawapay_admin', $flag, $ref );
        }
        if ( $order_id > 0 ) {
            $order = wc_get_order( $order_id );
            if ( $order instanceof WC_Order ) {
                return add_query_arg( 'pawapay_admin', $flag, $order->get_edit_order_url() );
            }
        }
        return add_query_arg(
            [ 'page' => self::PAGE, 'pawapay_admin' => $flag ],
            admin_url( 'admin.php' )
        );
    }

    /**
     * @param mixed $post_or_order
     */
    private static function order_from_screen( $post_or_order ): ?WC_Order {
        if ( $post_or_order instanceof WC_Order ) {
            return $post_or_order;
        }
        if ( is_object( $post_or_order ) && isset( $post_or_order->ID ) ) {
            $order = wc_get_order( (int) $post_or_order->ID );
            return $order instanceof WC_Order ? $order : null;
        }
        $id = absint( $_GET['id'] ?? ( $_GET['post'] ?? 0 ) );
        $order = $id ? wc_get_order( $id ) : null;
        return $order instanceof WC_Order ? $order : null;
    }
}
