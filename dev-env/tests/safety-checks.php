<?php
/**
 * Milestone 2: review-driven safety fixes.
 *   1. uploads cleaner never touches third-party backup folders
 *   2. snapshot integrity verification (and tamper detection)
 *   3. storage quota warning + one-click prune
 *
 *   docker compose exec wp wp eval-file /tests/safety-checks.php --user=chrono
 */

use WPChrono\API\RestController;
use WPChrono\Services\Database;
use WPChrono\Services\ResetService;
use WPChrono\Services\Settings;
use WPChrono\Services\SiteInspector;
use WPChrono\Services\SnapshotService;

$GLOBALS['wpc_fails']  = 0;
$GLOBALS['wpc_passes'] = 0;

function wpc_check( string $label, bool $ok, string $extra = '' ): void {
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) . "\n";
	$ok ? $GLOBALS['wpc_passes']++ : $GLOBALS['wpc_fails']++;
}

function wpc_rest( string $method, string $route, array $body = array() ) {
	$request = new WP_REST_Request( $method, '/wp-chrono/v1/' . ltrim( $route, '/' ) );
	$request->set_header( RestController::NONCE_HEADER, wp_create_nonce( RestController::NONCE_ACTION ) );
	foreach ( $body as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
}

global $wpdb;
$service = new SnapshotService();
$reset   = new ResetService();

// ---------------------------------------------------------------------------
echo "== 1. Uploads cleaner protects third-party backup folders ==\n";
$up    = wp_get_upload_dir()['basedir'];
$files = array(
	'updraft/backup_2026-10-26-db.gz'           => true,
	'ai1wm-backups/site-20261026.wpress'        => true,
	'backwpup-a1b2c3-backups/2026-10-26.zip'    => true,
	'wpvividbackups/wpvivid-site.zip'           => true,
	'BackWPup-Logs/old.html'                    => true, // case-insensitive match
	'2026/10/holiday-photo.jpg'                 => false,
	'random-plugin-cache/blob.bin'              => false,
	'not-updraft/file.txt'                      => false, // exact names only
);
foreach ( $files as $rel => $kept ) {
	wp_mkdir_p( dirname( "{$up}/{$rel}" ) );
	file_put_contents( "{$up}/{$rel}", str_repeat( 'x', 2048 ) );
}

$planted = array( 'updraft', 'ai1wm-backups', 'backwpup-a1b2c3-backups', 'wpvividbackups', 'BackWPup-Logs' );
$preview = $reset->preview( 'uploads' );
$prot    = (array) ( $preview['protected'] ?? array() );
$n       = count( $prot );
wpc_check( 'dry run lists every planted backup folder as protected', ! array_diff( $planted, $prot ) && ! in_array( 'not-updraft', $prot, true ), implode( ', ', $prot ) );
wpc_check( 'dry run summary says how many are protected', (bool) preg_grep( "/^Protected: {$n} backup folders kept untouched/", (array) $preview['details'] ) );
$counted = (int) $preview['items'];

$run = $reset->partial_reset( 'uploads', false, array(), INF );
clearstatcache();
$kept_ok = $gone_ok = true;
foreach ( $files as $rel => $kept ) {
	$exists = file_exists( "{$up}/{$rel}" );
	$kept ? ( $kept_ok = $kept_ok && $exists ) : ( $gone_ok = $gone_ok && ! $exists );
}
wpc_check( 'every file inside protected backup folders survives the cleanup', $kept_ok );
wpc_check( 'ordinary uploads are still deleted', $gone_ok, "{$run['processed']} files deleted" );
wpc_check( 'cleanup log reports the protected folders', (bool) preg_grep( "/^Kept {$n} protected backup folder\\(s\\)/", (array) $run['log'] ) );
wpc_check( 'protected files were not counted as "to be deleted"', $counted === (int) $run['processed'], "{$counted} counted, {$run['processed']} deleted" );
foreach ( $planted as $dir ) {
	array_map( 'unlink', glob( "{$up}/{$dir}/*" ) ?: array() );
	@rmdir( "{$up}/{$dir}" );
}

// ---------------------------------------------------------------------------
echo "\n== 2. Post-snapshot integrity verification ==\n";
$snap = $service->create_and_build( 'Verification test', 'manual' );
wpc_check( 'new snapshot is verified automatically', true === ( $snap['meta']['verified'] ?? null ), sprintf( '%d tables, %d rows, %d ms', $snap['meta']['verification']['tables'] ?? 0, $snap['meta']['verification']['rows'] ?? 0, $snap['meta']['verification']['ms'] ?? 0 ) );
wpc_check( 'verification covered every table', ( $snap['meta']['verification']['tables'] ?? 0 ) === $snap['table_count'] );

$tables = $service->tables( $snap['id'] );
$copy   = $tables[ $wpdb->options ]['snapshot_table'];
$wpdb->query( 'DELETE FROM ' . Database::quote_identifier( $copy ) . ' ORDER BY option_id DESC LIMIT 3' );
$posts_copy = $tables[ $wpdb->posts ]['snapshot_table'];
$wpdb->query( 'ALTER TABLE ' . Database::quote_identifier( $posts_copy ) . ' ADD COLUMN wpc_tamper INT NULL' );
$result = $service->verify( $snap['id'] );
wpc_check( 'missing rows in a copy are detected', ! $result['verified'] && (bool) preg_grep( '/^' . preg_quote( $wpdb->options, '/' ) . ': \d+ rows in the copy, \d+ recorded$/', $result['mismatches'] ), implode( ' | ', $result['mismatches'] ) );
wpc_check( 'schema drift in a copy is detected', (bool) preg_grep( '/columns in the copy/', $result['mismatches'] ) );
wpc_check( 'flag stored on the snapshot (UI shows "Verification failed")', false === ( $service->get( $snap['id'] )['meta']['verified'] ?? null ) );

$wpdb->query( 'DROP TABLE ' . Database::quote_identifier( $copy ) );
$response = wpc_rest( 'POST', "snapshots/{$snap['id']}/verify" );
wpc_check( 'POST /snapshots/{id}/verify reports a missing copy', 200 === $response->get_status() && (bool) preg_grep( '/is missing$/', $response->get_data()['mismatches'] ) );
$service->delete( $snap['id'] );

// ---------------------------------------------------------------------------
echo "\n== 3. Storage quota warning + prune ==\n";
Settings::update( array( 'storage_warn_mb' => 500, 'auto_retention' => 10 ) );
$autos = array();
for ( $i = 1; $i <= 3; $i++ ) {
	$autos[] = $service->create_and_build( "Auto #{$i}", 'auto' )['id'];
}
$usage = ( new SiteInspector() )->storage_usage();
wpc_check( 'below the 500 MB default: no warning', ! $usage['warn'], size_format( $usage['total_bytes'], 1 ) . ' used' );

Settings::update( array( 'storage_warn_mb' => 1 ) );
$status = wpc_rest( 'GET', 'status' )->get_data();
$usage  = $status['storage_usage'];
wpc_check( 'above the threshold: /status raises the warning', $usage['warn'] && false !== strpos( (string) $usage['reason'], 'warning threshold' ), (string) $usage['reason'] );
wpc_check( 'usage counts snapshot tables and archive/export files', $usage['snapshot_bytes'] > 0 && $usage['total_bytes'] === $usage['snapshot_bytes'] + $usage['file_bytes'] );
wpc_check( 'warning offers what pruning would free', $usage['prunable']['count'] >= 2 && $usage['prunable']['bytes'] > 0, "{$usage['prunable']['count']} snapshots, " . size_format( $usage['prunable']['bytes'] ) );
$index = wpc_rest( 'GET', 'snapshots' )->get_data();
wpc_check( 'Snapshots screen gets the same warning', ! empty( $index['storage']['warn'] ) );

$pruned  = wpc_rest( 'POST', 'snapshots/prune-auto', array( 'keep' => 1 ) )->get_data();
$left    = array_filter( $service->all(), static function ( $s ) { return 'auto' === $s['trigger_type']; } );
wpc_check( '1-click prune keeps only the newest auto-snapshot', 1 === count( $left ) && in_array( $autos[0], $pruned['deleted'], true ) && end( $autos ) === reset( $left )['id'], count( $pruned['deleted'] ) . ' deleted' );
wpc_check( 'manual snapshots are never pruned', ! array_diff( array_column( array_filter( $index['snapshots'], static function ( $s ) { return 'manual' === $s['trigger_type']; } ), 'id' ), array_column( $service->all(), 'id' ) ) );

Settings::update( array( 'storage_warn_mb' => 1048576 ) );
$disk_rule = ( new SiteInspector() )->storage_usage( 10 * GB_IN_BYTES, 20 * GB_IN_BYTES );
wpc_check( 'also warns above 25% of free disk space', $disk_rule['warn'] && false !== strpos( (string) $disk_rule['reason'], '25%' ), (string) $disk_rule['reason'] );
Settings::update( array( 'storage_warn_mb' => 500, 'auto_retention' => 5 ) );
foreach ( $left as $s ) {
	$service->delete( $s['id'] );
}

// ---------------------------------------------------------------------------
echo "\n== 4. Multisite scoping helpers on a single site ==\n";
wpc_check( 'single site: no table is treated as another subsite\'s', ! Database::is_other_subsite_table( $wpdb->prefix . '2_posts' ) );

echo "\n" . ( $GLOBALS['wpc_fails'] ? "{$GLOBALS['wpc_fails']} check(s) FAILED ({$GLOBALS['wpc_passes']} passed)" : "All checks passed ({$GLOBALS['wpc_passes']} passed)" ) . "\n";
