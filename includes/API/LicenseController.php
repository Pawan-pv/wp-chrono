<?php
/**
 * License endpoints.
 *
 *   GET    /license      status: plan, features, masked key, server state
 *   POST   /license      activate {key, server?}
 *   DELETE /license      deactivate (keys set via the WP_CHRONO_LICENSE_KEY constant stay)
 *
 * @package WPChrono
 */

namespace WPChrono\API;

use WP_REST_Request;
use WP_REST_Server;
use WPChrono\Services\LicenseService;

defined( 'ABSPATH' ) || exit;

final class LicenseController extends RestController {

	public function register_routes(): void {
		$this->route(
			'/license',
			array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'status' ),
				),
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'activate' ),
					'args'     => array(
						'key'    => array( 'type' => 'string', 'required' => true, 'maxLength' => 4096 ),
						'server' => array( 'type' => 'string' ),
					),
				),
				array(
					'methods'  => WP_REST_Server::DELETABLE,
					'callback' => array( $this, 'deactivate' ),
				),
			)
		);
	}

	public function status(): \WP_REST_Response {
		return $this->ok( LicenseService::status() );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function activate( WP_REST_Request $request ) {
		$status = LicenseService::activate( (string) $request['key'], null === $request['server'] ? null : (string) $request['server'] );
		return is_wp_error( $status ) ? $status : $this->ok( $status );
	}

	public function deactivate(): \WP_REST_Response {
		return $this->ok( LicenseService::deactivate() );
	}
}
