<?php
/**
 * Low-level table helpers shared by the reset and snapshot engines.
 *
 * @package WPChrono
 */

namespace WPChrono\Services;

use WPChrono\Core\Schema;

defined( 'ABSPATH' ) || exit;

final class Database {

	public static function quote_identifier( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Runs $callback with InnoDB foreign key checks disabled, so drops, truncates
	 * and restores never trip over FK constraints (WooCommerce, Action Scheduler…).
	 * Checks are re-enabled even if the callback throws.
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public static function without_foreign_keys( callable $callback ) {
		global $wpdb;
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		try {
			return $callback();
		} finally {
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Whether a table belongs to the current site.
	 *
	 * Single site: anything carrying `$wpdb->prefix`. Multisite subsite: only
	 * `wp_{blog_id}_*` (the escaped prefix never matches `wp_22_` for blog 2).
	 * Multisite main site: `wp_*` minus every `wp_{N}_*` subsite table, so a
	 * main-site snapshot, restore or diff never touches `wp_2_posts`.
	 */
	public static function belongs_to_site( string $name ): bool {
		global $wpdb;
		return 0 === strpos( $name, $wpdb->prefix ) && ! self::is_other_subsite_table( $name );
	}

	/**
	 * On a multisite main site: true for `{base_prefix}{N}_…` (a subsite's table).
	 */
	public static function is_other_subsite_table( string $name ): bool {
		global $wpdb;
		if ( ! is_multisite() || $wpdb->prefix !== $wpdb->base_prefix ) {
			return false;
		}
		return (bool) preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/', $name );
	}

	/**
	 * Network-wide tables a multisite main site shares with every subsite
	 * (users, usermeta, blogs, site, sitemeta, blogmeta, signups). Empty on
	 * single sites and subsites. Main-site restores leave these alone unless a
	 * super admin explicitly includes them, so rolling back the main site's
	 * content never wipes subsite users or network blogs.
	 *
	 * @return string[]
	 */
	public static function network_tables(): array {
		global $wpdb;
		if ( ! is_multisite() || $wpdb->prefix !== $wpdb->base_prefix ) {
			return array();
		}
		$names = array();
		foreach ( array( 'users', 'usermeta', 'blogs', 'site', 'sitemeta', 'blogmeta', 'signups' ) as $table ) {
			if ( ! empty( $wpdb->$table ) ) {
				$names[] = (string) $wpdb->$table;
			}
		}
		return $names;
	}

	/**
	 * Live tables belonging to this site (see belongs_to_site()), minus
	 * WP Chrono's own tables and any snapshot copies.
	 * Row counts come from INFORMATION_SCHEMA and are estimates for InnoDB.
	 *
	 * @return array<string,array{rows:int,bytes:int}> Keyed by table name, largest first.
	 */
	public static function site_tables(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_estimate, DATA_LENGTH + INDEX_LENGTH AS bytes
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = %s AND TABLE_NAME LIKE %s
				ORDER BY bytes DESC',
				$wpdb->dbname,
				'BASE TABLE',
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			),
			ARRAY_A
		);

		$internal = Schema::internal_tables();
		$tables   = array();
		foreach ( (array) $rows as $row ) {
			$name = (string) $row['name'];
			if ( in_array( $name, $internal, true ) || Schema::is_snapshot_table( $name ) || ! self::belongs_to_site( $name ) ) {
				continue;
			}
			$tables[ $name ] = array(
				'rows'  => (int) $row['row_estimate'],
				'bytes' => (int) $row['bytes'],
			);
		}

		return $tables;
	}

	/**
	 * Prefixed names of WordPress core tables (blog + global).
	 *
	 * @return string[]
	 */
	public static function core_tables(): array {
		global $wpdb;
		return array_values( $wpdb->tables( 'all', true ) );
	}

	public static function table_has_rows( string $table ): bool {
		global $wpdb;
		return null !== $wpdb->get_var( 'SELECT 1 FROM ' . self::quote_identifier( $table ) . ' LIMIT 1' ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Comma-separated, integer-cast ID list for `IN (…)` clauses.
	 *
	 * @param array<int|string> $ids
	 */
	public static function id_list( array $ids ): string {
		return implode( ',', array_map( 'intval', $ids ) );
	}
}
