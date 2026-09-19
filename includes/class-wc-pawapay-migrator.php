<?php
defined( 'ABSPATH' ) || exit;

/**
 * Schema versioning for {prefix}pawapay_transactions.
 */
class WC_PawaPay_Migrator {

    public const SCHEMA_VERSION = 1;
    public const OPTION_KEY     = 'wc_pawapay_schema_version';
    public const TABLE_SUFFIX   = 'pawapay_transactions';

    public static function table_name( string $prefix = 'wp_' ): string {
        return $prefix . self::TABLE_SUFFIX;
    }

    public static function schema_sql( string $table, string $charset_collate = '' ): string {
        $charset = $charset_collate !== '' ? $charset_collate : 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  deposit_id varchar(36) NOT NULL,
  provider varchar(64) NOT NULL DEFAULT '',
  msisdn_hash char(64) NOT NULL DEFAULT '',
  masked_msisdn varchar(32) NOT NULL DEFAULT '',
  order_currency char(3) NOT NULL DEFAULT '',
  order_amount varchar(32) NOT NULL DEFAULT '',
  payment_currency char(3) NOT NULL DEFAULT '',
  payment_amount varchar(32) NOT NULL DEFAULT '',
  exchange_rate varchar(32) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'CREATED',
  provider_transaction_id varchar(128) NOT NULL DEFAULT '',
  failure_code varchar(64) NOT NULL DEFAULT '',
  failure_message varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  completed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY deposit_id (deposit_id),
  KEY order_id (order_id),
  KEY status (status),
  KEY created_at (created_at)
) {$charset};";
    }

    public static function should_skip_upgrade( int $current_version, bool $table_exists ): bool {
        return $current_version >= self::SCHEMA_VERSION && $table_exists;
    }

    public static function table_exists(): bool {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
            return false;
        }

        $table = self::table_name( (string) $wpdb->prefix );
        $like  = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $table ) : $table;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

        return $found === $table;
    }

    public static function maybe_upgrade(): void {
        if ( ! function_exists( 'get_option' ) ) {
            return;
        }

        $current = (int) get_option( self::OPTION_KEY, 0 );
        if ( self::should_skip_upgrade( $current, self::table_exists() ) ) {
            return;
        }

        self::install();
        if ( self::table_exists() ) {
            update_option( self::OPTION_KEY, self::SCHEMA_VERSION, true );
        }
    }

    public static function install(): void {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return;
        }

        $upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
        if ( is_readable( $upgrade ) ) {
            require_once $upgrade;
        }
        if ( ! function_exists( 'dbDelta' ) ) {
            return;
        }

        $table   = self::table_name( (string) $wpdb->prefix );
        $charset = method_exists( $wpdb, 'get_charset_collate' ) ? (string) $wpdb->get_charset_collate() : '';
        dbDelta( self::schema_sql( $table, $charset ) );
    }
}
