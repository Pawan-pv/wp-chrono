<?php
/**
 * Deletes `_transient_*` / `_site_transient_*` rows from wp_options
 * (and wp_sitemeta on multisite).
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Reset;

defined( 'ABSPATH' ) || exit;

final class TransientsCleaner extends Cleaner {

	public function preview(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB -- the WHERE fragments are built with $wpdb->prepare().
		$options = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_name) + LENGTH(option_value)), 0) AS b FROM {$wpdb->options} WHERE " . $this->options_where(), ARRAY_A );
		$items   = (int) $options['n'];
		$bytes   = (int) $options['b'];
		$details = array( sprintf( '%d rows in %s', $items, $wpdb->options ) );

		if ( is_multisite() ) {
			$site     = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(meta_key) + LENGTH(meta_value)), 0) AS b FROM {$wpdb->sitemeta} WHERE " . $this->sitemeta_where(), ARRAY_A );
			$items   += (int) $site['n'];
			$bytes   += (int) $site['b'];
			$details[] = sprintf( '%d rows in %s', (int) $site['n'], $wpdb->sitemeta );
		}
		// phpcs:enable

		if ( wp_using_ext_object_cache() ) {
			$details[] = 'A persistent object cache is active; it will be flushed too.';
		}

		return self::summary( $items, $bytes, $details );
	}

	public function run( array $options, float $deadline ): array {
		global $wpdb;
		$processed = 0;
		$targets   = array( array( $wpdb->options, $this->options_where() ) );
		if ( is_multisite() ) {
			$targets[] = array( $wpdb->sitemeta, $this->sitemeta_where() );
		}

		foreach ( $targets as list( $table, $where ) ) {
			do {
				$deleted    = (int) $wpdb->query( "DELETE FROM {$table} WHERE {$where} LIMIT " . self::BATCH ); // phpcs:ignore WordPress.DB
				$processed += $deleted;
				if ( $deleted >= self::BATCH && self::out_of_time( $deadline ) ) {
					return self::result( false, $processed, array( sprintf( 'Deleted %d transient rows so far…', $processed ) ) );
				}
			} while ( $deleted >= self::BATCH );
		}

		wp_cache_flush();
		$log = array( sprintf( 'Deleted %d transient rows.', $processed ) );
		if ( wp_using_ext_object_cache() ) {
			$log[] = 'Flushed the persistent object cache.';
		}

		return self::result( true, $processed, $log );
	}

	private function options_where(): string {
		global $wpdb;
		return $wpdb->prepare(
			'(option_name LIKE %s OR option_name LIKE %s)',
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_site_transient_' ) . '%'
		);
	}

	private function sitemeta_where(): string {
		global $wpdb;
		return $wpdb->prepare( 'meta_key LIKE %s', $wpdb->esc_like( '_site_transient_' ) . '%' );
	}
}
