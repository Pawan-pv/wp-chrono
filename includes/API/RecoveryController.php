<?php
/**
 * Emergency Recovery Script endpoints.
 *
 *   GET    /recovery     status (exists, URL, created)
 *   POST   /recovery     generate / regenerate; returns the password ONCE
 *   DELETE /recovery     remove the script from the server
 *
 * @package WPChrono
 */

namespace WPChrono\API;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WPChrono\Services\RecoveryGenerator;

defined( 'ABSPATH' ) || exit;

final class RecoveryController extends RestController {

	public function register_routes(): void {
		$this->route(
			'/recovery',
			array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'status' ),
				),
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'generate' ),
					'args'     => array(
						'password' => array( 'type' => 'string', 'default' => '' ),
					),
				),
				array(
					'methods'  => WP_REST_Server::DELETABLE,
					'callback' => array( $this, 'delete' ),
				),
			)
		);
	}

	public function status(): \WP_REST_Response {
		return $this->ok( ( new RecoveryGenerator() )->status() );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate( WP_REST_Request $request ) {
		$denied = $this->require_network_admin();
		if ( ! $denied && ! wp_is_file_mod_allowed( 'wp_chrono_recovery' ) ) {
			$denied = new WP_Error( 'wp_chrono_forbidden', 'File modifications are disabled on this site (DISALLOW_FILE_MODS), so the recovery script cannot be written. Generate it with WP-CLI instead: wp chrono recovery generate', array( 'status' => 403 ) );
		}
		if ( $denied ) {
			return $denied;
		}
		$result = ( new RecoveryGenerator() )->generate( (string) $request['password'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = $this->ok( $result, 201 );
		$response->header( 'Cache-Control', 'no-store' ); // Carries the one-time password.
		return $response;
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function delete() {
		$denied = $this->require_network_admin();
		if ( $denied ) {
			return $denied;
		}
		$generator = new RecoveryGenerator();
		$generator->delete();
		return $this->ok( $generator->status() );
	}

	/**
	 * The script sits in the WordPress root and can restore any site of the
	 * network, so on multisite only super admins may write or remove it.
	 */
	private function require_network_admin(): ?WP_Error {
		if ( is_multisite() && ! is_super_admin() ) {
			return new WP_Error( 'wp_chrono_forbidden', 'On multisite only super admins can manage the Emergency Recovery Script.', array( 'status' => 403 ) );
		}
		return null;
	}
}
