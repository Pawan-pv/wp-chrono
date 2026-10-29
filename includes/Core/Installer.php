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
		if ( ! self::install_schema() ) {
			wp_die( esc_html__( 'WP Chrono could not create its database tables. Check the site database credentials and try activation again.', 'wp-chrono' ) );
		}
		$storage = Storage::ensure();
		if ( is_wp_error( $storage ) ) {
			wp_die( esc_html( $storage->get_error_message() ) );
		}
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

	private static function install_schema(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Schema::ddl() );
		foreach ( Schema::internal_tables() as $table ) {
			$like = $wpdb->esc_like( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) ) {
				return false;
			}
		}
		// autoload=false keeps our options out of the front-end alloptions payload.
		update_option( self::OPTION_DB_VERSION, Schema::VERSION, false );
		return true;
	}
}
