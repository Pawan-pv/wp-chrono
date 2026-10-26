<?php
/**
 * `wp chrono license …`
 *
 * @package WPChrono
 */

namespace WPChrono\CLI;

use WP_CLI;
use WPChrono\Services\LicenseService;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the WP Chrono Pro license (restoring is always free).
 */
final class LicenseCommand {

	/**
	 * Show license status and which features are unlocked.
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
		$s = LicenseService::status();
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $s, JSON_PRETTY_PRINT ) );
			return;
		}
		$rows = array(
			array( 'field' => 'plan', 'value' => $s['plan'] ),
			array( 'field' => 'active', 'value' => $s['active'] ? 'yes' : 'no' ),
			array( 'field' => 'key', 'value' => $s['masked'] ?? '—' ),
			array( 'field' => 'from', 'value' => $s['from'] ?? '—' ),
			array( 'field' => 'license id', 'value' => $s['license']['lid'] ?? '—' ),
			array( 'field' => 'expires', 'value' => $s['license'] ? ( $s['license']['expires'] ?? 'never' ) : '—' ),
			array( 'field' => 'blueprints', 'value' => $s['features']['blueprints'] ? 'unlocked' : 'locked (Pro)' ),
			array( 'field' => 'cloud', 'value' => $s['features']['cloud'] ? 'unlocked' : 'locked (Pro)' ),
			array( 'field' => 'always free', 'value' => implode( ', ', $s['free'] ) ),
			array( 'field' => 'server', 'value' => '' === $s['server'] ? 'none (offline keys)' : $s['server'] ),
		);
		if ( $s['error'] ) {
			$rows[] = array( 'field' => 'error', 'value' => $s['error'] );
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}

	/**
	 * Activate a license key (verified offline with Ed25519).
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The WPC1.… license key.
	 *
	 * [--server=<url>]
	 * : Optional license server for live activation / revocation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp chrono license activate WPC1.eyJsaWQiOi...
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function activate( array $args, array $assoc_args ): void {
		$status = LicenseService::activate( $args[0], isset( $assoc_args['server'] ) ? (string) $assoc_args['server'] : null );
		if ( is_wp_error( $status ) ) {
			WP_CLI::error( $status->get_error_message() );
		}
		WP_CLI::success( sprintf( 'License activated: %s plan (%s), license %s.', $status['plan'], $status['license']['source'], $status['license']['lid'] ) );
	}

	/**
	 * Remove the saved license key (Pro features lock again; nothing else changes).
	 *
	 * @param array<int,string>        $args
	 * @param array<string,string|bool> $assoc_args
	 */
	public function deactivate( array $args, array $assoc_args ): void {
		$status = LicenseService::deactivate();
		if ( 'constant' === $status['from'] ) {
			WP_CLI::warning( 'A key is still set via the WP_CHRONO_LICENSE_KEY constant in wp-config.php.' );
		}
		WP_CLI::success( 'License deactivated. Restoring, importing and the recovery script stay free.' );
	}
}
