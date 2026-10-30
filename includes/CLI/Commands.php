<?php
/**
 * WP-CLI entry point: `wp chrono <subcommand>`.
 *
 * @package WPChrono
 */

namespace WPChrono\CLI;

use WP_CLI;
use WPChrono\Services\Settings;
use WPChrono\Services\SiteInspector;
use WPChrono\Services\SnapshotService;

defined( 'ABSPATH' ) || exit;

final class Commands {

	public static function register(): void {
		WP_CLI::add_command( 'chrono', self::class );
		WP_CLI::add_command( 'chrono reset', ResetCommand::class );
		WP_CLI::add_command( 'chrono snapshot', SnapshotCommand::class );
		WP_CLI::add_command( 'chrono recovery', RecoveryCommand::class );
		WP_CLI::add_command( 'chrono blueprint', BlueprintCommand::class );
		WP_CLI::add_command( 'chrono cloud', CloudCommand::class );
		WP_CLI::add_command( 'chrono license', LicenseCommand::class );
	}

	/**
	 * Show storage usage, the configured caps, and what cleanup would free.
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
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp chrono storage
	 *     wp chrono storage --format=json
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function storage( array $args, array $assoc_args ): void {
		$usage   = ( new SiteInspector() )->storage_usage();
		$settings = Settings::get();

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $usage, JSON_PRETTY_PRINT ) );
			return;
		}

		$rows = array(
			array( 'metric' => 'Storage used',      'value' => size_format( $usage['total_bytes'], 1 ) ),
			array( 'metric' => '  Snapshot tables', 'value' => size_format( $usage['snapshot_bytes'], 1 ) ),
			array( 'metric' => '  Archives + exports', 'value' => size_format( $usage['file_bytes'], 1 ) ),
			array( 'metric' => 'Storage cap (GB)',  'value' => $settings['storage_cap_gb'] . ' GB (' . size_format( $usage['cap_bytes'], 1 ) . ')' ),
			array( 'metric' => 'Auto-snapshot cap', 'value' => $settings['storage_cap_autos'] ),
			array( 'metric' => 'Over byte cap',     'value' => $usage['cap_used_bytes'] ? 'yes' : 'no' ),
			array( 'metric' => 'Over auto cap',     'value' => $usage['cap_used_autos'] ? 'yes' : 'no' ),
			array( 'metric' => 'Disk free',         'value' => null === $usage['free_bytes'] ? 'unknown' : size_format( $usage['free_bytes'], 1 ) ),
			array( 'metric' => 'Storage protected', 'value' => $usage['protected'] ? 'yes' : 'no' ),
		);

		WP_CLI\Utils\format_items( 'table', $rows, array( 'metric', 'value' ) );

		if ( $usage['over_cap'] ) {
			WP_CLI::warning( 'Storage is above the configured cap. Run `wp chrono storage cleanup` to remove old auto-snapshots.' );
		}
	}

	/**
	 * Run the storage-cap cleanup: delete oldest auto-snapshots until under cap.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Only report what would be deleted, without deleting anything.
	 *
	 * [--keep=<autos>]
	 * : Number of auto-snapshots to keep (overrides the storage_cap_autos setting).
	 *
	 * ## EXAMPLES
	 *
	 *     wp chrono storage cleanup
	 *     wp chrono storage cleanup --dry-run
	 *     wp chrono storage cleanup --keep=5
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function storage_cleanup( array $args, array $assoc_args ): void {
		$service = new SnapshotService();
		$keep    = isset( $assoc_args['keep'] ) ? (int) $assoc_args['keep'] : null;

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
			$settings = Settings::get();
			$capAutos = null === $keep ? $settings['storage_cap_autos'] : $keep;
			$autos = array_values(
				array_filter(
					$service->all(),
					static function ( $s ) use ( $capAutos ) {
						return 'auto' === $s['trigger_type'] && 'ready' === $s['status'];
					}
				)
			);
			$autos = array_reverse( $autos ); // oldest first

			$lock = get_transient( SnapshotService::AUTO_LOCK );
			$lockId = is_array( $lock ) ? (int) ( $lock['snapshot'] ?? 0 ) : 0;

			$wouldDelete = array();
			foreach ( $autos as $snap ) {
				$id = (int) $snap['id'];
				if ( $id === $lockId || $service->is_pinned( $id ) ) {
					continue;
				}
				if ( count( $wouldDelete ) >= max( 0, count( $autos ) - $capAutos ) ) {
					break;
				}
				$wouldDelete[] = $id;
			}

			WP_CLI\Utils\format_items(
				'table',
				array_map(
					static function ( $id ) use ( $service ) {
						$s = $service->get( $id );
						return is_wp_error( $s )
							? array( 'id' => $id, 'label' => '?', 'size' => '?' )
							: array( 'id' => $id, 'label' => $s['label'], 'size' => size_format( $s['size_bytes'] ?? 0, 1 ) );
					},
					$wouldDelete
				),
				array( 'id', 'label', 'size' )
			);
			WP_CLI::success( sprintf( 'Dry run: would delete %d auto-snapshot(s).', count( $wouldDelete ) ) );
			return;
		}

		$deleted = $service->enforce_storage_cap( $keep );
		if ( $deleted ) {
			WP_CLI::success( sprintf( 'Deleted %d auto-snapshot(s): %s.', count( $deleted ), implode( ', ', $deleted ) ) );
		} else {
			WP_CLI::success( 'No auto-snapshots needed cleanup.' );
		}
	}
}

	/**
	 * Show database, snapshot and disk status.
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
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp chrono status
	 *     wp chrono status --format=json
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function status( array $args, array $assoc_args ): void {
		$status = ( new SiteInspector() )->status();

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $status, JSON_PRETTY_PRINT ) );
			return;
		}

		$rows = array(
			array( 'metric' => 'Database size', 'value' => size_format( $status['database']['size_bytes'], 1 ) ),
			array( 'metric' => 'Live tables', 'value' => $status['database']['table_count'] ),
			array( 'metric' => 'Snapshots', 'value' => $status['snapshots']['count'] ),
			array( 'metric' => 'Snapshot storage', 'value' => size_format( $status['database']['snapshot_bytes'], 1 ) ),
			array( 'metric' => 'Disk free', 'value' => null === $status['disk']['free_bytes'] ? 'unknown' : size_format( $status['disk']['free_bytes'], 1 ) ),
			array( 'metric' => 'Storage protected', 'value' => $status['storage']['protected'] ? 'yes' : 'no' ),
		);

		WP_CLI\Utils\format_items( 'table', $rows, array( 'metric', 'value' ) );
	}
}
