<?php
defined( 'ABSPATH' ) || exit;

/**
 * Persistence for payment attempts.
 *
 * WordPress uses $wpdb. Tests can construct an in-memory instance.
 */
class WC_PawaPay_Attempt_Repository {

    /** @var array<string, WC_PawaPay_Attempt> */
    private array $memory = [];

    private int $memory_ids = 0;

    private bool $use_memory;

    public function __construct( bool $use_memory = false ) {
        $this->use_memory = $use_memory || ! $this->wpdb_ready();
    }

    public static function instance(): self {
        static $instance = null;
        if ( ! $instance instanceof self ) {
            $instance = new self();
        }
        return $instance;
    }

    public static function memory(): self {
        return new self( true );
    }

    public function insert( WC_PawaPay_Attempt $attempt ): bool {
        if ( $attempt->deposit_id() === '' || $attempt->order_id() <= 0 ) {
            return false;
        }

        if ( $this->find_by_deposit_id( $attempt->deposit_id() ) ) {
            return false;
        }

        if ( $this->use_memory ) {
            $this->memory_ids++;
            $attempt->assign_id( $this->memory_ids );
            $this->memory[ $attempt->deposit_id() ] = $attempt;
            return true;
        }

        global $wpdb;
        $ok = $wpdb->insert( $this->table(), $attempt->to_row(), $this->row_formats() );
        if ( $ok === false ) {
            $this->log( 'warning', $attempt, 'Attempt insert failed (table missing or duplicate deposit_id).' );
            return false;
        }

        $attempt->assign_id( (int) $wpdb->insert_id );
        $this->log( 'info', $attempt, 'Attempt recorded' );
        return true;
    }

    public function find_by_deposit_id( string $deposit_id ): ?WC_PawaPay_Attempt {
        $deposit_id = trim( $deposit_id );
        if ( $deposit_id === '' ) {
            return null;
        }

        if ( $this->use_memory ) {
            return $this->memory[ $deposit_id ] ?? null;
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE deposit_id = %s LIMIT 1",
                $deposit_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? WC_PawaPay_Attempt::from_row( $row ) : null;
    }

    /**
     * @return list<WC_PawaPay_Attempt>
     */
    public function find_for_order( int $order_id ): array {
        if ( $order_id <= 0 ) {
            return [];
        }

        if ( $this->use_memory ) {
            $found = [];
            foreach ( $this->memory as $attempt ) {
                if ( $attempt->order_id() === $order_id ) {
                    $found[] = $attempt;
                }
            }
            usort( $found, static fn( WC_PawaPay_Attempt $a, WC_PawaPay_Attempt $b ) => $a->id() <=> $b->id() );
            return $found;
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE order_id = %d ORDER BY id ASC",
                $order_id
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return [];
        }

        return array_map( [ 'WC_PawaPay_Attempt', 'from_row' ], $rows );
    }

    /**
     * @return list<WC_PawaPay_Attempt>
     */
    public function find_active_for_order( int $order_id ): array {
        return array_values( array_filter(
            $this->find_for_order( $order_id ),
            static fn( WC_PawaPay_Attempt $attempt ) => $attempt->is_active()
        ) );
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function mark_status( string $deposit_id, string $status, array $extra = [] ): bool {
        $attempt = $this->find_by_deposit_id( $deposit_id );
        if ( ! $attempt ) {
            return false;
        }
        if ( $attempt->is_completed() ) {
            return true;
        }
        if ( ! $attempt->apply_status( $status, $extra ) ) {
            return true;
        }

        return $this->update( $attempt );
    }

    /**
     * Atomically move an attempt to COMPLETED. Winner is the only caller that gets "claimed".
     *
     * @return 'claimed'|'already'|'missing'
     */
    public function claim_completion( string $deposit_id, array $extra = [] ): string {
        $attempt = $this->find_by_deposit_id( $deposit_id );
        if ( ! $attempt ) {
            return 'missing';
        }
        if ( $attempt->is_completed() ) {
            return 'already';
        }
        if ( ! $attempt->apply_status( WC_PawaPay_Attempt::STATUS_COMPLETED, $extra ) ) {
            return 'already';
        }

        if ( $this->use_memory ) {
            $this->memory[ $attempt->deposit_id() ] = $attempt;
            return 'claimed';
        }

        global $wpdb;
        $now = $attempt->updated_at();
        $ok  = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table()} SET status = %s, completed_at = %s, updated_at = %s, provider_transaction_id = %s WHERE deposit_id = %s AND status <> %s",
                WC_PawaPay_Attempt::STATUS_COMPLETED,
                $attempt->completed_at() ?? $now,
                $now,
                $attempt->provider_transaction_id(),
                $deposit_id,
                WC_PawaPay_Attempt::STATUS_COMPLETED
            )
        );

        return $ok === 1 ? 'claimed' : 'already';
    }

    public function update( WC_PawaPay_Attempt $attempt ): bool {
        if ( $attempt->deposit_id() === '' ) {
            return false;
        }

        if ( $this->use_memory ) {
            $this->memory[ $attempt->deposit_id() ] = $attempt;
            return true;
        }

        global $wpdb;
        $row = $attempt->to_row();
        unset( $row['deposit_id'], $row['order_id'], $row['created_at'] );

        $ok = $wpdb->update(
            $this->table(),
            $row,
            [ 'deposit_id' => $attempt->deposit_id() ],
            $this->update_formats(),
            [ '%s' ]
        );

        return $ok !== false;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function backfill_from_meta( int $order_id, array $meta, bool $order_paid = false ): ?WC_PawaPay_Attempt {
        $attempt = WC_PawaPay_Attempt::from_legacy_meta( $order_id, $meta, $order_paid );
        if ( ! $attempt ) {
            return null;
        }

        $existing = $this->find_by_deposit_id( $attempt->deposit_id() );
        if ( $existing ) {
            return $existing;
        }

        return $this->insert( $attempt ) ? $attempt : null;
    }

    private function table(): string {
        global $wpdb;
        $prefix = ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ) ? (string) $wpdb->prefix : 'wp_';
        return WC_PawaPay_Migrator::table_name( $prefix );
    }

    private function wpdb_ready(): bool {
        global $wpdb;
        return isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'insert' );
    }

    /**
     * @return list<string>
     */
    private function row_formats(): array {
        return [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];
    }

    /**
     * @return list<string>
     */
    private function update_formats(): array {
        return [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];
    }

    private function log( string $level, WC_PawaPay_Attempt $attempt, string $message ): void {
        if ( ! function_exists( 'wc_get_logger' ) ) {
            return;
        }

        $line = sprintf(
            '[PawaPay][Order %d][Attempt %d][Deposit %s] %s',
            $attempt->order_id(),
            $attempt->id(),
            $attempt->deposit_id(),
            $message
        );

        wc_get_logger()->{$level}( $line, [ 'source' => 'wc-pawapay' ] );
    }
}
