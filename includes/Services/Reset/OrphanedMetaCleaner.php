<?php
/**
 * Purges meta rows whose parent post, comment or term no longer exists.
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Reset;

use WPChrono\Services\Database;

defined( 'ABSPATH' ) || exit;

final class OrphanedMetaCleaner extends Cleaner {

	public function preview(): array {
		global $wpdb;
		$items   = 0;
		$bytes   = 0;
		$details = array();

		foreach ( $this->relations() as list( $meta, $fk, $parent, $pk ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(m.meta_value)), 0) AS b FROM {$meta} m LEFT JOIN {$parent} p ON p.{$pk} = m.{$fk} WHERE p.{$pk} IS NULL", ARRAY_A );
			$items    += (int) $row['n'];
			$bytes    += (int) $row['b'];
			$details[] = sprintf( '%d orphaned rows in %s', (int) $row['n'], $meta );
		}

		return self::summary( $items, $bytes, $details );
	}

	public function run( array $options, float $deadline ): array {
		global $wpdb;
		$total = 0;
		$log   = array();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $this->relations() as list( $meta, $fk, $parent, $pk, $id ) ) {
			$count = 0;
			do {
				$ids = $wpdb->get_col( "SELECT m.{$id} FROM {$meta} m LEFT JOIN {$parent} p ON p.{$pk} = m.{$fk} WHERE p.{$pk} IS NULL LIMIT " . self::BATCH );
				if ( $ids ) {
					$count += (int) $wpdb->query( "DELETE FROM {$meta} WHERE {$id} IN (" . Database::id_list( $ids ) . ')' );
				}
				if ( count( $ids ) >= self::BATCH && self::out_of_time( $deadline ) ) {
					$log[] = sprintf( 'Purged %d rows from %s so far…', $count, $meta );
					return self::result( false, $total + $count, $log );
				}
			} while ( count( $ids ) >= self::BATCH );

			$total += $count;
			$log[]  = sprintf( 'Purged %d orphaned rows from %s.', $count, $meta );
		}
		// phpcs:enable

		wp_cache_flush();
		return self::result( true, $total, $log );
	}

	/**
	 * [meta table, meta FK column, parent table, parent PK, meta PK]
	 *
	 * @return array<int,string[]>
	 */
	private function relations(): array {
		global $wpdb;
		return array(
			array( $wpdb->postmeta, 'post_id', $wpdb->posts, 'ID', 'meta_id' ),
			array( $wpdb->commentmeta, 'comment_id', $wpdb->comments, 'comment_ID', 'meta_id' ),
			array( $wpdb->termmeta, 'term_id', $wpdb->terms, 'term_id', 'meta_id' ),
		);
	}
}
