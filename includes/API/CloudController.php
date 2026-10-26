<?php
/**
 * Cloud storage endpoints (S3-compatible: R2, S3, B2).
 *
 *   GET  /cloud                 settings (never includes the secret key)
 *   POST /cloud                 save settings; empty secret_key keeps the stored one
 *   POST /cloud/test            list + write + read + delete a probe object
 *   GET  /cloud/remote          this site's complete backups in the bucket
 *   POST /cloud/push/{id}       one push tick  {job, step}
 *   POST /cloud/pull            one pull tick  {key, job}
 *
 * @package WPChrono
 */

namespace WPChrono\API;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WPChrono\Services\CloudStorageService;
use WPChrono\Services\Snapshot\CloudSync;
use WPChrono\Services\SnapshotService;

defined( 'ABSPATH' ) || exit;

final class CloudController extends RestController {

	public function register_routes(): void {
		$this->route(
			'/cloud',
			array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'settings' ),
				),
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'save' ),
					'args'     => array(
						'provider'   => array( 'type' => 'string', 'enum' => array_keys( CloudStorageService::PROVIDERS ) ),
						'endpoint'   => array( 'type' => 'string' ),
						'region'     => array( 'type' => 'string' ),
						'bucket'     => array( 'type' => 'string' ),
						'access_key' => array( 'type' => 'string' ),
						'secret_key' => array( 'type' => 'string', 'default' => '' ),
						'path_style' => array( 'type' => 'boolean' ),
						'prefix'     => array( 'type' => 'string' ),
					),
				),
			)
		);
		$this->route(
			'/cloud/test',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'test' ),
				),
			)
		);
		$this->route(
			'/cloud/remote',
			array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'remote' ),
				),
			)
		);
		$this->route(
			'/cloud/push/(?P<id>\d+)',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'push' ),
					'args'     => array(
						'id'   => array( 'type' => 'integer', 'minimum' => 1 ),
						'job'  => array( 'type' => 'string', 'default' => '' ),
						'step' => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0 ),
					),
				),
			)
		);
		$this->route(
			'/cloud/pull',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'pull' ),
					'args'     => array(
						'key' => array( 'type' => 'string', 'default' => '' ),
						'job' => array( 'type' => 'string', 'default' => '' ),
					),
				),
			)
		);
	}

	public function settings(): \WP_REST_Response {
		return $this->ok( CloudStorageService::settings() );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$locked = $this->require_pro( 'cloud' );
		if ( $locked ) {
			return $locked;
		}
		$input = array();
		foreach ( array( 'provider', 'endpoint', 'region', 'bucket', 'access_key', 'secret_key', 'path_style', 'prefix' ) as $key ) {
			if ( null !== $request[ $key ] ) {
				$input[ $key ] = $request[ $key ];
			}
		}
		$saved = CloudStorageService::save( $input );
		return is_wp_error( $saved ) ? $saved : $this->ok( $saved );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function test() {
		$locked = $this->require_pro( 'cloud' );
		if ( $locked ) {
			return $locked;
		}
		$result = ( new CloudStorageService() )->test();
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function remote() {
		$list = ( new CloudSync( new SnapshotService() ) )->remote();
		return is_wp_error( $list ) ? $list : $this->ok( array( 'backups' => $list ) );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function push( WP_REST_Request $request ) {
		$locked = $this->require_pro( 'cloud' );
		if ( $locked ) {
			return $locked;
		}
		$result = ( new CloudSync( new SnapshotService() ) )->push( (int) $request['id'], (string) $request['job'], (int) $request['step'] );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function pull( WP_REST_Request $request ) {
		$result = ( new CloudSync( new SnapshotService() ) )->pull( (string) $request['key'], (string) $request['job'] );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}
}
