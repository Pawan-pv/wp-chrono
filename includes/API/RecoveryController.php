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
		$result = ( new RecoveryGenerator() )->generate( (string) $request['password'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = $this->ok( $result, 201 );
		$response->header( 'Cache-Control', 'no-store' ); // Carries the one-time password.
		return $response;
	}

	public function delete(): \WP_REST_Response {
		$generator = new RecoveryGenerator();
		$generator->delete();
		return $this->ok( $generator->status() );
	}
}
