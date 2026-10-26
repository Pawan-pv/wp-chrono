<?php
/**
 * Activation and schema upgrades.
 *
 * @package WPChrono
 */

namespace WPChrono\Core;

defined( 'ABSPATH' ) || exit;

final class Installer {

	const OPTION_DB_VERSION = 'wp_chrono_db_version';

	public static function activate(): void {
		self::install_schema();
		Storage::ensure();
		// First activation only: show the welcome / safety checklist once.
		if ( false === get_option( \WPChrono\API\OnboardingController::OPTION ) ) {
			add_option( \WPChrono\API\OnboardingController::OPTION, array( 'welcome_seen' => false ), '', false );
		}
	}

	/**
	 * Runs on admin_init only, so the option read never happens on the front end.
	 */
	public static function maybe_upgrade(): void {
		if ( Schema::VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			self::install_schema();
		}
	}

	private static function install_schema(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Schema::ddl() );
		// autoload=false keeps our options out of the front-end alloptions payload.
		update_option( self::OPTION_DB_VERSION, Schema::VERSION, false );
	}
}
