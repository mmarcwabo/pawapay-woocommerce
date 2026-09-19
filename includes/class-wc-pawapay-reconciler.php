<?php
defined( 'ABSPATH' ) || exit;

/**
 * Background GET /deposits/{id} for stale ACCEPTED/UNKNOWN attempts.
 */
class WC_PawaPay_Reconciler {

    public const HOOK  = 'wc_pawapay_reconcile';
    public const GROUP = 'pawapay';

    public static function init(): void {
        add_action( self::HOOK, [ self::class, 'run' ] );
        add_action( 'init', [ self::class, 'ensure_scheduled' ] );
    }

    public static function ensure_scheduled(): void {
        if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
            return;
        }

        if ( self::is_scheduled() ) {
            return;
        }

        as_schedule_recurring_action(
            time() + 60,
            WC_PawaPay_Reconciliation_Policy::TICK_SECONDS,
            self::HOOK,
            [],
            self::GROUP
        );
    }

    public static function unschedule(): void {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::HOOK, [], self::GROUP );
        }
    }

    public static function run(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
            return;
        }

        $gateway = WC()->payment_gateways()->payment_gateways()['pawapay'] ?? null;
        if ( ! $gateway instanceof WC_PawaPay_Gateway ) {
            return;
        }

        $repo = WC_PawaPay_Attempt_Repository::instance();
        $now  = time();
        $due  = self::due_attempts( $repo, $now );
        if ( ! $due ) {
            return;
        }

        $options = [
            'fail_woo_on_failed' => $gateway->get_option( 'fail_woo_on_failed_deposit' ) === 'yes',
        ];

        foreach ( $due as $attempt ) {
            self::reconcile_attempt( $attempt, $gateway->get_api(), $repo, $options );
        }
    }

    /**
     * @return list<WC_PawaPay_Attempt>
     */
    public static function due_attempts( WC_PawaPay_Attempt_Repository $repo, int $now ): array {
        $window = $repo->find_in_created_window(
            WC_PawaPay_Reconciliation_Policy::eligible_statuses(),
            $now - WC_PawaPay_Reconciliation_Policy::MAX_AGE_SECONDS,
            $now - WC_PawaPay_Reconciliation_Policy::MIN_AGE_SECONDS,
            WC_PawaPay_Reconciliation_Policy::FETCH_LIMIT
        );

        return WC_PawaPay_Reconciliation_Policy::select_due(
            $window,
            $now,
            WC_PawaPay_Reconciliation_Policy::BATCH_SIZE
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function reconcile_attempt(
        WC_PawaPay_Attempt $attempt,
        WC_PawaPay_Client $api,
        WC_PawaPay_Attempt_Repository $repo,
        array $options = []
    ): string {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $attempt->order_id() ) : null;
        if ( ! $order instanceof WC_Order || $order->get_payment_method() !== 'pawapay' ) {
            $repo->touch( $attempt->deposit_id() );
            return 'skipped';
        }

        $before = $attempt->updated_at();
        WC_PawaPay_Deposit::sync_deposit(
            $order,
            $attempt->deposit_id(),
            $api,
            'reconciliation',
            $options
        );

        $fresh = $repo->find_by_deposit_id( $attempt->deposit_id() );
        if ( $fresh && $fresh->is_active() && $fresh->updated_at() === $before ) {
            $repo->touch( $attempt->deposit_id() );
        }

        return $fresh && $fresh->is_completed() ? 'completed' : 'checked';
    }

    private static function is_scheduled(): bool {
        if ( function_exists( 'as_has_scheduled_action' ) ) {
            return as_has_scheduled_action( self::HOOK, [], self::GROUP );
        }
        if ( function_exists( 'as_next_scheduled_action' ) ) {
            return as_next_scheduled_action( self::HOOK, [], self::GROUP ) !== false;
        }
        return true;
    }
}
