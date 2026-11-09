<?php
/**
 * Server-side fixtures for ui-e2e-checks.mjs.
 *
 *   wp eval-file /tests/ui-fixtures.php rollback --user=chrono   real update wpc-ui-roll 1.0 → 2.0, prints SNAP=<id>
 *   wp eval-file /tests/ui-fixtures.php version  --user=chrono   prints VERSION=<on-disk version>
 *   wp eval-file /tests/ui-fixtures.php cleanup  --user=chrono   removes fixtures and cloud settings
 */

use WPChrono\Services\CloudStorageService;
use WPChrono\Services\Settings;
use WPChrono\Services\SnapshotService;

require '/tests/lib/upgrade-helpers.php';

$file = 'wpc-ui-roll/wpc-ui-roll.php';
switch ( $args[0] ?? '' ) {
	case 'permalink': // Current permalink_structure, for the post-reset REST check.
		echo 'PERMALINK=' . var_export( get_option( 'permalink_structure' ), true ) . "\n";
		break;
	case 'rollback':
		Settings::update( array( 'auto_snapshot' => true ) );
		wpc_install_plugin( 'wpc-ui-roll', '1.0' );
		wpc_plugin_zip( 'wpc-ui-roll', '2.0' );
		wp_clean_plugins_cache();
		activate_plugin( $file );
		delete_transient( SnapshotService::AUTO_LOCK );
		$service = new SnapshotService();
		$before  = (int) max( array_merge( array( 0 ), array_column( $service->all(), 'id' ) ) );
		wpc_bulk_upgrade_plugins( array( $file => '2.0' ) );
		delete_transient( SnapshotService::AUTO_LOCK );
		foreach ( $service->all() as $s ) {
			if ( $s['id'] > $before && 'auto' === $s['trigger_type'] ) {
				echo "SNAP={$s['id']}\n";
			}
		}
		echo 'VERSION=' . wpc_disk_version( $file ) . "\n";
		break;
	case 'version':
		echo 'VERSION=' . wpc_disk_version( $file ) . "\n";
		break;
	case 'onboarding-reset': // First-run state: welcome modal + checklist, recovery script removed.
		update_option( 'wp_chrono_onboarding', array( 'welcome_seen' => false ), false );
		( new WPChrono\Services\RecoveryGenerator() )->delete();
		echo "ONBOARDING=reset\n";
		break;
	case 'license-off':
		WPChrono\Services\LicenseService::deactivate();
		echo "LICENSE=off\n";
		break;
	case 'license-key': // Needs the test keypair from license-checks.php.
		require '/var/www/html/wp-content/plugins/wp-chrono/scripts/license-sign.php';
		$secret = base64_decode( trim( (string) @file_get_contents( '/tmp/wpc-test-license.secret' ) ) );
		echo 'KEY=' . ( 64 === strlen( (string) $secret ) ? wpc_license_mint( $secret, array( 'lid' => 'e2e-' . time(), 'plan' => 'pro', 'sites' => 1, 'source' => 'appsumo', 'issued' => gmdate( 'Y-m-d' ), 'expires' => null ) ) : '' ) . "\n";
		break;
	case 'export-file': // A fresh WP Chrono export, for the Import dialog.
		// ~1.2 MB of incompressible data, so the .sql.gz is larger than a stock
		// nginx client_max_body_size (1 MB) and the dialog must split the upload.
		update_option( 'wpc_e2e_ballast', base64_encode( random_bytes( 1200000 ) ), false );
		$service = new SnapshotService();
		$snap    = $service->create_and_build( 'E2E export source', 'manual' );
		delete_option( 'wpc_e2e_ballast' );
		$job     = '';
		do {
			$tick = $service->export_tick( $snap['id'], $job, INF );
			$job  = (string) ( $tick['job'] ?? '' );
		} while ( empty( $tick['done'] ) );
		echo 'PATH=' . WPChrono\Core\Storage::ensure() . $tick['file'] . "\n";
		break;
	case 'cleanup':
		deactivate_plugins( $file );
		delete_plugins( array( $file ) );
		delete_option( CloudStorageService::OPTION );
		echo "CLEAN=1\n";
		break;
}
