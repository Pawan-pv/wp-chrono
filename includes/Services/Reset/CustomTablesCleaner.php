<?php
/**
 * Truncates or drops every non-core table that carries the site prefix.
 *
 * Options: `mode` = truncate (default) | drop.
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Reset;

use WPChrono\Services\Database;

defined( 'ABSPATH' ) || exit;

final class CustomTablesCleaner extends Cleaner {

	public function availability() {
		if ( is_multisite() ) {
			return 'Not available on multisite: other subsites\' tables share this prefix.';
		}
		return $this->tables() ? true : 'No custom tables found.';
	}

	public function preview(): array {
		$rows    = 0;
		$bytes   = 0;
		$details = array();
		foreach ( $this->tables() as $name => $stats ) {
			$rows     += $stats['rows'];
			$bytes    += $stats['bytes'];
			$details[] = sprintf( '%s (~%d rows)', $name, $stats['rows'] );
		}
		return self::summary( $rows, $bytes, $details, true );
	}

	public function run( array $options, float $deadline ): array {
		global $wpdb;
		$drop   = 'drop' === ( $options['mode'] ?? 'truncate' );
		$cursor = (int) ( $options['cursor'] ?? 0 );
		$tables = array_keys( $this->tables() );
		sort( $tables );

		// When dropping, finished tables disappear from the list, so the cursor only
		// matters for truncation, where every table remains.
		$pending = $drop ? $tables : array_slice( $tables, $cursor );
		$log     = array();
		$done    = 0;

		$finished = Database::without_foreign_keys(
			function () use ( $pending, $drop, $deadline, $wpdb, &$log, &$done, &$cursor ) {
				foreach ( $pending as $table ) {
					$sql = ( $drop ? 'DROP TABLE IF EXISTS ' : 'TRUNCATE TABLE ' ) . Database::quote_identifier( $table );
					if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB
						$log[] = "Failed on {$table}: {$wpdb->last_error}";
					} else {
						$log[] = ( $drop ? 'Dropped ' : 'Truncated ' ) . $table . '.';
						$done++;
					}
					$cursor++;
					if ( self::out_of_time( $deadline ) ) {
						return false;
					}
				}
				return true;
			}
		);

		return self::result( $finished, $done, $log, $drop ? 0 : $cursor );
	}

	/**
	 * @return array<string,array{rows:int,bytes:int}>
	 */
	private function tables(): array {
		return array_diff_key( Database::site_tables(), array_flip( Database::core_tables() ) );
	}
}
