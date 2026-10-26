<?php
/**
 * Finds and drops snapshot-like tables that no snapshot owns.
 *
 * Candidates come from INFORMATION_SCHEMA: `_wpc_*`, `_wpchrono_snap_*`,
 * tables whose COMMENT carries `"wpc":1`, and legacy WP Reset copies
 * (`{6 hex}_{prefix}…`). A candidate is spared when:
 * - it belongs to a snapshot in the tracking table, or to a running restore;
 * - its name starts with this site's live prefix (never touch live tables);
 * - its COMMENT / embedded name says it belongs to another install sharing
 *   the database (different table prefix).
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Snapshot;

use WPChrono\Core\Schema;
use WPChrono\Services\Database;
use WPChrono\Services\SnapshotService;

defined( 'ABSPATH' ) || exit;

final class Scavenger {

	/** @var SnapshotService */
	private $snapshots;

	public function __construct( SnapshotService $snapshots ) {
		$this->snapshots = $snapshots;
	}

	/**
	 * @return array{tables:array<int,array{name:string,reason:string,rows:int,bytes:int}>,bytes:int}
	 */
	public function scan(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME AS name, TABLE_ROWS AS row_estimate, DATA_LENGTH + INDEX_LENGTH AS bytes, TABLE_COMMENT AS comment
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = 'BASE TABLE'
				AND ( TABLE_NAME LIKE %s OR TABLE_NAME LIKE %s OR TABLE_COMMENT LIKE %s OR TABLE_NAME REGEXP %s )",
				$wpdb->dbname,
				$wpdb->esc_like( Schema::SNAPSHOT_TABLE_PREFIX ) . '%',
				$wpdb->esc_like( Schema::LEGACY_SNAPSHOT_TABLE_PREFIX ) . '%',
				'%' . $wpdb->esc_like( '"wpc":1' ) . '%',
				'^[0-9a-f]{6}_' . preg_quote( $wpdb->prefix, '/' )
			),
			ARRAY_A
		);

		$track = Schema::snapshot_tables_table();
		$owned = array_flip( (array) $wpdb->get_col( "SELECT snapshot_table FROM {$track}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:enable
		$job       = Restorer::active_job();
		$job_stage = $job ? Schema::SNAPSHOT_TABLE_PREFIX . 'stg_' . $job['id'] . '_' : null;

		$orphans = array();
		$bytes   = 0;
		foreach ( (array) $candidates as $table ) {
			$name = (string) $table['name'];
			if ( isset( $owned[ $name ] ) || ( $job_stage && 0 === strpos( $name, $job_stage ) ) ) {
				continue;
			}
			if ( 0 === strpos( $name, $wpdb->prefix ) ) {
				continue; // A live table, whatever its comment says.
			}
			$reason = $this->classify( $name, (string) $table['comment'] );
			if ( null === $reason ) {
				continue;
			}
			$orphans[] = array(
				'name'   => $name,
				'reason' => $reason,
				'rows'   => (int) $table['row_estimate'],
				'bytes'  => (int) $table['bytes'],
			);
			$bytes += (int) $table['bytes'];
		}

		return array( 'tables' => $orphans, 'bytes' => $bytes );
	}

	/**
	 * Drops orphans found by a fresh scan. When `$only` is given, just those
	 * names, and only if the scan still classifies them as orphans.
	 *
	 * @param string[]|null $only
	 * @return string[] Dropped table names.
	 */
	public function purge( ?array $only = null ): array {
		global $wpdb;
		$names = array_column( $this->scan()['tables'], 'name' );
		if ( null !== $only ) {
			$names = array_values( array_intersect( $names, $only ) );
		}
		Database::without_foreign_keys(
			static function () use ( $names, $wpdb ) {
				foreach ( $names as $name ) {
					$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::quote_identifier( $name ) ); // phpcs:ignore WordPress.DB
				}
			}
		);
		return $names;
	}

	/**
	 * Why a candidate is an orphan, or null if it belongs to another install.
	 */
	private function classify( string $name, string $comment ): ?string {
		global $wpdb;

		$meta = json_decode( $comment, true );
		if ( is_array( $meta ) && isset( $meta['orig'] ) && ! Database::belongs_to_site( (string) $meta['orig'] ) ) {
			return null; // Tagged for a different table prefix, or another subsite of this network.
		}

		$p = preg_quote( Schema::SNAPSHOT_TABLE_PREFIX, '/' );
		if ( preg_match( '/^' . $p . '(stg|old)_[a-z0-9]+_[0-9a-f]{8}_(.*)$/', $name, $m ) ) {
			if ( ! $this->embedded_prefix_matches( $m[2] ) ) {
				return null;
			}
			return 'stg' === $m[1] ? 'Abandoned restore staging table' : 'Leftover table from an interrupted restore swap';
		}
		if ( preg_match( '/^' . $p . '(\d+)_[0-9a-f]{8}_(.*)$/', $name, $m ) ) {
			if ( ! $this->embedded_prefix_matches( $m[2] ) ) {
				return null;
			}
			return sprintf( 'Copy from snapshot #%d, which no longer exists', $m[1] );
		}
		if ( 0 === strpos( $name, Schema::LEGACY_SNAPSHOT_TABLE_PREFIX ) ) {
			return 'Pre-release WP Chrono snapshot table';
		}
		if ( preg_match( '/^[0-9a-f]{6}_(.*)$/', $name, $m ) ) {
			return $this->embedded_prefix_matches( $m[1] ) ? 'Legacy WP Reset snapshot table' : null;
		}
		if ( is_array( $meta ) && 1 === ( $meta['wpc'] ?? null ) ) {
			return 'Tagged WP Chrono table with no owning snapshot';
		}
		return null;
	}

	/**
	 * Embedded originals are truncated to fit 64 chars, so compare only as far
	 * as both strings go. On a multisite main site, `wp_2_…` names belong to a
	 * subsite and never match.
	 */
	private function embedded_prefix_matches( string $orig ): bool {
		global $wpdb;
		$len = min( strlen( $orig ), strlen( $wpdb->prefix ) );
		return substr( $orig, 0, $len ) === substr( $wpdb->prefix, 0, $len ) && ! Database::is_other_subsite_table( $orig );
	}
}
