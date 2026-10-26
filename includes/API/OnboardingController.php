<?php
/**
 * First-run safety checklist.
 *
 *   GET  /onboarding    steps (derived from real state) + dismissed / welcome flags
 *   POST /onboarding    {dismissed?, welcome_seen?, kit_downloaded?}
 *
 * Step state comes from the site itself (recovery script present, auto
 * snapshots on, cloud configured or a snapshot taken), so the checklist can
 * never claim something is done when it isn't. It only ever renders inside
 * WP Chrono's own screen: no admin notices.
 *
 * @package WPChrono
 */

namespace WPChrono\API;

use WP_REST_Request;
use WP_REST_Server;
use WPChrono\Services\CloudStorageService;
use WPChrono\Services\RecoveryGenerator;
use WPChrono\Services\Settings;
use WPChrono\Services\SnapshotService;

defined( 'ABSPATH' ) || exit;

final class OnboardingController extends RestController {

	const OPTION = 'wp_chrono_onboarding';

	public function register_routes(): void {
		$this->route(
			'/onboarding',
			array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'status' ),
				),
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'update' ),
					'args'     => array(
						'dismissed'      => array( 'type' => 'boolean' ),
						'welcome_seen'   => array( 'type' => 'boolean' ),
						'kit_downloaded' => array( 'type' => 'boolean' ),
					),
				),
			)
		);
	}

	/**
	 * @return array{dismissed:bool,welcome_seen:bool,kit_downloaded:bool}
	 */
	public static function flags(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'dismissed'      => ! empty( $saved['dismissed'] ),
			'welcome_seen'   => ! array_key_exists( 'welcome_seen', $saved ) || ! empty( $saved['welcome_seen'] ),
			'kit_downloaded' => ! empty( $saved['kit_downloaded'] ),
		);
	}

	public function status(): \WP_REST_Response {
		$flags    = self::flags();
		$recovery = ( new RecoveryGenerator() )->status();
		$manual   = array_filter(
			( new SnapshotService() )->all(),
			static function ( $s ) {
				return 'ready' === $s['status'] && in_array( $s['trigger_type'], array( 'manual', 'import', 'cloud' ), true );
			}
		);
		$steps = array(
			'recovery'    => $recovery['exists'] && $flags['kit_downloaded'],
			'auto'        => Settings::get()['auto_snapshot'],
			'safety_copy' => CloudStorageService::configured() || (bool) $manual,
		);
		return $this->ok(
			$flags + array(
				'steps'            => $steps,
				'complete'         => ! in_array( false, $steps, true ),
				'recovery_exists'  => $recovery['exists'],
				'cloud_configured' => CloudStorageService::configured(),
			)
		);
	}

	public function update( WP_REST_Request $request ): \WP_REST_Response {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		foreach ( array( 'dismissed', 'welcome_seen', 'kit_downloaded' ) as $key ) {
			if ( null !== $request[ $key ] ) {
				$saved[ $key ] = (bool) $request[ $key ];
			}
		}
		update_option( self::OPTION, $saved, false );
		return $this->status();
	}
}
