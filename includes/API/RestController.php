<?php
/**
 * Base REST controller.
 *
 * Every route registered through `route()` is forced through `authorize()`:
 * the caller must hold `manage_options` AND send a valid WP Chrono nonce in the
 * `X-WP-Chrono-Nonce` header. Subclasses cannot supply their own
 * permission_callback, so a route can never be registered unguarded.
 *
 * (Core's cookie auth separately validates `X-WP-Nonce`; without it the
 * request runs as user 0 and fails the capability check anyway.)
 *
 * @package WPChrono
 */

namespace WPChrono\API;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

abstract class RestController {

	const NAMESPACE    = 'wp-chrono/v1';
	const NONCE_ACTION = 'wp_chrono_api';
	const NONCE_HEADER = 'X-WP-Chrono-Nonce';

	abstract public function register_routes(): void;

	/**
	 * @param string $path      Route path relative to the namespace, e.g. '/status'.
	 * @param array  $endpoints List of endpoint definitions (methods, callback, args).
	 */
	protected function route( string $path, array $endpoints ): void {
		foreach ( $endpoints as &$endpoint ) {
			$endpoint['permission_callback'] = array( $this, 'authorize' );
		}
		unset( $endpoint );

		register_rest_route( self::NAMESPACE, $path, $endpoints );
	}

	/**
	 * @return true|WP_Error
	 */
	public function authorize( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'wp_chrono_forbidden',
				__( 'You are not allowed to use WP Chrono.', 'wp-chrono' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$nonce = $request->get_header( self::NONCE_HEADER );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return new WP_Error(
				'wp_chrono_invalid_nonce',
				__( 'Your session has expired. Reload the page and try again.', 'wp-chrono' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * HTTP 402 for a Pro-only action without a license, else null. Restore,
	 * import and recovery endpoints never call this: restoring is always free.
	 */
	protected function require_pro( string $feature ): ?WP_Error {
		return \WPChrono\Services\LicenseService::require( $feature );
	}

	/**
	 * @param mixed $data
	 */
	protected function ok( $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $data, $status );
	}
}
