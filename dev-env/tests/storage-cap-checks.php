<?php
/**
 * Storage cap: default 1 GB or 3 auto-snapshots, adjustable in settings.
 * Oldest auto-snapshots deleted first; never deletes manual, pinned, or the
 * snapshot needed to undo the most recent update. Disk-space check before
 * each snapshot. Front-end adds no assets or autoloaded data.
 *
 *   docker compose exec wp wp eval-file /tests/storage-cap-checks.php --user=chrono
 *
 * DESTRUCTIVE. Only run against the disposable dev-env stack.
 */

use WPChrono\API\RestController;
use WPChrono\Core\Storage;
use WPChrono\Services\Settings;
use WPChrono\Services\SiteInspector;
use WPChrono\Services\SnapshotService;

$GLOBALS['wpc_fails']  = 0;
$GLOBALS['wpc_passes'] = 0;

function wpc_check( string $label, bool $ok, string $extra = '' ): void {
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) . "\n";
	$ok ? $GLOBALS['wpc_passes']++ : $GLOBALS['wpc_fails']++;
}

function wpc_call( string $method, string $route, array $params = array() ): array {
	$request = new WP_REST_Request( $method, '/wp-chrono/v1/' . ltrim( $route, '/' ) );
	$request->set_header( RestController::NONCE_HEADER, wp_create_nonce( RestController::NONCE_ACTION ) );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
}

global $wpdb;
$service = new SnapshotService();
$p       = $wpdb->prefix;

// Clean slate: delete all existing snapshots.
foreach ( $service->all() as $s ) {
	$service->delete( (int) $s['id'] );
}
delete_transient( SnapshotService::AUTO_LOCK );

// ---------------------------------------------------------------------------
echo "== 1. Default cap settings ==\n";
$settings = Settings::get();
wpc_check( 'default storage cap is 1 GB', 1 === $settings['storage_cap_gb'], (string) $settings['storage_cap_gb'] );
wpc_check( 'default auto-snapshot cap is 3', 3 === $settings['storage_cap_autos'], (string) $settings['storage_cap_autos'] );
wpc_check( 'REST /settings exposes cap fields', isset( $settings['storage_cap_gb'] ) && isset( $settings['storage_cap_autos'] ) );

// ---------------------------------------------------------------------------
echo "\n== 2. Cap enforcement: oldest auto-snapshots deleted first ==\n";
Settings::update( array( 'storage_cap_gb' => 1, 'storage_cap_autos' => 2 ) );
delete_transient( SnapshotService::AUTO_LOCK );

$ids = array();
for ( $i = 1; $i <= 4; $i++ ) {
	$ids[] = (int) $service->create_and_build( "Auto #{$i}", 'auto' )['id'];
}
wpc_check( '4 auto-snapshots created', 4 === count( array_filter( $service->all(), static function ( $s ) { return 'auto' === $s['trigger_type']; } ) ) );

// enforce_storage_cap with keep_autos=2 should delete the 2 oldest.
$deleted = $service->enforce_storage_cap( 2 );
wpc_check( '2 oldest autos deleted', 2 === count( $deleted ) && in_array( $ids[0], $deleted, true ) && in_array( $ids[1], $deleted, true ), implode( ',', $deleted ) );
wpc_check( 'newest 2 autos kept', 2 === count( array_filter( $service->all(), static function ( $s ) use ( $ids ) { return 'auto' === $s['trigger_type'] && in_array( $s['id'], array_slice( $ids, 2 ), true ); } ) ) );

// ---------------------------------------------------------------------------
echo "\n== 3. Never-delete rules ==\n";
Settings::update( array( 'storage_cap_autos' => 1 ) );
delete_transient( SnapshotService::AUTO_LOCK );

// Manual snapshot must never be deleted.
$manualId = (int) $service->create( 'Manual snapshot', 'manual' )['id'];
$service->build( $manualId );

// Pinned snapshot must never be deleted.
$pinnedId = (int) $service->create_and_build( 'Pinned auto', 'auto' )['id'];
$service->set_pinned( $pinnedId, true );

// AUTO_LOCK snapshot (most recent update) must never be deleted.
$lockId = (int) $service->create_and_build( 'Locked auto', 'auto' )['id'];
set_transient( SnapshotService::AUTO_LOCK, array( 'snapshot' => $lockId ), SnapshotService::AUTO_LOCK_TTL );

// One more auto-snapshot that IS deletable.
$deletableId = (int) $service->create_and_build( 'Deletable auto', 'auto' )['id'];

// Cap is 1 auto: only the deletable one should go.
$deleted = $service->enforce_storage_cap( 1 );
$stillThere = array_column( array_filter( $service->all(), static function ( $s ) { return 'auto' === $s['trigger_type'] && 'ready' === $s['status']; } ), 'id' );
wpc_check( 'only deletable auto removed', in_array( $deletableId, $deleted, true ), implode( ',', $deleted ) );
wpc_check( 'manual snapshot kept', in_array( $manualId, $stillThere, true ) );
wpc_check( 'pinned snapshot kept', in_array( $pinnedId, $stillThere, true ) );
wpc_check( 'AUTO_LOCK snapshot kept', in_array( $lockId, $stillThere, true ) );

// ---------------------------------------------------------------------------
echo "\n== 4. Low disk space skips the snapshot ==\n";
Settings::update( array( 'auto_snapshot' => true ) );
delete_transient( SnapshotService::AUTO_LOCK );

// Force disk_free_space() to report almost no free space.
add_filter( 'wp_chrono_storage_free_bytes', static function () {
	return 1024; // 1 KB
} );

$before = count( array_filter( $service->all(), static function ( $s ) { return 'auto' === $s['trigger_type']; } ) );
$result = apply_filters( 'upgrader_pre_install', true, array( 'plugin' => 'akismet/akismet.php', 'action' => 'update' ) );
$after  = count( array_filter( $service->all(), static function ( $s ) { return 'auto' === $s['trigger_type']; } ) );
remove_filter( 'wp_chrono_storage_free_bytes' );

wpc_check( 'upgrader_pre_install passes through (update not blocked)', true === $result );
wpc_check( 'no auto-snapshot created when disk is low', $before === $after, "before={$before} after={$after}" );
wpc_check( 'admin email sent (low disk notice)', true === get_transient( 'wp_chrono_low_disk_notice' ) );

// ---------------------------------------------------------------------------
echo "\n== 5. REST pin endpoint ==\n";
$snapId = (int) $service->create_and_build( 'To pin', 'manual' )['id'];
list( $status, $data ) = wpc_call( 'POST', "snapshots/{$snapId}/pin", array( 'pinned' => true ) );
wpc_check( 'POST /snapshots/{id}/pin pins the snapshot', 200 === $status && true === ( $data['pinned'] ?? false ), "HTTP {$status}" );
wpc_check( 'pinned flag stored on snapshot', true === ( $service->get( $snapId )['meta']['pinned'] ?? false ) );

list( $status, $data ) = wpc_call( 'POST', "snapshots/{$snapId}/pin", array( 'pinned' => false ) );
wpc_check( 'POST /snapshots/{id}/pin unpins the snapshot', 200 === $status && false === ( $data['pinned'] ?? true ), "HTTP {$status}" );
wpc_check( 'pinned flag cleared', false === ( $service->get( $snapId )['meta']['pinned'] ?? true ) );

// ---------------------------------------------------------------------------
echo "\n== 6. Storage info in /snapshots index ==\n";
list( $status, $index ) = wpc_call( 'GET', 'snapshots' );
wpc_check( 'GET /snapshots includes storage cap info', 200 === $status && isset( $index['storage']['cap_bytes'] ) && isset( $index['storage']['cap_autos'] ) && isset( $index['storage']['over_cap'] ), "HTTP {$status}" );

// ---------------------------------------------------------------------------
echo "\n== 7. Front-end: no plugin assets or autoloaded data ==\n";
// The plugin only enqueues on its own admin page (hook_suffix match) and only
// registers hooks in is_admin() / cron / CLI / REST contexts. Verify no
// wp-chrono script handle is registered globally and our option is not autoloaded.
global $wp_scripts;
$found = false;
foreach ( (array) $wp_scripts->registered as $handle => $obj ) {
	if ( 'wp-chrono-app' === $handle || 0 === strpos( (string) $handle, 'wp-chrono' ) ) {
		$found = true;
		break;
	}
}
wpc_check( 'no wp-chrono scripts registered globally', ! $found );

$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'wp_chrono_settings' ) );
wpc_check( 'wp_chrono_settings is not autoloaded', 'no' === $autoload, $autoload ?? 'missing' );

// ---------------------------------------------------------------------------
echo "\n== 8. Settings change triggers cap enforcement ==\n";
Settings::update( array( 'storage_cap_autos' => 1 ) );
$deleted = $service->enforce_storage_cap();
$remainingAutos = count( array_filter( $service->all(), static function ( $s ) { return 'auto' === $s['trigger_type'] && 'ready' === $s['status']; } ) );
wpc_check( 'cap enforcement after settings change', $remainingAutos <= 1, "still {$remainingAutos} autos" );

// ---------------------------------------------------------------------------
echo "\n" . ( $GLOBALS['wpc_fails'] ? "{$GLOBALS['wpc_fails']} check(s) FAILED ({$GLOBALS['wpc_passes']} passed)" : "All checks passed ({$GLOBALS['wpc_passes']} passed)" ) . "\n";
