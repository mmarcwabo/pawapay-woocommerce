<?php
defined( 'ABSPATH' ) || exit;

/**
 * Which attempts the background reconciler may GET, and how often.
 */
class WC_PawaPay_Reconciliation_Policy {

    public const MIN_AGE_SECONDS = 180;
    public const MAX_AGE_SECONDS = 172800;
    public const BATCH_SIZE      = 10;
    public const FETCH_LIMIT     = 50;
    public const TICK_SECONDS    = 300;

    /**
     * @return list<string>
     */
    public static function eligible_statuses(): array {
        return [
            WC_PawaPay_Attempt::STATUS_ACCEPTED,
            WC_PawaPay_Attempt::STATUS_UNKNOWN,
            WC_PawaPay_Attempt::STATUS_PROCESSING,
            WC_PawaPay_Attempt::STATUS_INITIATING,
        ];
    }

    public static function interval_seconds( int $age_seconds ): int {
        if ( $age_seconds < 900 ) {
            return 180;
        }
        if ( $age_seconds < 3600 ) {
            return 600;
        }
        if ( $age_seconds < 21600 ) {
            return 1800;
        }
        return 3600;
    }

    public static function is_due( string $created_at, string $updated_at, int $now ): bool {
        $created = strtotime( $created_at . ' UTC' );
        $updated = strtotime( $updated_at . ' UTC' );
        if ( $created === false || $updated === false ) {
            return false;
        }

        $age         = $now - $created;
        $since_check = $now - $updated;

        if ( $age < self::MIN_AGE_SECONDS || $age > self::MAX_AGE_SECONDS ) {
            return false;
        }

        return $since_check >= self::interval_seconds( $age );
    }

    /**
     * @param list<WC_PawaPay_Attempt> $attempts
     * @return list<WC_PawaPay_Attempt>
     */
    public static function select_due( array $attempts, int $now, int $limit = self::BATCH_SIZE ): array {
        $due = [];
        foreach ( $attempts as $attempt ) {
            if ( ! $attempt instanceof WC_PawaPay_Attempt ) {
                continue;
            }
            if ( ! in_array( $attempt->status(), self::eligible_statuses(), true ) ) {
                continue;
            }
            if ( ! self::is_due( $attempt->created_at(), $attempt->updated_at(), $now ) ) {
                continue;
            }
            $due[] = $attempt;
            if ( count( $due ) >= $limit ) {
                break;
            }
        }
        return $due;
    }
}
