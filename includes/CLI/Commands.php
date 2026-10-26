<?php
/**
 * WP-CLI entry point: `wp chrono <subcommand>`.
 *
 * @package WPChrono
 */

namespace WPChrono\CLI;

use WP_CLI;
use WPChrono\Services\SiteInspector;

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
