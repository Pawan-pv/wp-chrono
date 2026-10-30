<?php
/**
 * Snapshot endpoints.
 *
 *   GET    /snapshots                       list + settings + orphan summary + running restore
 *   POST   /snapshots                       register a snapshot (then build it in ticks)
 *   POST   /snapshots/{id}/build            one copy tick
 *   DELETE /snapshots/{id}
 *   POST   /snapshots/{id}/verify           re-run the integrity check (row + column counts)
 *   GET    /snapshots/{id}/diff             table + wp_options diff against live
 *   POST   /snapshots/{id}/restore          start a restore job        (confirm: RESTORE, mode: db|files|full)
 *   POST   /snapshots/restore/tick          stage / swap               (confirm: RESTORE)
 *   POST   /snapshots/restore/cancel
 *   POST   /snapshots/{id}/export           one export tick
 *   POST   /snapshots/import/chunk          upload a WP Chrono .sql.gz in chunks (multipart "chunk")
 *   POST   /snapshots/import/tick           import the uploaded dump as a new snapshot
 *   POST   /snapshots/import/cancel
 *   POST   /snapshots/prune-auto            delete all but the newest {keep} auto-snapshots
 *   GET    /snapshots/orphans               scavenger preview
 *   POST   /snapshots/orphans/purge         drop orphans               (confirm: PURGE)
 *   GET    /settings, POST /settings
 *
 * @package WPChrono
 */

namespace WPChrono\API;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WPChrono\Services\CloudStorageService;
use WPChrono\Services\DiffService;
use WPChrono\Services\Scheduler;
use WPChrono\Services\Settings;
use WPChrono\Services\SiteInspector;
use WPChrono\Services\Snapshot\LocalImport;
use WPChrono\Services\Snapshot\Restorer;
use WPChrono\Services\SnapshotService;

defined( 'ABSPATH' ) || exit;

final class SnapshotController extends RestController {

	/** @var SnapshotService */
	private $service;

	public function __construct() {
		$this->service = new SnapshotService();
	}

	public function register_routes(): void {
		$id = array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) );

		$this->route(
			'/snapshots',
			array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'index' ),
				),
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'create' ),
					'args'     => array(
						'label' => array( 'type' => 'string', 'default' => '', 'maxLength' => 191 ),
						'note'  => array( 'type' => 'string', 'default' => '', 'maxLength' => 2000 ),
					),
				),
			)
		);
		$this->route(
			'/snapshots/(?P<id>\d+)',
			array(
				array(
					'methods'  => WP_REST_Server::DELETABLE,
					'callback' => array( $this, 'delete' ),
					'args'     => $id,
				),
			)
		);
		$this->route(
			'/snapshots/(?P<id>\d+)/build',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'build' ),
					'args'     => $id,
				),
			)
		);
		$this->route(
			'/snapshots/(?P<id>\d+)/verify',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'verify' ),
					'args'     => $id,
				),
			)
		);
		$this->route(
			'/snapshots/(?P<id>\d+)/diff',
			array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'diff' ),
					'args'     => $id,
				),
			)
		);
		$this->route(
			'/snapshots/(?P<id>\d+)/restore',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'restore' ),
					'args'     => $id + array(
						'confirm'    => array( 'type' => 'string', 'default' => '' ),
						'drop_extra' => array( 'type' => 'boolean', 'default' => false ),
						'mode'       => array( 'type' => 'string', 'enum' => array( 'db', 'files', 'full' ), 'default' => 'db' ),
						'include_network_tables' => array( 'type' => 'boolean', 'default' => false ),
					),
				),
			)
		);
		$this->route(
			'/snapshots/restore/tick',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'restore_tick' ),
					'args'     => array(
						'job'     => array( 'type' => 'string', 'required' => true, 'pattern' => '^[a-z0-9]{6}$' ),
						'confirm' => array( 'type' => 'string', 'default' => '' ),
					),
				),
			)
		);
		$this->route(
			'/snapshots/restore/cancel',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'restore_cancel' ),
				),
			)
		);
		$this->route(
			'/snapshots/(?P<id>\d+)/export',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'export' ),
					'args'     => $id + array( 'job' => array( 'type' => 'string', 'default' => '' ) ),
				),
			)
		);
		$this->route(
			'/snapshots/import/chunk',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'import_chunk' ),
					'args'     => array(
						'upload' => array( 'type' => 'string', 'default' => '' ),
						'offset' => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0 ),
						'final'  => array( 'type' => 'boolean', 'default' => false ),
						'name'   => array( 'type' => 'string', 'default' => '' ),
					),
				),
			)
		);
		$this->route(
			'/snapshots/import/tick',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'import_tick' ),
					'args'     => array( 'job' => array( 'type' => 'string', 'required' => true ) ),
				),
			)
		);
		$this->route(
			'/snapshots/import/cancel',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'import_cancel' ),
				),
			)
		);
		$this->route(
			'/snapshots/prune-auto',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'prune_auto' ),
					'args'     => array( 'keep' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => 50 ) ),
				),
			)
		);
		$this->route(
			'/snapshots/orphans',
			array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'orphans' ),
				),
			)
		);
		$this->route(
			'/snapshots/orphans/purge',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'purge' ),
					'args'     => array(
						'confirm' => array( 'type' => 'string', 'default' => '' ),
						'tables'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
				),
			)
		);
		$this->route(
			'/settings',
			array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'settings' ),
				),
				array(
					'methods'  => WP_REST_Server::EDITABLE,
					'callback' => array( $this, 'update_settings' ),
					'args'     => array(
						'auto_snapshot'   => array( 'type' => 'boolean' ),
						'safe_updates'    => array( 'type' => 'boolean' ),
						'auto_retention'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
						'storage_warn_mb' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1048576 ),
						'rollback_max_mb' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10240 ),
						'schedule'        => array( 'type' => 'string', 'enum' => Settings::SCHEDULES ),
						'schedule_hour'   => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 23 ),
						'schedule_keep'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
						'keep_data_on_uninstall' => array( 'type' => 'boolean' ),
					),
				),
			)
		);
	}

	public function index(): \WP_REST_Response {
		$orphans = $this->service->scavenger()->scan();
		$job     = Restorer::active_job();
		return $this->ok(
			array(
				'snapshots' => $this->service->all(),
				'settings'  => Settings::get(),
				'orphans'   => array( 'count' => count( $orphans['tables'] ), 'bytes' => $orphans['bytes'] ),
				'restore'   => $job ? array( 'job' => $job['id'], 'snapshot_id' => $job['snapshot_id'] ) : null,
				'storage'   => ( new SiteInspector() )->storage_usage(),
				'cloud'     => array( 'configured' => CloudStorageService::configured() ),
				'import'    => array( 'max_chunk' => LocalImport::max_chunk() ),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function import_chunk( WP_REST_Request $request ) {
		$file = $request->get_file_params()['chunk'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'wp_chrono_upload', 'Send the next part of the file in the "chunk" field.', array( 'status' => 400 ) );
		}
		$name = (string) $request['name'];
		if ( '' !== $name && ! preg_match( '/\.sql\.gz$/i', $name ) ) {
			return new WP_Error( 'wp_chrono_upload', 'Only WP Chrono .sql.gz exports can be imported.', array( 'status' => 400 ) );
		}
		return $this->respond( ( new LocalImport( $this->service ) )->chunk( (string) $request['upload'], (int) $request['offset'], (string) $file['tmp_name'], (bool) $request['final'], $name ) );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function import_tick( WP_REST_Request $request ) {
		return $this->respond( ( new LocalImport( $this->service ) )->tick( (string) $request['job'] ) );
	}

	public function import_cancel(): \WP_REST_Response {
		( new LocalImport( $this->service ) )->cancel();
		return $this->ok( array( 'cancelled' => true ) );
	}

	public function prune_auto( WP_REST_Request $request ): \WP_REST_Response {
		$deleted = $this->service->prune_auto( (int) $request['keep'] );
		return $this->ok( array( 'deleted' => $deleted, 'storage' => ( new SiteInspector() )->storage_usage() ) );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$snapshot = $this->service->create( (string) $request['label'], 'manual', (string) $request['note'] );
		return is_wp_error( $snapshot ) ? $snapshot : $this->ok( $this->service->progress( (int) $snapshot['id'] ), 201 );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function build( WP_REST_Request $request ) {
		return $this->respond( $this->service->build( (int) $request['id'] ) );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		$result = $this->service->delete( (int) $request['id'] );
		return is_wp_error( $result ) ? $result : $this->ok( array( 'deleted' => (int) $request['id'] ) );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function verify( WP_REST_Request $request ) {
		$snapshot = $this->service->get( (int) $request['id'] );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( 'ready' !== $snapshot['status'] ) {
			return new WP_Error( 'wp_chrono_snapshot_state', 'Only ready snapshots can be verified.', array( 'status' => 409 ) );
		}
		return $this->ok( $this->service->verify( (int) $request['id'] ) );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function diff( WP_REST_Request $request ) {
		return $this->respond( ( new DiffService( $this->service ) )->compare( (int) $request['id'] ) );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function restore( WP_REST_Request $request ) {
		$error = $this->confirmed( $request, 'RESTORE' );
		if ( ! $error && 'db' !== $request['mode'] ) {
			// Plugin, theme and core folders are shared by the whole network.
			$error = $this->require_caps(
				array( 'update_plugins', 'update_themes', 'update_core' ),
				'Restoring files replaces plugin, theme and WordPress folders, which your account cannot do on this site. Restore the database only, or ask a super admin.'
			);
		}
		// Network tables (users, blogs…) belong to the whole network: super admins only.
		$network = (bool) $request['include_network_tables'] && is_super_admin();
		return $error ?? $this->respond( $this->service->restore( (int) $request['id'], (bool) $request['drop_extra'], (string) $request['mode'], $network ) );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function restore_tick( WP_REST_Request $request ) {
		$error = $this->confirmed( $request, 'RESTORE' );
		return $error ?? $this->respond( $this->service->restore_tick( (string) $request['job'] ) );
	}

	public function restore_cancel(): \WP_REST_Response {
		( new Restorer( $this->service ) )->cancel();
		return $this->ok( array( 'cancelled' => true ) );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function export( WP_REST_Request $request ) {
		return $this->respond( $this->service->export_tick( (int) $request['id'], (string) $request['job'] ) );
	}

	public function orphans(): \WP_REST_Response {
		return $this->ok( $this->service->scavenger()->scan() );
	}

	/**
	 * @return \WP_REST_Response|WP_Error
	 */
	public function purge( WP_REST_Request $request ) {
		$error = $this->confirmed( $request, 'PURGE' );
		if ( $error ) {
			return $error;
		}
		$only = null === $request['tables'] ? null : array_map( 'strval', (array) $request['tables'] );
		return $this->ok( array( 'dropped' => $this->service->scavenger()->purge( $only ) ) );
	}

	public function settings(): \WP_REST_Response {
		return $this->ok( Settings::get() + array( 'schedule_next' => Scheduler::next() ) );
	}

	public function update_settings( WP_REST_Request $request ): \WP_REST_Response {
		$changes = array();
		foreach ( array_keys( Settings::DEFAULTS ) as $key ) {
			if ( null !== $request[ $key ] ) {
				$changes[ $key ] = $request[ $key ];
			}
		}
		$settings = Settings::update( $changes );
		if ( isset( $changes['auto_retention'] ) ) {
			$this->service->prune_auto();
		}
		if ( isset( $changes['schedule_keep'] ) ) {
			$this->service->prune_trigger( 'scheduled', $settings['schedule_keep'] );
		}
		return $this->ok( $settings + array( 'schedule_next' => Scheduler::next() ) );
	}

	private function confirmed( WP_REST_Request $request, string $word ): ?WP_Error {
		if ( $word === $request['confirm'] ) {
			return null;
		}
		return new WP_Error( 'wp_chrono_unconfirmed', sprintf( 'This action requires "confirm": "%s".', $word ), array( 'status' => 400 ) );
	}

	/**
	 * @param array<string,mixed>|WP_Error $result
	 * @return \WP_REST_Response|WP_Error
	 */
	private function respond( $result ) {
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}
}
