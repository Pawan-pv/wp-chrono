<?php
/**
 * `wp chrono cloud …`
 *
 * @package WPChrono
 */

namespace WPChrono\CLI;

use WP_CLI;
use WPChrono\Services\CloudStorageService;
use WPChrono\Services\Snapshot\CloudSync;
use WPChrono\Services\SnapshotService;

defined( 'ABSPATH' ) || exit;

/**
 * Offload snapshots to S3-compatible storage (Cloudflare R2, AWS S3, Backblaze B2).
 */
final class CloudCommand {

	/**
	 * Show cloud storage settings and test the connection.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function status( array $args, array $assoc_args ): void {
		$settings = CloudStorageService::settings();
		unset( $settings['providers'] );
		$test = $settings['configured'] ? ( new CloudStorageService() )->test() : null;
		$settings['connection'] = null === $test ? 'not configured' : ( is_wp_error( $test ) ? 'ERROR: ' . $test->get_error_message() : $test['message'] );
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $settings, JSON_PRETTY_PRINT ) );
			return;
		}
		$rows = array();
		foreach ( $settings as $key => $value ) {
			$rows[] = array( 'setting' => $key, 'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value );
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'setting', 'value' ) );
		if ( is_wp_error( $test ) ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Save cloud storage settings. The secret key is encrypted at rest.
	 *
	 * ## OPTIONS
	 *
	 * [--provider=<provider>]
	 * : r2, s3, b2 or custom.
	 *
	 * [--endpoint=<url>]
	 * : e.g. https://<account-id>.r2.cloudflarestorage.com
	 *
	 * [--region=<region>]
	 * : e.g. auto (R2), us-east-1.
	 *
	 * [--bucket=<bucket>]
	 * : Bucket name.
	 *
	 * [--access-key=<key>]
	 * : Access key ID.
	 *
	 * [--secret-key=<secret>]
	 * : Secret access key (stored encrypted).
	 *
	 * [--path-style=<bool>]
	 * : Path-style URLs (endpoint/bucket/key) instead of virtual-hosted.
	 *
	 * [--prefix=<folder>]
	 * : Folder inside the bucket (default wp-chrono/).
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function configure( array $args, array $assoc_args ): void {
		$locked = \WPChrono\Services\LicenseService::require( 'cloud' );
		if ( $locked ) {
			WP_CLI::error( $locked->get_error_message() );
		}
		$input = array();
		foreach ( array( 'provider', 'endpoint', 'region', 'bucket', 'access-key', 'secret-key', 'prefix' ) as $key ) {
			if ( isset( $assoc_args[ $key ] ) ) {
				$input[ str_replace( '-', '_', $key ) ] = (string) $assoc_args[ $key ];
			}
		}
		if ( isset( $assoc_args['path-style'] ) ) {
			$input['path_style'] = filter_var( $assoc_args['path-style'], FILTER_VALIDATE_BOOLEAN );
		}
		$saved = CloudStorageService::save( $input );
		if ( is_wp_error( $saved ) ) {
			WP_CLI::error( $saved );
		}
		WP_CLI::success( sprintf( 'Cloud storage saved (%s, bucket "%s", secret %s).', $saved['endpoint'], $saved['bucket'], $saved['has_secret'] ? 'stored encrypted' : 'missing' ) );
	}

	/**
	 * List this site's backups in the bucket.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - keys
	 * ---
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function remote( array $args, array $assoc_args ): void {
		$list = ( new CloudSync( new SnapshotService() ) )->remote();
		if ( is_wp_error( $list ) ) {
			WP_CLI::error( $list );
		}
		if ( 'keys' === ( $assoc_args['format'] ?? '' ) ) {
			foreach ( $list as $b ) {
				WP_CLI::line( $b['key'] );
			}
			return;
		}
		$rows = array_map(
			static function ( $b ) {
				return array( 'key' => $b['key'], 'label' => $b['label'], 'tables' => $b['table_count'], 'rows' => $b['row_count'], 'archives' => $b['files'], 'size' => size_format( $b['bytes'], 1 ), 'pushed' => $b['pushed_at'] );
			},
			$list
		);
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'key', 'label', 'tables', 'rows', 'archives', 'size', 'pushed' ) );
	}

	/**
	 * Push a snapshot (SQL export + plugin/theme archives) to the bucket.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Snapshot ID.
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function push( array $args, array $assoc_args ): void {
		$locked = \WPChrono\Services\LicenseService::require( 'cloud' );
		if ( $locked ) {
			WP_CLI::error( $locked->get_error_message() );
		}
		$sync = new CloudSync( new SnapshotService() );
		$job  = '';
		$step = 0;
		do {
			$tick = $sync->push( (int) $args[0], $job, $step, INF );
			if ( is_wp_error( $tick ) ) {
				WP_CLI::error( $tick );
			}
			WP_CLI::log( '  ' . $tick['message'] );
			$job  = (string) ( $tick['job'] ?? '' );
			$step = (int) ( $tick['step'] ?? 0 );
		} while ( ! $tick['done'] );
		WP_CLI::success( $tick['message'] );
	}

	/**
	 * Download a backup from the bucket as a new local snapshot.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Remote folder, as shown by `wp chrono cloud remote --format=keys`.
	 *
	 * [--porcelain]
	 * : Output just the new snapshot ID.
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function pull( array $args, array $assoc_args ): void {
		$sync = new CloudSync( new SnapshotService() );
		$job  = '';
		$quiet = WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false );
		do {
			$tick = $sync->pull( $args[0], $job, INF );
			if ( is_wp_error( $tick ) ) {
				WP_CLI::error( $tick );
			}
			if ( ! $quiet ) {
				WP_CLI::log( '  ' . $tick['message'] );
			}
			$job = (string) ( $tick['job'] ?? '' );
		} while ( ! $tick['done'] );
		$quiet ? WP_CLI::line( (string) $tick['snapshot_id'] ) : WP_CLI::success( $tick['message'] );
	}
}
