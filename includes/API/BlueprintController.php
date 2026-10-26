<?php
/**
 * Blueprint endpoints.
 *
 *   GET    /blueprints                       list
 *   POST   /blueprints                       capture the current site
 *   POST   /blueprints/import                import a blueprint JSON document
 *   GET    /blueprints/{id}/export           portable JSON (embed=1 includes zips)
 *   DELETE /blueprints/{id}
 *   POST   /blueprints/{id}/packages         upload a plugin / theme .zip (multipart "package")
 *   DELETE /blueprints/{id}/items            remove a plugin / theme  {type, slug}
 *   POST   /blueprints/{id}/apply            one step of "Reset to Dev Baseline"  (confirm: RESET)
 *
 * The full reset that precedes applying runs through POST /reset/full.
 *
 * @package WPChrono
 */

namespace WPChrono\API;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WPChrono\Services\BlueprintService;
use WPChrono\Services\ResetService;

defined( 'ABSPATH' ) || exit;

final class BlueprintController extends RestController {

	/** @var BlueprintService */
	private $service;

	public function __construct() {
		$this->service = new BlueprintService();
	}

	public function register_routes(): void {
		$id = array( 'id' => array( 'type' => 'string', 'pattern' => '^[a-z0-9]{8}$' ) );

		$this->route(
			'/blueprints',
			array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'index' ),
				),
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'capture' ),
					'args'     => array(
						'name'             => array( 'type' => 'string', 'required' => true, 'maxLength' => 120 ),
						'description'      => array( 'type' => 'string', 'default' => '', 'maxLength' => 2000 ),
						'bundle'           => array( 'type' => 'boolean', 'default' => true ),
						'include_inactive' => array( 'type' => 'boolean', 'default' => true ),
					),
				),
			)
		);
		$this->route(
			'/blueprints/import',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'import' ),
					'args'     => array( 'blueprint' => array( 'required' => true ) ),
				),
			)
		);
		$this->route(
			'/blueprints/(?P<id>[a-z0-9]{8})',
			array(
				array(
					'methods'  => WP_REST_Server::DELETABLE,
					'callback' => array( $this, 'delete' ),
					'args'     => $id,
				),
			)
		);
		$this->route(
			'/blueprints/(?P<id>[a-z0-9]{8})/export',
			array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'export' ),
					'args'     => $id + array( 'embed' => array( 'type' => 'boolean', 'default' => false ) ),
				),
			)
		);
		$this->route(
			'/blueprints/(?P<id>[a-z0-9]{8})/packages',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'upload' ),
					'args'     => $id,
				),
			)
		);
		$this->route(
			'/blueprints/(?P<id>[a-z0-9]{8})/items',
			array(
				array(
					'methods'  => WP_REST_Server::DELETABLE,
					'callback' => array( $this, 'remove_item' ),
					'args'     => $id + array(
						'type' => array( 'type' => 'string', 'enum' => array( 'plugin', 'theme' ), 'required' => true ),
						'slug' => array( 'type' => 'string', 'required' => true ),
					),
				),
			)
		);
		$this->route(
			'/blueprints/(?P<id>[a-z0-9]{8})/apply',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'apply' ),
					'args'     => $id + array(
						'step'    => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0 ),
						'confirm' => array( 'type' => 'string', 'default' => '' ),
					),
				),
			)
		);
	}

	public function index(): \WP_REST_Response {
		$items = array();
		foreach ( $this->service->all() as $bp ) {
			$items[] = BlueprintService::summary( $bp ) + array( 'steps' => BlueprintService::steps( $bp ) );
		}
		return $this->ok( array( 'blueprints' => $items, 'baseline_options' => BlueprintService::BASELINE_OPTIONS ) );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function capture( WP_REST_Request $request ) {
		$locked = $this->require_pro( 'blueprints' );
		if ( $locked ) {
			return $locked;
		}
		$bp = $this->service->capture(
			(string) $request['name'],
			array(
				'description'      => (string) $request['description'],
				'bundle'           => (bool) $request['bundle'],
				'include_inactive' => (bool) $request['include_inactive'],
			)
		);
		return is_wp_error( $bp ) ? $bp : $this->ok( BlueprintService::summary( $bp ), 201 );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function import( WP_REST_Request $request ) {
		$locked = $this->require_pro( 'blueprints' );
		if ( $locked ) {
			return $locked;
		}
		$doc = $request['blueprint'];
		$bp  = $this->service->import( is_array( $doc ) || is_string( $doc ) ? $doc : '' );
		return is_wp_error( $bp ) ? $bp : $this->ok( BlueprintService::summary( $bp ), 201 );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function export( WP_REST_Request $request ) {
		$doc = $this->service->export( (string) $request['id'], (bool) $request['embed'] );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$response = $this->ok( $doc );
		$response->header( 'Content-Disposition', 'attachment; filename="' . sanitize_file_name( $doc['name'] ) . '.wpchrono-blueprint.json"' );
		return $response;
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		$result = $this->service->delete( (string) $request['id'] );
		return is_wp_error( $result ) ? $result : $this->ok( array( 'deleted' => (string) $request['id'] ) );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function upload( WP_REST_Request $request ) {
		$locked = $this->require_pro( 'blueprints' );
		if ( $locked ) {
			return $locked;
		}
		$files = $request->get_file_params();
		$file  = $files['package'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'wp_chrono_upload', 'Upload a .zip file in the "package" field.', array( 'status' => 400 ) );
		}
		if ( '.zip' !== strtolower( substr( (string) $file['name'], -4 ) ) ) {
			return new WP_Error( 'wp_chrono_upload', 'Only .zip packages are accepted.', array( 'status' => 400 ) );
		}
		$bp = $this->service->add_package( (string) $request['id'], (string) $file['tmp_name'] );
		return is_wp_error( $bp ) ? $bp : $this->ok( BlueprintService::summary( $bp ) );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function remove_item( WP_REST_Request $request ) {
		$bp = $this->service->remove_item( (string) $request['id'], (string) $request['type'], (string) $request['slug'] );
		return is_wp_error( $bp ) ? $bp : $this->ok( BlueprintService::summary( $bp ) );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function apply( WP_REST_Request $request ) {
		$locked = $this->require_pro( 'blueprints' );
		if ( $locked ) {
			return $locked;
		}
		if ( ResetService::CONFIRM_WORD !== $request['confirm'] ) {
			return new WP_Error( 'wp_chrono_unconfirmed', sprintf( 'Applying a blueprint requires "confirm": "%s".', ResetService::CONFIRM_WORD ), array( 'status' => 400 ) );
		}
		$result = $this->service->apply_step( (string) $request['id'], (int) $request['step'] );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}
}
