<?php
/**
 * `wp chrono recovery …`
 *
 * @package WPChrono
 */

namespace WPChrono\CLI;

use WP_CLI;
use WPChrono\Services\RecoveryGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the standalone Emergency Recovery Script.
 */
final class RecoveryCommand {

	/**
	 * Generate (or regenerate) the recovery script.
	 *
	 * ## OPTIONS
	 *
	 * [--password=<password>]
	 * : Recovery password (12+ characters). A random one is generated if omitted.
	 *
	 * [--porcelain]
	 * : Print only the URL.
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function generate( array $args, array $assoc_args ): void {
		$result = ( new RecoveryGenerator() )->generate( (string) ( $assoc_args['password'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result );
		}
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( $result['url'] );
			return;
		}
		WP_CLI::log( 'URL:      ' . $result['url'] );
		if ( $result['generated_password'] ) {
			WP_CLI::log( 'Password: ' . $result['password'] . '   (shown once — store it safely)' );
		}
		WP_CLI::success( 'Emergency Recovery Script generated.' );
	}

	/**
	 * Show whether a recovery script exists.
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function status( array $args, array $assoc_args ): void {
		$status = ( new RecoveryGenerator() )->status();
		if ( ! $status['exists'] ) {
			WP_CLI::log( 'No recovery script is installed.' );
			return;
		}
		WP_CLI::log( "URL:     {$status['url']}\nCreated: {$status['created_at']}" );
	}

	/**
	 * Delete the recovery script from the server.
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function delete( array $args, array $assoc_args ): void {
		( new RecoveryGenerator() )->delete();
		WP_CLI::success( 'Recovery script removed.' );
	}
}
