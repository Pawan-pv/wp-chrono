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

		// Our own filter, fired only by ResetService::preflight().
		add_filter( 'wp_chrono_safety_snapshot', array( SnapshotService::class, 'safety_snapshot' ), 10, 2 );

		// Updates run from wp-admin, WP-Cron (auto-updates) or WP-CLI — never a public page view.
		if ( is_admin() || wp_doing_cron() || $cli ) {
			add_filter( 'upgrader_pre_install', array( SnapshotService::class, 'before_update' ), 10, 2 );
			add_filter( 'upgrader_post_install', array( SnapshotService::class, 'after_update' ), 10, 3 );
			Updater::register();
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
