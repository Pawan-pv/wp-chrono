<?php
/**
 * Keeps the acting admin signed in across operations that replace the users
 * or options tables (full reset, snapshot restore).
 *
 * @package WPChrono
 */

namespace WPChrono\Services;

use WP_Error;
use WP_User;
use WPChrono\API\RestController;

defined( 'ABSPATH' ) || exit;

final class Session {

	/**
	 * Salts live in wp_options when wp-config.php doesn't define them; losing
	 * them invalidates every auth cookie and nonce.
	 */
	const SALT_OPTIONS = array(
		'auth_key',
		'auth_salt',
		'logged_in_key',
		'logged_in_salt',
		'nonce_key',
		'nonce_salt',
		'secure_auth_key',
		'secure_auth_salt',
	);

	/**
	 * The signed-in admin, or (under WP-CLI without --user) the oldest administrator.
	 *
	 * @return WP_User|WP_Error
	 */
	public static function acting_admin() {
		$user = wp_get_current_user();
		if ( $user->exists() && user_can( $user, 'manage_options' ) ) {
			return $user;
		}
		$admins = get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1 ) );
		if ( ! $admins ) {
			return new WP_Error( 'wp_chrono_no_admin', __( 'No administrator account was found.', 'wp-chrono' ) );
		}
		return $admins[0];
	}

	/**
	 * Current values of options matching names or `LIKE` patterns, skipping ones
	 * that don't exist.
	 *
	 * @param string[] $names
	 * @param string[] $like_prefixes e.g. ['wp_chrono_'].
	 * @return array<string,mixed>
	 */
	public static function capture_options( array $names, array $like_prefixes = array() ): array {
		global $wpdb;
		foreach ( $like_prefixes as $prefix ) {
			$names = array_merge(
				$names,
				$wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ) // phpcs:ignore WordPress.DB
			);
		}

		$missing = new \stdClass();
		$options = array();
		foreach ( array_unique( $names ) as $name ) {
			$value = get_option( $name, $missing );
			if ( $value !== $missing ) {
				$options[ $name ] = $value;
			}
		}
		return $options;
	}

	/**
	 * Writes captured options back; `wp_chrono_*` ones keep autoload off.
	 *
	 * @param array<string,mixed> $options
	 */
	public static function restore_options( array $options ): void {
		foreach ( $options as $name => $value ) {
			update_option( $name, $value, 0 === strpos( $name, 'wp_chrono_' ) ? false : null );
		}
	}

	/**
	 * Re-issues the auth cookie and returns nonces bound to the new session, so
	 * the SPA's next request isn't rejected. Null under WP-CLI.
	 *
	 * @return array{rest:string,chrono:string}|null
	 */
	public static function refresh( int $user_id ): ?array {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return null;
		}

		wp_set_current_user( $user_id );

		// wp_set_auth_cookie() only sends headers; nonces are derived from the
		// session token in $_COOKIE, so mirror the new cookie into it.
		$capture = static function ( $cookie ) {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		};
		add_action( 'set_logged_in_cookie', $capture );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		remove_action( 'set_logged_in_cookie', $capture );

		return array(
			'rest'   => wp_create_nonce( 'wp_rest' ),
			'chrono' => wp_create_nonce( RestController::NONCE_ACTION ),
		);
	}
}
