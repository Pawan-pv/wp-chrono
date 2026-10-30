<?php
/**
 * Plugin bootstrap.
 *
 * Every hook registered here is scoped to a non-public context (wp-admin, the
 * REST API, or WP-CLI). Nothing runs, enqueues, or queries on front-end page
 * views — this is the "zero frontend footprint" guarantee.
 *
 * @package WPChrono
 */

namespace WPChrono\Core;

use WPChrono\API\Router;
use WPChrono\CLI\Commands;
use WPChrono\Services\SafeUpdates;
use WPChrono\Services\Scheduler;
use WPChrono\Services\SnapshotService;
use WPChrono\Services\Updater;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var bool */
	private static $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		$cli = defined( 'WP_CLI' ) && WP_CLI;

		// Translations: only where WP Chrono runs (admin, REST, CLI), never on public pages.
		if ( is_admin() || $cli ) {
			add_action( 'init', array( self::class, 'load_textdomain' ) );
		}
		add_action( 'rest_api_init', array( self::class, 'load_textdomain' ), 0 );

		if ( is_admin() ) {
			( new Admin() )->register();
			add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ) );
		}

		// `rest_api_init` only fires while serving a REST request.
		add_action( 'rest_api_init', array( Installer::class, 'maybe_upgrade' ), 1 );
		add_action( 'rest_api_init', array( Router::class, 'register' ) );

		// A Safe Updates probe loading a public page (one-time token in the URL).
		if ( isset( $_GET[ SafeUpdates::PROBE_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token verified in probe_mode().
			SafeUpdates::probe_mode();
		}

		// Our own filter, fired only by ResetService::preflight().
		add_filter( 'wp_chrono_safety_snapshot', array( SnapshotService::class, 'safety_snapshot' ), 10, 2 );

		// Snapshots + Safe Updates on every update, whatever starts it: wp-admin,
		// WP-Cron auto-updates, WP-CLI, and remote-management dashboards
		// (ManageWP, MainWP, InfiniteWP…) whose worker plugins run the upgrader
		// inside a front-end request. These hooks only fire during an update.
		add_filter( 'upgrader_pre_install', array( SnapshotService::class, 'before_update' ), 10, 2 );
		add_filter( 'upgrader_post_install', array( SnapshotService::class, 'after_update' ), 10, 3 );
		// WordPress core: Core_Upgrader skips upgrader_pre_install / post_install.
		add_filter( 'upgrader_pre_download', array( SnapshotService::class, 'before_core_update' ), 5, 3 );
		add_action( '_core_updated_successfully', array( SnapshotService::class, 'after_core_update' ), 1 );
		SafeUpdates::register();

		// Update checks and the snapshot schedule: wp-admin, WP-Cron, WP-CLI only.
		if ( is_admin() || wp_doing_cron() || $cli ) {
			Updater::register();
			Scheduler::register();
		}

		if ( $cli ) {
			Installer::maybe_upgrade();
			Commands::register();
		}
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'wp-chrono', false, dirname( WP_CHRONO_BASENAME ) . '/languages' );
	}
}
