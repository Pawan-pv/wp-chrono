<?php
/**
 * `wp chrono blueprint …`
 *
 * @package WPChrono
 */

namespace WPChrono\CLI;

use WP_CLI;
use WPChrono\Services\BlueprintService;
use WPChrono\Services\ResetService;
use WPChrono\Services\SnapshotService;

defined( 'ABSPATH' ) || exit;

/**
 * Capture, share and apply "dev baseline" blueprints.
 */
final class BlueprintCommand {

	/** @var BlueprintService */
	private $service;

	public function __construct() {
		$this->service = new BlueprintService();
	}

	/**
	 * List blueprints.
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
	 *   - ids
	 * ---
	 *
	 * @subcommand list
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function list_( array $args, array $assoc_args ): void {
		$all = $this->service->all();
		if ( 'ids' === ( $assoc_args['format'] ?? '' ) ) {
			WP_CLI::line( implode( ' ', array_keys( $all ) ) );
			return;
		}
		$rows = array();
		foreach ( $all as $bp ) {
			$s      = BlueprintService::summary( $bp );
			$rows[] = array(
				'id'       => $s['id'],
				'name'     => $s['name'],
				'plugins'  => $s['plugin_count'],
				'themes'   => $s['theme_count'],
				'options'  => $s['option_count'],
				'packages' => size_format( $s['package_bytes'], 1 ) ?: '0 B',
				'created'  => $s['created_at'],
			);
		}
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'name', 'plugins', 'themes', 'options', 'packages', 'created' ) );
	}

	/**
	 * Capture the current site as a blueprint.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Blueprint name.
	 *
	 * [--description=<text>]
	 * : Longer description.
	 *
	 * [--[no-]bundle]
	 * : Store local zips of plugins and themes (default). --no-bundle re-installs from wordpress.org instead.
	 * ---
	 * default: true
	 * ---
	 *
	 * [--active-only]
	 * : Only include active plugins.
	 *
	 * [--porcelain]
	 * : Output just the new blueprint ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp chrono blueprint create "Agency baseline"
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function create( array $args, array $assoc_args ): void {
		$locked = \WPChrono\Services\LicenseService::require( 'blueprints' );
		if ( $locked ) {
			WP_CLI::error( $locked->get_error_message() );
		}
		$bp = $this->service->capture(
			$args[0],
			array(
				'description'      => (string) ( $assoc_args['description'] ?? '' ),
				'bundle'           => WP_CLI\Utils\get_flag_value( $assoc_args, 'bundle', true ),
				'include_inactive' => ! WP_CLI\Utils\get_flag_value( $assoc_args, 'active-only', false ),
			)
		);
		if ( is_wp_error( $bp ) ) {
			WP_CLI::error( $bp );
		}
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( $bp['id'] );
			return;
		}
		WP_CLI::success( sprintf( 'Blueprint %s "%s": %d plugins, %d themes, %d settings.', $bp['id'], $bp['name'], count( $bp['plugins'] ), count( $bp['themes'] ), count( $bp['options'] ) ) );
	}

	/**
	 * Reset the site to a blueprint ("Reset to Dev Baseline").
	 *
	 * Takes a safety snapshot, runs a full reset (keeping your admin account),
	 * then installs and activates the blueprint's plugins and theme and
	 * applies its settings.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Blueprint ID.
	 *
	 * [--[no-]reset]
	 * : Reset the site first (default). --no-reset applies on top of the current site.
	 * ---
	 * default: true
	 * ---
	 *
	 * [--[no-]snapshot]
	 * : Take a safety snapshot before resetting (default). --no-snapshot skips it.
	 * ---
	 * default: true
	 * ---
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function apply( array $args, array $assoc_args ): void {
		$locked = \WPChrono\Services\LicenseService::require( 'blueprints' );
		if ( $locked ) {
			WP_CLI::error( $locked->get_error_message() );
		}
		$bp = $this->service->get( $args[0] );
		if ( is_wp_error( $bp ) ) {
			WP_CLI::error( $bp );
		}
		$reset = WP_CLI\Utils\get_flag_value( $assoc_args, 'reset', true );
		WP_CLI::confirm( $reset ? "Reset this site and rebuild it from blueprint \"{$bp['name']}\"?" : "Apply blueprint \"{$bp['name']}\" to this site?", $assoc_args );

		if ( $reset ) {
			if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'snapshot', true ) ) {
				$snap = ( new SnapshotService() )->create_and_build( 'Before blueprint ' . $bp['name'], 'safety' );
				if ( is_wp_error( $snap ) ) {
					WP_CLI::error( 'Safety snapshot failed, nothing was changed: ' . $snap->get_error_message() );
				}
				WP_CLI::log( sprintf( 'Safety snapshot #%d created.', $snap['id'] ) );
			}
			$result = ( new ResetService() )->full_reset( array( 'keep_theme' => false, 'keep_plugins' => false ) );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result );
			}
			WP_CLI::log( sprintf( 'Site reset: %d tables dropped in %d ms.', $result['tables_dropped'], $result['duration_ms'] ) );
		}

		$step     = 0;
		$failures = 0;
		do {
			$tick = $this->service->apply_step( $bp['id'], $step );
			if ( is_wp_error( $tick ) ) {
				WP_CLI::error( $tick );
			}
			foreach ( $tick['log'] as $line ) {
				$tick['ok'] ? WP_CLI::log( "  {$line}" ) : WP_CLI::warning( $line );
			}
			$failures += $tick['ok'] ? 0 : 1;
			$step      = (int) $tick['next'];
		} while ( ! $tick['done'] );

		$failures
			? WP_CLI::warning( sprintf( 'Blueprint applied with %d failed step(s).', $failures ) )
			: WP_CLI::success( sprintf( 'Site rebuilt from blueprint "%s" (%d steps).', $bp['name'], $tick['total'] ) );
	}

	/**
	 * Export a blueprint as portable JSON.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Blueprint ID.
	 *
	 * [<file>]
	 * : Write to this file instead of stdout.
	 *
	 * [--embed]
	 * : Embed the stored plugin / theme zips (base64) so the file is self-contained.
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function export( array $args, array $assoc_args ): void {
		$doc = $this->service->export( $args[0], (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'embed', false ) );
		if ( is_wp_error( $doc ) ) {
			WP_CLI::error( $doc );
		}
		$json = (string) wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( empty( $args[1] ) ) {
			WP_CLI::line( $json );
			return;
		}
		if ( false === file_put_contents( $args[1], $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			WP_CLI::error( "Could not write {$args[1]}." );
		}
		WP_CLI::success( sprintf( 'Exported to %s (%s).', $args[1], size_format( strlen( $json ), 1 ) ) );
	}

	/**
	 * Import a blueprint JSON file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a .json blueprint ("-" for stdin).
	 *
	 * [--porcelain]
	 * : Output just the new blueprint ID.
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function import( array $args, array $assoc_args ): void {
		$locked = \WPChrono\Services\LicenseService::require( 'blueprints' );
		if ( $locked ) {
			WP_CLI::error( $locked->get_error_message() );
		}
		$json = '-' === $args[0] ? stream_get_contents( STDIN ) : @file_get_contents( $args[0] ); // phpcs:ignore
		if ( false === $json ) {
			WP_CLI::error( "Could not read {$args[0]}." );
		}
		$bp = $this->service->import( (string) $json );
		if ( is_wp_error( $bp ) ) {
			WP_CLI::error( $bp );
		}
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( $bp['id'] );
			return;
		}
		WP_CLI::success( sprintf( 'Imported blueprint %s "%s".', $bp['id'], $bp['name'] ) );
	}
}
