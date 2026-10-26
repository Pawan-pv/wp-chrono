<?php
/**
 * `wp chrono snapshot …`
 *
 * @package WPChrono
 */

namespace WPChrono\CLI;

use WP_CLI;
use WPChrono\Services\DiffService;
use WPChrono\Services\SnapshotService;

defined( 'ABSPATH' ) || exit;

/**
 * Create, inspect, restore and export database snapshots.
 */
final class SnapshotCommand {

	/** @var SnapshotService */
	private $service;

	public function __construct() {
		$this->service = new SnapshotService();
	}

	/**
	 * Snapshot every site table.
	 *
	 * ## OPTIONS
	 *
	 * [--label=<label>]
	 * : Short name for the snapshot.
	 *
	 * [--note=<note>]
	 * : Longer description.
	 *
	 * ## EXAMPLES
	 *
	 *     wp chrono snapshot create --label="Before theme switch"
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function create( array $args, array $assoc_args ): void {
		$snapshot = $this->service->create( (string) ( $assoc_args['label'] ?? '' ), 'manual', (string) ( $assoc_args['note'] ?? '' ) );
		if ( is_wp_error( $snapshot ) ) {
			WP_CLI::error( $snapshot );
		}

		$progress = WP_CLI\Utils\make_progress_bar( "Snapshot #{$snapshot['id']}", $snapshot['table_count'] );
		$done     = 0;
		do {
			$tick = $this->service->build( $snapshot['id'] );
			if ( is_wp_error( $tick ) ) {
				WP_CLI::error( $tick );
			}
			$progress->tick( $tick['tables_done'] - $done );
			$done = $tick['tables_done'];
		} while ( ! $tick['done'] );
		$progress->finish();

		$ready = $tick['snapshot'];
		if ( empty( $ready['meta']['verified'] ) ) {
			WP_CLI::warning( 'Integrity verification failed: ' . implode( '; ', (array) ( $ready['meta']['verification']['mismatches'] ?? array() ) ) );
		}
		WP_CLI::success( sprintf( 'Snapshot #%d ready%s: %d tables, %s rows, %s.', $ready['id'], empty( $ready['meta']['verified'] ) ? '' : ' and verified', $ready['table_count'], number_format( $ready['row_count'] ), size_format( $ready['size_bytes'], 1 ) ) );
	}

	/**
	 * Re-check a snapshot's integrity (every copy exists with the recorded row
	 * and column counts).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Snapshot ID.
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function verify( array $args, array $assoc_args ): void {
		$snapshot = $this->service->get( (int) $args[0] );
		if ( is_wp_error( $snapshot ) ) {
			WP_CLI::error( $snapshot );
		}
		$result = $this->service->verify( (int) $args[0] );
		foreach ( $result['mismatches'] as $line ) {
			WP_CLI::warning( $line );
		}
		if ( ! $result['verified'] ) {
			WP_CLI::error( sprintf( 'Snapshot #%d failed verification (%d problem(s)).', $args[0], count( $result['mismatches'] ) ) );
		}
		WP_CLI::success( sprintf( 'Snapshot #%d verified: %d tables, %s rows match (%d ms).', $args[0], $result['tables'], number_format( $result['rows'] ), $result['ms'] ) );
	}

	/**
	 * List snapshots.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * @subcommand list
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function list_( array $args, array $assoc_args ): void {
		if ( 'ids' === ( $assoc_args['format'] ?? '' ) ) {
			WP_CLI::line( implode( ' ', array_column( $this->service->all(), 'id' ) ) );
			return;
		}
		$rows = array_map(
			static function ( $s ) {
				return array(
					'id'      => $s['id'],
					'label'   => $s['label'],
					'trigger' => $s['trigger_type'],
					'status'  => $s['status'],
					'tables'  => $s['table_count'],
					'rows'    => $s['row_count'],
					'size'    => size_format( $s['size_bytes'], 1 ),
					'created' => $s['created_at'],
				);
			},
			$this->service->all()
		);
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'label', 'trigger', 'status', 'tables', 'rows', 'size', 'created' ) );
	}

	/**
	 * Compare a snapshot with the live database.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Snapshot ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function diff( array $args, array $assoc_args ): void {
		$diff = ( new DiffService( $this->service ) )->compare( (int) $args[0] );
		if ( is_wp_error( $diff ) ) {
			WP_CLI::error( $diff );
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $diff, JSON_PRETTY_PRINT ) );
			return;
		}

		$t    = $diff['tables'];
		$rows = array();
		foreach ( $t['added_tables'] as $x ) {
			$rows[] = array( 'table' => $x['table'], 'change' => 'added since snapshot', 'rows' => '+' . $x['rows'] );
		}
		foreach ( $t['removed_tables'] as $x ) {
			$rows[] = array( 'table' => $x['table'], 'change' => 'removed since snapshot', 'rows' => '-' . $x['rows'] );
		}
		foreach ( $t['modified_tables'] as $x ) {
			$what   = array_filter( array( $x['rows_delta'] ? 'rows' : '', $x['schema_changed'] ? 'schema' : '', $x['content_changed'] ? 'content' : '' ) );
			$rows[] = array( 'table' => $x['table'], 'change' => implode( '+', $what ), 'rows' => sprintf( '%+d', $x['rows_delta'] ) );
		}
		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'table', 'change', 'rows' ) );
		}
		WP_CLI::log( sprintf( '%d tables unchanged.', $t['unchanged_tables'] ) );

		$o = $diff['options'];
		if ( $o['available'] ) {
			$opt = array();
			foreach ( array( 'added', 'deleted', 'modified' ) as $kind ) {
				foreach ( $o[ $kind ] as $x ) {
					$opt[] = array( 'option' => $x['name'], 'change' => $kind, 'summary' => $x['summary'] ?? '' );
				}
			}
			if ( $opt ) {
				WP_CLI::log( '' );
				WP_CLI\Utils\format_items( 'table', $opt, array( 'option', 'change', 'summary' ) );
			} else {
				WP_CLI::log( 'No wp_options changes.' );
			}
		}
	}

	/**
	 * Restore a snapshot with an atomic table swap.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Snapshot ID.
	 *
	 * [--drop-extra]
	 * : Also remove tables created after the snapshot was taken.
	 *
	 * [--mode=<mode>]
	 * : What to restore. `full` also reverts the plugin / theme files archived
	 * before an update (auto-snapshots only); `files` reverts only those files.
	 * ---
	 * default: db
	 * options:
	 *   - db
	 *   - files
	 *   - full
	 * ---
	 *
	 * [--include-network-tables]
	 * : Multisite main site only: also restore the network-wide tables (users,
	 * usermeta, blogs, site, sitemeta, blogmeta, signups). Off by default so a
	 * main-site rollback never wipes subsite users or blogs.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp chrono snapshot restore 12 --yes
	 *     wp chrono snapshot restore 14 --mode=full --yes   # undo a plugin update
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function restore( array $args, array $assoc_args ): void {
		$id   = (int) $args[0];
		$mode = (string) ( $assoc_args['mode'] ?? 'db' );
		WP_CLI::confirm( 'files' === $mode ? "Revert the plugin / theme files archived with snapshot #{$id}?" : "Replace the live database with snapshot #{$id}?", $assoc_args );

		$tick = $this->service->restore( $id, (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'drop-extra', false ), $mode, (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'include-network-tables', false ) );
		if ( is_wp_error( $tick ) ) {
			WP_CLI::error( $tick );
		}
		if ( $tick['done'] ) {
			foreach ( $tick['log'] as $line ) {
				WP_CLI::log( $line );
			}
			WP_CLI::success( sprintf( 'Reverted %d plugin/theme folder(s) from snapshot #%d.', $tick['files_restored'], $id ) );
			return;
		}
		$progress = WP_CLI\Utils\make_progress_bar( 'Staging', $tick['tables_total'] );
		$done     = 0;
		while ( ! $tick['done'] ) {
			$tick = $this->service->restore_tick( $tick['job'] );
			if ( is_wp_error( $tick ) ) {
				WP_CLI::error( $tick );
			}
			$now = $tick['done'] ? $tick['tables_restored'] : $tick['tables_done'];
			$progress->tick( $now - $done );
			$done = $now;
		}
		$progress->finish();

		foreach ( $tick['log'] as $line ) {
			WP_CLI::log( $line );
		}
		WP_CLI::success(
			sprintf(
				'Restored snapshot #%d: %d tables, %s rows; atomic swap took %d ms%s.',
				$id,
				$tick['tables_restored'],
				number_format( $tick['rows_copied'] ),
				$tick['swap_ms'],
				$tick['files_restored'] ? sprintf( '; %d plugin/theme folder(s) reverted', $tick['files_restored'] ) : ''
			)
		);
	}

	/**
	 * Export a snapshot to a .sql.gz file in WP Chrono's protected storage folder.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Snapshot ID.
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function export( array $args, array $assoc_args ): void {
		$job = '';
		do {
			$tick = $this->service->export_tick( (int) $args[0], $job, INF );
			if ( is_wp_error( $tick ) ) {
				WP_CLI::error( $tick );
			}
			$job = $tick['job'] ?? '';
		} while ( ! $tick['done'] );

		$dir = \WPChrono\Core\Storage::ensure();
		WP_CLI::success( sprintf( 'Exported to %s%s (%s).', is_wp_error( $dir ) ? '' : $dir, $tick['file'], size_format( $tick['bytes'], 1 ) ) );
	}

	/**
	 * Import a WP Chrono .sql.gz export as a new (verified) snapshot.
	 *
	 * The file is validated (WP Chrono header, gzip integrity, end-of-export
	 * marker, and only this site's tables / whitelisted statements) and loaded
	 * into snapshot copies — never into live tables. Restore it afterwards.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a .sql.gz exported by WP Chrono.
	 *
	 * [--porcelain]
	 * : Output just the new snapshot ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp chrono snapshot import ~/backups/snapshot-20261026-101500-abc.sql.gz
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function import( array $args, array $assoc_args ): void {
		$tick = ( new \WPChrono\Services\Snapshot\LocalImport( $this->service ) )->from_file( $args[0] );
		if ( is_wp_error( $tick ) ) {
			WP_CLI::error( $tick );
		}
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( (string) $tick['snapshot_id'] );
			return;
		}
		WP_CLI::success( $tick['message'] );
	}

	/**
	 * Delete a snapshot and its tables.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Snapshot ID.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function delete( array $args, array $assoc_args ): void {
		WP_CLI::confirm( "Delete snapshot #{$args[0]}?", $assoc_args );
		$result = $this->service->delete( (int) $args[0] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result );
		}
		WP_CLI::success( "Deleted snapshot #{$args[0]}." );
	}

	/**
	 * Find and drop orphaned snapshot tables.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Only list orphans.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function scavenge( array $args, array $assoc_args ): void {
		$scavenger = $this->service->scavenger();
		$scan      = $scavenger->scan();
		if ( ! $scan['tables'] ) {
			WP_CLI::success( 'No orphaned tables found.' );
			return;
		}

		WP_CLI\Utils\format_items(
			'table',
			array_map(
				static function ( $t ) {
					return array( 'table' => $t['name'], 'reason' => $t['reason'], 'size' => size_format( $t['bytes'], 1 ) ?: '0 B' );
				},
				$scan['tables']
			),
			array( 'table', 'reason', 'size' )
		);

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
			WP_CLI::success( sprintf( 'Dry run: %d orphaned tables found.', count( $scan['tables'] ) ) );
			return;
		}
		WP_CLI::confirm( sprintf( 'Drop these %d tables?', count( $scan['tables'] ) ), $assoc_args );
		WP_CLI::success( sprintf( 'Dropped %d orphaned tables.', count( $scavenger->purge() ) ) );
	}
}
