<?php
/**
 * GET /wp-chrono/v1/status — dashboard metrics.
 *
 * @package WPChrono
 */

namespace WPChrono\API;

use WP_REST_Server;
use WPChrono\Services\SiteInspector;

defined( 'ABSPATH' ) || exit;

final class SystemController extends RestController {

	public function register_routes(): void {
		$this->route(
			'/status',
			array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'status' ),
				),
			)
		);
	}

	public function status(): \WP_REST_Response {
		return $this->ok( ( new SiteInspector() )->status() );
	}
}
