<?php
/**
 * `wp chrono reset …`
 *
 * @package WPChrono
 */

namespace WPChrono\CLI;

use WP_CLI;
use WPChrono\Services\ResetService;

defined( 'ABSPATH' ) || exit;

/**
 * Reset the whole site or clean specific data.
 */
final class ResetCommand {

	/** @var ResetService */
	private $service;

	public function __construct() {
		$this->service = new ResetService();
	}

	/**
	 * Drop every site table and reinstall WordPress.
	 *
	 * The admin running the command (--user, or the oldest administrator)
	 * keeps their ID, password and sessions. WP Chrono stays active and all
	 * snapshot tables are preserved.
	 *
	 * ## OPTIONS
	 *
	 * [--keep-theme]
	 * : Reactivate the current theme after the reset.
	 *
	 * [--keep-plugins]
	 * : Reactivate currently active plugins after the reset.
	 *
	 * [--keep-users]
	 * : Keep the users and usermeta tables.
	 *
	 * [--[no-]snapshot]
	 * : Take a pre-reset safety snapshot (default). Use --no-snapshot to skip it.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp chrono reset site --yes --keep-plugins
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function site( array $args, array $assoc_args ): void {
		WP_CLI::confirm( 'This drops every table of this site and reinstalls WordPress. Continue?', $assoc_args );

		$this->preflight( 'full', $assoc_args );

		$result = $this->service->full_reset(
			array(
				'keep_theme'   => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'keep-theme', false ),
				'keep_plugins' => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'keep-plugins', false ),
				'keep_users'   => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'keep-users', false ),
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result );
		}

		foreach ( $result['steps'] as $step ) {
			WP_CLI::log( sprintf( '%6d ms  %s', $step['ms'], $step['label'] ) );
		}
		foreach ( $result['plugin_errors'] as $error ) {
			WP_CLI::warning( $error );
		}
		WP_CLI::success( sprintf( 'Site reset in %.2fs (%d tables dropped).', $result['duration_ms'] / 1000, $result['tables_dropped'] ) );
	}

	/**
	 * Run one partial cleanup.
	 *
	 * ## OPTIONS
	 *
	 * <target>
	 * : What to clean.
	 * ---
	 * options:
	 *   - transients
	 *   - woocommerce
	 *   - uploads
	 *   - inactive_plugins
	 *   - inactive_themes
	 *   - custom_tables
	 *   - orphaned_meta
	 *   - options
	 * ---
	 *
	 * [--dry-run]
	 * : Show what would be removed without changing anything.
	 *
	 * [--mode=<mode>]
	 * : For custom_tables: empty the tables or remove them.
	 * ---
	 * default: truncate
	 * options:
	 *   - truncate
	 *   - drop
	 * ---
	 *
	 * [--[no-]snapshot]
	 * : Take a pre-reset safety snapshot (default). Use --no-snapshot to skip it.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp chrono reset partial transients --dry-run
	 *     wp chrono reset partial custom_tables --mode=drop --yes
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function partial( array $args, array $assoc_args ): void {
		list( $target ) = $args;

		$preview = $this->service->preview( $target );
		if ( is_wp_error( $preview ) ) {
			WP_CLI::error( $preview );
		}
		if ( ! $preview['available'] ) {
			WP_CLI::error( $preview['reason'] );
		}

		WP_CLI::log( sprintf( '%s: %s items, %s%s', $target, number_format( $preview['items'] ), size_format( $preview['bytes'], 1 ) ?: '0 B', $preview['approximate'] ? ' (estimate)' : '' ) );
		foreach ( $preview['details'] as $line ) {
			WP_CLI::log( '  · ' . $line );
		}

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
			WP_CLI::success( 'Dry run: nothing was changed.' );
			return;
		}
		if ( 0 === $preview['items'] && 0 === $preview['bytes'] ) {
			WP_CLI::success( 'Nothing to clean.' );
			return;
		}

		WP_CLI::confirm( "Run the {$target} cleanup?", $assoc_args );
		$this->preflight( $target, $assoc_args );

		$options = array(
			'mode'   => $assoc_args['mode'] ?? 'truncate',
			'cursor' => 0,
		);
		$total   = 0;
		do {
			$result = $this->service->partial_reset( $target, false, $options, INF );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result );
			}
			foreach ( $result['log'] as $line ) {
				WP_CLI::log( $line );
			}
			$total            += $result['processed'];
			$options['cursor'] = $result['cursor'];
		} while ( ! $result['done'] );

		WP_CLI::success( sprintf( '%s cleanup finished (%d items).', $target, $total ) );
	}

	/**
	 * @param array<string,string|bool> $assoc_args
	 */
	private function preflight( string $target, array $assoc_args ): void {
		$snapshot = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'snapshot', true );
		foreach ( $this->service->preflight( $target, $snapshot )['log'] as $line ) {
			WP_CLI::log( $line );
		}
	}
}
