<?php
defined( 'ABSPATH' ) || exit;

/**
 * Short-lived lock so two process_payment calls cannot both POST /deposits.
 */
class WC_PawaPay_Initiation_Lock {

    public const TTL = 90;

    /** @var array<int, int> */
    private array $memory = [];

    private bool $use_memory;

    public function __construct( bool $use_memory = false ) {
        $this->use_memory = $use_memory || ! function_exists( 'add_option' );
    }

    public static function memory(): self {
        return new self( true );
    }

    public function acquire( int $order_id, int $ttl = self::TTL ): bool {
        if ( $order_id <= 0 ) {
            return false;
        }

        $now = time();
        if ( $this->use_memory ) {
            $held = $this->memory[ $order_id ] ?? 0;
            if ( $held > 0 && ( $now - $held ) < $ttl ) {
                return false;
            }
            $this->memory[ $order_id ] = $now;
            return true;
        }

        $key      = self::option_key( $order_id );
        $existing = get_option( $key, false );
        if ( $existing !== false && ( $now - (int) $existing ) < $ttl ) {
            return false;
        }
        if ( $existing !== false ) {
            delete_option( $key );
        }

        return add_option( $key, (string) $now, '', false );
    }

    public function release( int $order_id ): void {
        if ( $order_id <= 0 ) {
            return;
        }
        if ( $this->use_memory ) {
            unset( $this->memory[ $order_id ] );
            return;
        }
        delete_option( self::option_key( $order_id ) );
    }

    public static function option_key( int $order_id ): string {
        return 'wc_pawapay_init_lock_' . $order_id;
    }
}
