<?php
/**
 * Installs wpc-bad v1.0, then runs a real Plugin_Upgrader update to a broken
 * v2.0 that changes the database AND fatals:
 *
 *   front  v2.0 writes wpc_bad_marker=v2 on load and fatals on front-end
 *          requests only (wp-admin and the REST API still work)
 *   hard   v2.0 writes wpc_bad_marker=v2 and fatals on every request
 *
 * The fatal happens on the first web request after the update, like a real
 * broken release. Prints SNAP=<auto-snapshot id>.
 *
 *   wp eval-file /tests/rollback-fixture.php front|hard --user=chrono
 */

use WPChrono\Services\Settings;
use WPChrono\Services\SnapshotService;

require '/tests/lib/upgrade-helpers.php';

$mode = ( $args[0] ?? 'front' ) === 'hard' ? 'hard' : 'front';
$body = "update_option( 'wpc_bad_marker', 'v2' );\n" . ( 'hard' === $mode
	? "throw new Error( 'wpc-bad 2.0 fatal on load' );"
	: "add_action( 'template_redirect', function () { throw new Error( 'wpc-bad 2.0 front-end fatal' ); } );" );

Settings::update( array( 'auto_snapshot' => true ) );
$file = wpc_install_plugin( 'wpc-bad', '1.0' );
wp_clean_plugins_cache();
activate_plugin( $file );
update_option( 'wpc_bad_marker', 'v1' );
wpc_plugin_zip( 'wpc-bad', '2.0', $body );

delete_transient( SnapshotService::AUTO_LOCK );
$service = new SnapshotService();
$before  = (int) max( array_merge( array( 0 ), array_column( $service->all(), 'id' ) ) );
$results = wpc_bulk_upgrade_plugins( array( $file => '2.0' ) );
delete_transient( SnapshotService::AUTO_LOCK );

$snap = null;
foreach ( $service->all() as $s ) {
	if ( $s['id'] > $before && 'auto' === $s['trigger_type'] ) {
		$snap = $s;
	}
}
echo 'UPGRADED=' . ( wpc_upgrade_ok( $results, array( $file ) ) ? 'yes' : 'no' ) . "\n";
echo 'VERSION=' . wpc_disk_version( $file ) . "\n";
echo 'ARCHIVES=' . ( $snap ? count( SnapshotService::rollback_files( $snap ) ) : 0 ) . "\n";
echo 'SNAP=' . ( $snap ? $snap['id'] : 0 ) . "\n";
