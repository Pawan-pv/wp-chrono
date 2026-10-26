<?php
/**
 * Database snapshots: chunked creation, listing, deletion, safety and
 * auto-update snapshots. Restore, export and scavenging live in Snapshot\*
 * and are exposed through this facade.
 *
 * A snapshot is a set of `_wpc_{id}_{hash8}_{short_orig}` copies of every
 * site table, each tagged with a JSON COMMENT naming its original, plus rows
 * in the two tracking tables.
 *
 * @package WPChrono
 */

namespace WPChrono\Services;

use WP_Error;
use WPChrono\Core\Schema;
use WPChrono\Services\Snapshot\Exporter;
use WPChrono\Services\Snapshot\FileArchive;
use WPChrono\Services\Snapshot\Restorer;
use WPChrono\Services\Snapshot\Scavenger;
use WPChrono\Services\Snapshot\TableCopier;

defined( 'ABSPATH' ) || exit;

final class SnapshotService {

	const TICK_BUDGET = 5.0;

	const TRIGGERS = array( 'manual', 'auto', 'safety', 'cloud', 'import' );

	const AUTO_LOCK = '_wpchrono_auto_snap_lock';

	const AUTO_LOCK_TTL = 180;

	// ---------------------------------------------------------------------
	// Creation
	// ---------------------------------------------------------------------

	/**
	 * Registers a snapshot and its table list. Nothing is copied yet; call
	 * build() until it reports done.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function create( string $label = '', string $trigger = 'manual', string $note = '' ) {
		global $wpdb;

		$tables = array_keys( Database::site_tables() );
		sort( $tables );
		if ( ! $tables ) {
			return new WP_Error( 'wp_chrono_no_tables', __( 'No tables found to snapshot.', 'wp-chrono' ) );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			Schema::snapshots_table(),
			array(
				'uid'           => strtolower( wp_generate_password( 8, false ) ),
				'label'         => mb_substr( sanitize_text_field( $label ), 0, 191 ),
				'trigger_type'  => in_array( $trigger, self::TRIGGERS, true ) ? $trigger : 'manual',
				'status'        => 'building',
				'source_prefix' => $wpdb->prefix,
				'table_count'   => count( $tables ),
				'meta'          => wp_json_encode( array( 'note' => sanitize_textarea_field( $note ) ) ),
				'created_at'    => current_time( 'mysql', true ),
			)
		);
		if ( ! $inserted ) {
			return new WP_Error( 'wp_chrono_db', __( 'Could not register the snapshot.', 'wp-chrono' ) . ' ' . $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;

		foreach ( $tables as $position => $table ) {
			$wpdb->insert(
				Schema::snapshot_tables_table(),
				array(
					'snapshot_id'    => $id,
					'position'       => $position,
					'source_table'   => $table,
					'snapshot_table' => Schema::snapshot_table_name( (string) $id, $table ),
				)
			);
		}
		// phpcs:enable

		return $this->get( $id );
	}

	/**
	 * One resumable tick: copies tables/rows for up to `$budget` seconds.
	 *
	 * @return array<string,mixed>|WP_Error Progress (see progress()).
	 */
	public function build( int $id, float $budget = self::TICK_BUDGET ) {
		global $wpdb;
		$snapshot = $this->get( $id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( 'ready' === $snapshot['status'] ) {
			return $this->progress( $id );
		}
		if ( 'building' !== $snapshot['status'] ) {
			return new WP_Error( 'wp_chrono_snapshot_state', sprintf( 'Snapshot #%d is %s and cannot be built.', $id, $snapshot['status'] ), array( 'status' => 409 ) );
		}

		$deadline = microtime( true ) + $budget;
		$track    = Schema::snapshot_tables_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$track} WHERE snapshot_id = %d AND status IN ('pending','copying') ORDER BY position", $id ), ARRAY_A );

		foreach ( $rows as $row ) {
			$source = $row['source_table'];
			$copy   = $row['snapshot_table'];

			if ( 'pending' === $row['status'] ) {
				if ( ! TableCopier::exists( $source ) ) {
					// Dropped since the snapshot was registered.
					$wpdb->update( $track, array( 'status' => 'skipped' ), array( 'id' => $row['id'] ) );
					continue;
				}
				$strategy = TableCopier::strategy( $source );
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $copy ) );
				if ( false === $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $copy, $source ) ) ) {
					return $this->fail( $id, "Could not create {$copy}: {$wpdb->last_error}" );
				}
				$create = TableCopier::normalized_create( $source );
				$fks    = TableCopier::foreign_keys( $create );
				TableCopier::set_comment( $copy, self::table_tag( $id, $source, $fks ) );
				$row = array_merge(
					$row,
					array(
						'status'       => 'copying',
						'copy_mode'    => $strategy['mode'],
						'key_columns'  => implode( ',', $strategy['keys'] ),
						'last_key'     => '',
						'create_sql'   => $create,
						'schema_hash'  => md5( $create ),
						'orig_comment' => TableCopier::table_comment( $source ),
						'foreign_keys' => $fks ? wp_json_encode( $fks ) : null,
					)
				);
				$wpdb->update( $track, array_intersect_key( $row, array_flip( array( 'status', 'copy_mode', 'key_columns', 'last_key', 'create_sql', 'schema_hash', 'orig_comment', 'foreign_keys' ) ) ), array( 'id' => $row['id'] ) );
			}

			$state = array(
				'mode'     => $row['copy_mode'],
				'keys'     => '' === $row['key_columns'] ? array() : explode( ',', $row['key_columns'] ),
				'columns'  => TableCopier::column_list( $source ),
				'last_key' => (string) $row['last_key'],
				'rows'     => (int) $row['rows_copied'],
			);

			do {
				$finished = TableCopier::copy_batch( $source, $copy, $state );
				if ( null === $finished ) {
					return $this->fail( $id, "Copy of {$source} failed: {$wpdb->last_error}" );
				}
				$update = array( 'rows_copied' => $state['rows'], 'last_key' => $state['last_key'] );
				if ( $finished ) {
					$update += array( 'status' => 'done', 'row_count' => $state['rows'], 'size_bytes' => TableCopier::size( $copy ) );
				}
				$wpdb->update( $track, $update, array( 'id' => $row['id'] ) );

				if ( ! $finished && microtime( true ) >= $deadline ) {
					return $this->progress( $id, $source );
				}
			} while ( ! $finished );

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$track} WHERE snapshot_id = %d AND status IN ('pending','copying')", $id ) );
		if ( 0 === $remaining ) {
			$totals = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS t, COALESCE(SUM(row_count),0) AS r, COALESCE(SUM(size_bytes),0) AS b FROM {$track} WHERE snapshot_id = %d AND status = 'done'", $id ), ARRAY_A );
			$wpdb->update(
				Schema::snapshots_table(),
				array(
					'status'       => 'ready',
					'table_count'  => (int) $totals['t'],
					'row_count'    => (int) $totals['r'],
					'size_bytes'   => (int) $totals['b'],
					'completed_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $id )
			);
			// phpcs:enable
			$this->verify( $id );
			$ready = $this->get( $id );
			do_action( 'wp_chrono_snapshot_ready', $ready );
			if ( 'auto' === $ready['trigger_type'] ) {
				$this->prune_auto();
			}
		}

		return $this->progress( $id );
	}

	/**
	 * Integrity pass over a finished snapshot: every copy must exist, hold
	 * exactly the number of rows recorded while copying, and have the same
	 * column count as the source schema captured at copy time. Result is
	 * stored as meta `verified` (bool) + `verification` (details), so a
	 * silently incomplete snapshot is flagged instead of trusted.
	 *
	 * @return array{verified:bool,tables:int,rows:int,mismatches:string[],ms:int,checked_at:string}
	 */
	public function verify( int $id ): array {
		global $wpdb;
		$started    = microtime( true );
		$mismatches = array();
		$rows_total = 0;
		$tables     = $this->tables( $id );

		foreach ( $tables as $source => $row ) {
			$copy = (string) $row['snapshot_table'];
			if ( ! TableCopier::exists( $copy ) ) {
				$mismatches[] = "{$source}: copy {$copy} is missing";
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count       = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $copy ) );
			$rows_total += $count;
			if ( $count !== (int) $row['row_count'] ) {
				$mismatches[] = sprintf( '%s: %d rows in the copy, %d recorded', $source, $count, (int) $row['row_count'] );
			}
			$expected = preg_match_all( '/^\s+`(?:[^`]|``)+`\s/m', (string) $row['create_sql'] );
			$columns  = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', $wpdb->dbname, $copy )
			);
			if ( $expected && $columns !== $expected ) {
				$mismatches[] = sprintf( '%s: %d columns in the copy, %d in the source schema', $source, $columns, $expected );
			}
		}

		$result = array(
			'verified'   => ! $mismatches && (bool) $tables,
			'tables'     => count( $tables ),
			'rows'       => $rows_total,
			'mismatches' => $mismatches,
			'ms'         => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'checked_at' => gmdate( 'c' ),
		);
		$this->update_meta( $id, array( 'verified' => $result['verified'], 'verification' => array_diff_key( $result, array( 'verified' => 1 ) ) ) );
		return $result;
	}

	/**
	 * Self-describing JSON COMMENT for a snapshot table, so the scavenger and the
	 * standalone recovery script can identify and restore it — foreign keys
	 * included — even without the tracking tables. MySQL caps table comments at
	 * 2048 characters; when the FK list doesn't fit, `fk` is `"tracked"`.
	 *
	 * @param string[] $fks
	 */
	public static function table_tag( int $id, string $source, array $fks ): string {
		$tag = array( 'wpc' => 1, 'snap' => (string) $id, 'orig' => $source );
		if ( $fks ) {
			$tag['fk'] = $fks;
			if ( strlen( (string) wp_json_encode( $tag ) ) > 2048 ) {
				$tag['fk'] = 'tracked';
			}
		}
		return (string) wp_json_encode( $tag );
	}

	/**
	 * Builds a snapshot to completion in this request (WP-CLI, auto-updates).
	 *
	 * @return array<string,mixed>|WP_Error The finished snapshot.
	 */
	public function create_and_build( string $label, string $trigger, string $note = '' ) {
		$snapshot = $this->create( $label, $trigger, $note );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$progress = $this->build( (int) $snapshot['id'], INF );
		return is_wp_error( $progress ) ? $progress : $this->get( (int) $snapshot['id'] );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function progress( int $id, string $current = '' ): array {
		global $wpdb;
		$track = Schema::snapshot_tables_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stats = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(status IN ('done','skipped')) AS done, COALESCE(SUM(rows_copied),0) AS copied FROM {$track} WHERE snapshot_id = %d", $id ), ARRAY_A );
		$snapshot = $this->get( $id );

		return array(
			'snapshot'      => is_wp_error( $snapshot ) ? null : $snapshot,
			'done'          => ! is_wp_error( $snapshot ) && 'ready' === $snapshot['status'],
			'tables_total'  => (int) $stats['total'],
			'tables_done'   => (int) $stats['done'],
			'rows_copied'   => (int) $stats['copied'],
			'current_table' => $current,
			'cursor'        => (int) $stats['done'],
		);
	}

	/**
	 * Marks a snapshot failed and drops whatever was copied.
	 */
	private function fail( int $id, string $message ): WP_Error {
		global $wpdb;
		$this->drop_tables( $id );
		$this->update_meta( $id, array( 'error' => $message ) );
		$wpdb->update( Schema::snapshots_table(), array( 'status' => 'failed' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return new WP_Error( 'wp_chrono_snapshot_failed', $message, array( 'status' => 500 ) );
	}

	// ---------------------------------------------------------------------
	// Read / delete
	// ---------------------------------------------------------------------

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		global $wpdb;
		$table = Schema::snapshots_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( $this, 'format' ), (array) $rows );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public function get( int $id ) {
		global $wpdb;
		$table = Schema::snapshots_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return new WP_Error( 'wp_chrono_snapshot_missing', sprintf( 'Snapshot #%d does not exist.', $id ), array( 'status' => 404 ) );
		}
		return $this->format( $row );
	}

	/**
	 * Tracking rows for a snapshot's tables, keyed by original table name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function tables( int $id ): array {
		global $wpdb;
		$track = Schema::snapshot_tables_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$track} WHERE snapshot_id = %d AND status = 'done' ORDER BY position", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_column( (array) $rows, null, 'source_table' );
	}

	/**
	 * @return true|WP_Error
	 */
	public function delete( int $id ) {
		global $wpdb;
		$snapshot = $this->get( $id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$this->drop_tables( $id );
		foreach ( (array) ( $snapshot['meta']['exports'] ?? array() ) as $export ) {
			Exporter::delete_file( (string) $export['file'] );
		}
		FileArchive::delete( self::rollback_files( $snapshot ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::snapshot_tables_table(), array( 'snapshot_id' => $id ) );
		$wpdb->delete( Schema::snapshots_table(), array( 'id' => $id ) );
		// phpcs:enable

		do_action( 'wp_chrono_snapshot_deleted', $snapshot );
		return true;
	}

	/**
	 * Drops a snapshot's copies: those in the tracking table plus anything
	 * else carrying its `_wpc_{id}_` tag whose COMMENT names one of this
	 * site's tables. (Snapshot IDs are per site, so on multisite subsite 2
	 * can have its own #{id}; its copies must survive.)
	 */
	private function drop_tables( int $id ): void {
		global $wpdb;
		$track  = Schema::snapshot_tables_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tagged = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, TABLE_COMMENT AS comment FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s',
				$wpdb->dbname,
				$wpdb->esc_like( Schema::SNAPSHOT_TABLE_PREFIX . $id . '_' ) . '%'
			),
			ARRAY_A
		);
		$tables = $wpdb->get_col( $wpdb->prepare( "SELECT snapshot_table FROM {$track} WHERE snapshot_id = %d", $id ) );
		foreach ( (array) $tagged as $row ) {
			$meta = json_decode( (string) $row['comment'], true );
			if ( is_array( $meta ) && isset( $meta['orig'] ) && (string) $id === (string) ( $meta['snap'] ?? '' ) && Database::belongs_to_site( (string) $meta['orig'] ) ) {
				$tables[] = (string) $row['name'];
			}
		}
		Database::without_foreign_keys(
			static function () use ( $tables, $wpdb ) {
				foreach ( array_unique( $tables ) as $table ) {
					$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
				}
			}
		);
		// phpcs:enable
	}

	/**
	 * @param array<string,string> $row
	 * @return array<string,mixed>
	 */
	private function format( array $row ): array {
		$meta = json_decode( (string) $row['meta'], true );
		$meta = is_array( $meta ) ? $meta : array();
		if ( ! empty( $meta['exports'] ) ) {
			foreach ( (array) $meta['exports'] as $i => $export ) {
				$meta['exports'][ $i ]['download_url'] = Exporter::download_url( (string) $export['file'] );
			}
		}
		return array(
			'id'           => (int) $row['id'],
			'uid'          => $row['uid'],
			'label'        => $row['label'],
			'note'         => (string) ( $meta['note'] ?? '' ),
			'trigger_type' => $row['trigger_type'],
			'status'       => $row['status'],
			'table_count'  => (int) $row['table_count'],
			'row_count'    => (int) $row['row_count'],
			'size_bytes'   => (int) $row['size_bytes'],
			'created_at'   => self::utc_iso( $row['created_at'] ),
			'completed_at' => $row['completed_at'] ? self::utc_iso( $row['completed_at'] ) : null,
			'meta'         => $meta,
		);
	}

	/**
	 * Timestamps are stored in UTC; emit an explicit `Z` so browsers don't read
	 * them as local time (mysql_to_rfc3339() omits the zone).
	 */
	public static function utc_iso( string $mysql_gmt ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $mysql_gmt . ' UTC' ) );
	}

	/**
	 * Records a `.sql.gz` already in sealed storage as a snapshot's export
	 * (downloadable from the Snapshots screen). Used by cloud pulls and local
	 * imports, whose dump becomes the new snapshot's export.
	 */
	public function attach_export( int $id, string $file ): void {
		$dir = \WPChrono\Core\Storage::ensure();
		if ( is_wp_error( $dir ) || ! is_file( $dir . basename( $file ) ) ) {
			return;
		}
		$snapshot = $this->get( $id );
		$exports  = is_wp_error( $snapshot ) ? array() : (array) ( $snapshot['meta']['exports'] ?? array() );
		$exports[] = array( 'file' => basename( $file ), 'bytes' => (int) filesize( $dir . basename( $file ) ), 'created_at' => gmdate( 'c' ) );
		$this->update_meta( $id, array( 'exports' => $exports ) );
	}

	/**
	 * Merges keys into a snapshot's meta JSON.
	 *
	 * @param array<string,mixed> $changes
	 */
	public function update_meta( int $id, array $changes ): void {
		global $wpdb;
		$snapshot = $this->get( $id );
		if ( is_wp_error( $snapshot ) ) {
			return;
		}
		$wpdb->update( Schema::snapshots_table(), array( 'meta' => wp_json_encode( array_merge( $snapshot['meta'], $changes ) ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	// ---------------------------------------------------------------------
	// Restore / export / scavenge (delegated)
	// ---------------------------------------------------------------------

	/**
	 * Starts a restore.
	 *
	 * Modes: `db` (database only), `files` (only the plugin / theme folders
	 * archived before an update; finishes in this call) or `full` (both: the
	 * archives are staged now and swapped in right after the atomic table swap).
	 * On a multisite main site the shared network tables are kept unless
	 * `$include_network` (see Database::network_tables()).
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function restore( int $id, bool $drop_extra = false, string $mode = 'db', bool $include_network = false ) {
		if ( 'files' === $mode ) {
			return $this->undo_files( $id );
		}
		return ( new Restorer( $this ) )->start( $id, $drop_extra, 'full' === $mode, $include_network );
	}

	/**
	 * "Undo update": the database snapshot taken before an update plus the
	 * plugin / theme files it replaced. Runs to completion (WP-CLI).
	 *
	 * @return array<string,mixed>|WP_Error Final restore result.
	 */
	public function undo_update( int $id, string $mode = 'full', bool $drop_extra = false, bool $include_network = false ) {
		$tick = $this->restore( $id, $drop_extra, $mode, $include_network );
		while ( ! is_wp_error( $tick ) && empty( $tick['done'] ) ) {
			$tick = $this->restore_tick( (string) $tick['job'], INF );
		}
		return $tick;
	}

	/**
	 * Puts back the archived plugin / theme folders of a snapshot, without
	 * touching the database.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function undo_files( int $id ) {
		$snapshot = $this->get( $id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$entries = self::rollback_files( $snapshot );
		if ( ! $entries ) {
			return new WP_Error( 'wp_chrono_no_rollback_files', sprintf( 'Snapshot #%d has no plugin or theme files to revert.', $id ), array( 'status' => 409 ) );
		}
		$started = microtime( true );
		$job     = strtolower( wp_generate_password( 6, false ) );
		$staged  = FileArchive::stage( $entries, $job );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}
		$result = FileArchive::commit( $staged );
		$this->update_meta( $id, array( 'last_restored_at' => gmdate( 'c' ) ) );

		return array(
			'done'            => true,
			'phase'           => 'files',
			'snapshot_id'     => $id,
			'tables_restored' => 0,
			'tables_removed'  => 0,
			'tables_kept'     => array(),
			'rows_copied'     => 0,
			'files_restored'  => $result['restored'],
			'swap_ms'         => 0,
			'duration_ms'     => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'nonces'          => null,
			'log'             => $result['log'],
			'redirect'        => admin_url( 'admin.php?page=wp-chrono#/snapshots' ),
		);
	}

	/**
	 * Plugin / theme archives attached to a snapshot.
	 *
	 * @param array<string,mixed> $snapshot
	 * @return array<int,array<string,mixed>>
	 */
	public static function rollback_files( array $snapshot ): array {
		return array_values( (array) ( $snapshot['meta']['rollback']['files'] ?? array() ) );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public function restore_tick( string $job, float $budget = self::TICK_BUDGET ) {
		return ( new Restorer( $this ) )->tick( $job, $budget );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public function export_tick( int $id, string $job = '', float $budget = self::TICK_BUDGET ) {
		return ( new Exporter( $this ) )->tick( $id, $job, $budget );
	}

	public function scavenger(): Scavenger {
		return new Scavenger( $this );
	}

	// ---------------------------------------------------------------------
	// Automatic snapshots
	// ---------------------------------------------------------------------

	/**
	 * `wp_chrono_safety_snapshot` filter (fired by ResetService::preflight).
	 * Over REST the snapshot is only registered — the client builds it in ticks
	 * before running the reset. Under WP-CLI it is built to completion here.
	 *
	 * @param mixed $result
	 * @return mixed
	 */
	public static function safety_snapshot( $result, string $target ) {
		if ( null !== $result ) {
			return $result;
		}
		$service = new self();
		$label   = 'full' === $target ? 'Before full reset' : sprintf( 'Before %s cleanup', str_replace( '_', ' ', $target ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $service->create_and_build( $label, 'safety' );
		}
		return $service->create( $label, 'safety' );
	}

	/**
	 * `upgrader_pre_install` filter. Takes ONE snapshot per update batch: the
	 * first package grabs a 180s transient lock holding the snapshot ID; the
	 * rest of the batch (bulk updates, auto-update runs) reuses it. Every
	 * package's current plugin / theme folder is zipped and attached to that
	 * snapshot, so the batch can be undone files + database together. (This
	 * filter runs before WordPress moves the old version aside.)
	 *
	 * @param bool|WP_Error       $response
	 * @param array<string,mixed> $hook_extra
	 * @return bool|WP_Error Unchanged — a failed snapshot never blocks an update.
	 */
	public static function before_update( $response, $hook_extra ) {
		if ( is_wp_error( $response ) || ! Settings::get()['auto_snapshot'] ) {
			return $response;
		}
		$hook_extra = (array) $hook_extra;
		if ( 'install' === ( $hook_extra['action'] ?? '' ) ) {
			return $response; // Fresh installs change nothing existing.
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$service = new self();
		$lock    = get_transient( self::AUTO_LOCK );
		if ( false === $lock ) {
			set_transient( self::AUTO_LOCK, array( 'snapshot' => 0 ), self::AUTO_LOCK_TTL );
			// Probe before anything is replaced: the baseline Safe Updates compares against.
			$baseline = SafeUpdates::enabled() ? SafeUpdates::probe() : null;
			$subject  = $hook_extra['plugin'] ?? $hook_extra['theme'] ?? ( $hook_extra['type'] ?? 'update' );
			$result   = $service->create_and_build( 'Before updating ' . $subject, 'auto' );
			if ( is_wp_error( $result ) ) {
				error_log( 'WP Chrono: auto-snapshot failed: ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				return $response;
			}
			$lock = array( 'snapshot' => (int) $result['id'] );
			set_transient( self::AUTO_LOCK, $lock, self::AUTO_LOCK_TTL );
			if ( null !== $baseline ) {
				SafeUpdates::watch( (int) $result['id'], $baseline );
			}
		}

		$snapshot_id = is_array( $lock ) ? (int) ( $lock['snapshot'] ?? 0 ) : 0;
		if ( $snapshot_id ) {
			$service->attach_archives( $snapshot_id, FileArchive::targets( $hook_extra ) );
		}
		return $response;
	}

	/**
	 * `upgrader_post_install` filter: records the version each archived
	 * plugin / theme was updated to, and where WordPress put it. (A
	 * single-file plugin is deleted and re-installed into a new folder; undo
	 * removes that folder so no duplicate plugin is left behind.)
	 *
	 * @param bool|WP_Error       $response
	 * @param array<string,mixed> $hook_extra
	 * @param array<string,mixed> $result     Install result (destination, …).
	 * @return bool|WP_Error Unchanged.
	 */
	public static function after_update( $response, $hook_extra, $result = array() ) {
		$lock = get_transient( self::AUTO_LOCK );
		if ( is_wp_error( $response ) || ! is_array( $lock ) || empty( $lock['snapshot'] ) ) {
			return $response;
		}
		$service  = new self();
		$snapshot = $service->get( (int) $lock['snapshot'] );
		if ( is_wp_error( $snapshot ) ) {
			return $response;
		}
		$files   = self::rollback_files( $snapshot );
		$changed = false;
		foreach ( FileArchive::targets( (array) $hook_extra ) as $target ) {
			foreach ( $files as $i => $entry ) {
				if ( $entry['type'] === $target['type'] && $entry['slug'] === $target['slug'] ) {
					$files[ $i ]['new_version'] = FileArchive::version( $target );
					$destination                = untrailingslashit( wp_normalize_path( (string) ( ( (array) $result )['destination'] ?? '' ) ) );
					if ( ! empty( $entry['single'] ) && '' !== $destination ) {
						$files[ $i ]['installed_to']   = $destination;
						$files[ $i ]['is_single_file'] = true;
						$files[ $i ]['new_version']    = FileArchive::folder_plugin_version( $destination, (string) ( $entry['name'] ?? '' ) );
					}
					$changed = true;
				}
			}
		}
		if ( $changed ) {
			$service->update_meta( (int) $lock['snapshot'], array( 'rollback' => array_merge( (array) ( $snapshot['meta']['rollback'] ?? array() ), array( 'files' => $files ) ) ) );
		}
		return $response;
	}

	/**
	 * Zips plugin / theme folders into storage and records them on a snapshot.
	 * Targets already archived for this snapshot are skipped.
	 *
	 * @param array<int,array<string,mixed>> $targets From FileArchive::targets().
	 * @return array<int,array<string,mixed>> Updated manifest.
	 */
	public function attach_archives( int $snapshot_id, array $targets ): array {
		$snapshot = $this->get( $snapshot_id );
		if ( is_wp_error( $snapshot ) ) {
			return array();
		}
		$rollback = (array) ( $snapshot['meta']['rollback'] ?? array() );
		$files    = array_values( (array) ( $rollback['files'] ?? array() ) );
		$skipped  = array_values( (array) ( $rollback['skipped'] ?? array() ) );
		$have     = array();
		foreach ( $files as $entry ) {
			$have[ $entry['type'] . ':' . $entry['slug'] ] = true;
		}

		foreach ( $targets as $target ) {
			if ( isset( $have[ $target['type'] . ':' . $target['slug'] ] ) ) {
				continue;
			}
			$entry = FileArchive::archive( $snapshot_id, $target );
			if ( is_wp_error( $entry ) ) {
				$skipped[] = array( 'type' => $target['type'], 'slug' => $target['slug'], 'reason' => $entry->get_error_message() );
				continue;
			}
			$files[] = $entry;
		}
		$this->update_meta( $snapshot_id, array( 'rollback' => array( 'files' => $files, 'skipped' => $skipped ) ) );
		return $files;
	}

	/**
	 * Keeps only the newest N ready auto-snapshots (default: the retention
	 * setting). Their tables, exports and rollback archives go with them.
	 *
	 * @return int[] IDs deleted.
	 */
	public function prune_auto( ?int $keep = null ): array {
		$keep  = max( 1, null === $keep ? (int) Settings::get()['auto_retention'] : $keep );
		$autos = array_values(
			array_filter(
				$this->all(),
				static function ( $s ) {
					return 'auto' === $s['trigger_type'] && 'ready' === $s['status'];
				}
			)
		);
		$deleted = array();
		foreach ( array_slice( $autos, $keep ) as $old ) {
			if ( true === $this->delete( $old['id'] ) ) {
				$deleted[] = $old['id'];
			}
		}
		return $deleted;
	}
}
