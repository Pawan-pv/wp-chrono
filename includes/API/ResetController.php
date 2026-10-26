<?php
/**
 * Reset endpoints.
 *
 *   GET  /reset/preview                 dry-run summary of every target + full-reset scope
 *   GET  /reset/preview/{target}        dry-run summary of one target
 *   POST /reset/preflight               pre-reset state check + safety snapshot hook
 *   POST /reset/partial/{target}        one resumable tick of a partial reset
 *   POST /reset/full                    full site reset
 *
 * Destructive calls must also send `confirm: "RESET"`, so a stray request
 * can't wipe anything even with a valid nonce.
 *
 * @package WPChrono
 */

namespace WPChrono\API;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WPChrono\Services\Database;
use WPChrono\Services\ResetService;

defined( 'ABSPATH' ) || exit;

final class ResetController extends RestController {

	/** @var ResetService */
	private $service;

	public function __construct() {
		$this->service = new ResetService();
	}

	public function register_routes(): void {
		$target = array(
			'type' => 'string',
			'enum' => array_keys( ResetService::targets() ),
		);

		$this->route(
			'/reset/preview',
			array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'preview_all' ),
				),
			)
		);

		$this->route(
			'/reset/preview/(?P<target>[a-z_]+)',
			array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'preview' ),
					'args'     => array( 'target' => $target ),
				),
			)
		);

		$this->route(
			'/reset/preflight',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'preflight' ),
					'args'     => array(
						'target'   => array( 'type' => 'string', 'required' => true, 'enum' => array_merge( array( 'full' ), array_keys( ResetService::targets() ) ) ),
						'snapshot' => array( 'type' => 'boolean', 'default' => true ),
					),
				),
			)
		);

		$this->route(
			'/reset/partial/(?P<target>[a-z_]+)',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'partial' ),
					'args'     => array(
						'target'  => $target,
						'dry_run' => array( 'type' => 'boolean', 'default' => false ),
						'confirm' => array( 'type' => 'string', 'default' => '' ),
						'cursor'  => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0 ),
						'mode'    => array( 'type' => 'string', 'enum' => array( 'truncate', 'drop' ), 'default' => 'truncate' ),
					),
				),
			)
		);

		$this->route(
			'/reset/full',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'full' ),
					'args'     => array(
						'confirm'      => array( 'type' => 'string', 'required' => true ),
						'keep_theme'   => array( 'type' => 'boolean', 'default' => true ),
						'keep_plugins' => array( 'type' => 'boolean', 'default' => false ),
						'keep_users'   => array( 'type' => 'boolean', 'default' => false ),
					),
				),
			)
		);
	}

	public function preview_all(): \WP_REST_Response {
		$targets = array();
		foreach ( array_keys( ResetService::targets() ) as $target ) {
			$targets[ $target ] = $this->service->preview( $target );
		}

		$tables = Database::site_tables();

		return $this->ok(
			array(
				'targets' => $targets,
				'full'    => array(
					'available' => ! is_multisite(),
					'tables'    => count( $tables ),
					'bytes'     => array_sum( array_column( $tables, 'bytes' ) ),
				),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function preview( WP_REST_Request $request ) {
		return $this->respond( $this->service->preview( $request['target'] ) );
	}

	public function preflight( WP_REST_Request $request ): \WP_REST_Response {
		return $this->ok( $this->service->preflight( $request['target'], (bool) $request['snapshot'] ) );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function partial( WP_REST_Request $request ) {
		$dry_run = (bool) $request['dry_run'];
		$error = $dry_run ? null : $this->require_confirmation( $request );
		if ( $error ) {
			return $error;
		}

		return $this->respond(
			$this->service->partial_reset(
				$request['target'],
				$dry_run,
				array(
					'cursor' => (int) $request['cursor'],
					'mode'   => $request['mode'],
				)
			)
		);
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function full( WP_REST_Request $request ) {
		$error = $this->require_confirmation( $request );
		if ( $error ) {
			return $error;
		}

		return $this->respond(
			$this->service->full_reset(
				array(
					'keep_theme'   => (bool) $request['keep_theme'],
					'keep_plugins' => (bool) $request['keep_plugins'],
					'keep_users'   => (bool) $request['keep_users'],
				)
			)
		);
	}

	private function require_confirmation( WP_REST_Request $request ): ?WP_Error {
		if ( ResetService::CONFIRM_WORD === $request['confirm'] ) {
			return null;
		}
		return new WP_Error(
			'wp_chrono_unconfirmed',
			sprintf( 'Destructive actions require "confirm": "%s".', ResetService::CONFIRM_WORD ),
			array( 'status' => 400 )
		);
	}

	/**
	 * @param array<string,mixed>|WP_Error $result
	 * @return \WP_REST_Response|WP_Error
	 */
	private function respond( $result ) {
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}
}
