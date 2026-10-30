<?php
/**
 * Milestone 3.2: BYOC cloud offload against an offline mock S3 endpoint
 * (tests/mock-s3.php on 127.0.0.1:9000, started by cloud-checks.sh).
 *
 *   docker compose exec wp wp eval-file /tests/cloud-checks.php --user=chrono
 */

use WPChrono\API\RestController;
use WPChrono\Services\CloudStorageService as Cloud;
use WPChrono\Services\Snapshot\CloudSync;
use WPChrono\Services\Snapshot\FileArchive;
use WPChrono\Services\SnapshotService;

$GLOBALS['wpc_fails']  = 0;
$GLOBALS['wpc_passes'] = 0;
function wpc_check( string $label, bool $ok, string $extra = '' ): void {
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) . "\n";
	$ok ? $GLOBALS['wpc_passes']++ : $GLOBALS['wpc_fails']++;
}
function wpc_rest( string $method, string $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, '/wp-chrono/v1/' . ltrim( $route, '/' ) );
	$request->set_header( RestController::NONCE_HEADER, wp_create_nonce( RestController::NONCE_ACTION ) );
	foreach ( $params as $k => $v ) {
		$request->set_param( $k, $v );
	}
	return rest_do_request( $request );
}
function wpc_err( $response ): string {
	$data = $response->get_data();
	return is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : '';
}

const MOCK_DATA   = '/tmp/mock-s3/data/wpc-test-bucket/';
const MOCK_SECRET = 'mock/Secret+Key/0123456789abcdefghij';

global $wpdb;
$service = new SnapshotService();

// ---------------------------------------------------------------------------
echo "== AWS Signature V4 (published test vectors) ==\n";
$h = Cloud::sign_v4( 'GET', 'https://example.amazonaws.com/', array(), Cloud::EMPTY_SHA256, 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'service', gmmktime( 12, 36, 0, 8, 30, 2015 ) );
wpc_check( 'aws-sig-v4-test-suite "get-vanilla"', false !== strpos( $h['Authorization'], 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31' ) && false !== strpos( $h['Authorization'], 'SignedHeaders=host;x-amz-date' ) );
$h = Cloud::sign_v4( 'GET', 'https://examplebucket.s3.amazonaws.com/test.txt', array( 'Range' => 'bytes=0-9', 'x-amz-content-sha256' => Cloud::EMPTY_SHA256 ), Cloud::EMPTY_SHA256, 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1', 's3', gmmktime( 0, 0, 0, 5, 24, 2013 ) );
wpc_check( 'S3 docs "GET Object" example (Range header)', false !== strpos( $h['Authorization'], 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41' ) );

// ---------------------------------------------------------------------------
echo "\n== Settings: secret encrypted at rest ==\n";
$r = wpc_rest( 'POST', 'cloud', array( 'provider' => 'custom', 'endpoint' => 'http://127.0.0.1:9000', 'region' => 'us-east-1', 'bucket' => 'wpc-test-bucket', 'access_key' => 'MOCKACCESSKEY', 'secret_key' => MOCK_SECRET, 'path_style' => true, 'prefix' => 'backups' ) );
$public = $r->get_data();
wpc_check( 'POST /cloud saves settings', 200 === $r->get_status() && ! empty( $public['configured'] ), wpc_err( $r ) );
$stored = get_option( Cloud::OPTION );
wpc_check( 'secret stored encrypted, never in plain text', false === strpos( (string) wp_json_encode( $stored ), MOCK_SECRET ) && 0 === strpos( (string) $stored['secret'], 'sb1:' ), substr( (string) $stored['secret'], 0, 12 ) . '…' );
wpc_check( 'decrypts back to the original secret', MOCK_SECRET === Cloud::decrypt( (string) $stored['secret'] ) );
$get = wpc_rest( 'GET', 'cloud' )->get_data();
wpc_check( 'GET /cloud never returns the secret', ! isset( $get['secret'] ) && ! isset( $get['secret_key'] ) && true === $get['has_secret'] && false === strpos( (string) wp_json_encode( $get ), 'sb1:' ) );
wpc_rest( 'POST', 'cloud', array( 'bucket' => 'wpc-test-bucket', 'secret_key' => '' ) );
wpc_check( 'saving with an empty secret keeps the stored one', MOCK_SECRET === Cloud::decrypt( (string) get_option( Cloud::OPTION )['secret'] ) );
wpc_check( 'prefix normalised to a folder', 'backups/' === get_option( Cloud::OPTION )['prefix'] );
$bad = wpc_rest( 'POST', 'cloud', array( 'bucket' => 'Bad_Bucket!' ) );
wpc_check( 'invalid bucket name rejected', 400 === $bad->get_status() );

// ---------------------------------------------------------------------------
echo "\n== Test connection ==\n";
$t = wpc_rest( 'POST', 'cloud/test' );
wpc_check( 'list + put + get + delete probe against the bucket', 200 === $t->get_status() && ! empty( $t->get_data()['ok'] ), 200 === $t->get_status() ? $t->get_data()['message'] : wpc_err( $t ) );
$wrong = ( new Cloud( array_merge( get_option( Cloud::OPTION ), array( 'secret_key' => 'wrong-secret' ) ) ) )->test();
wpc_check( 'wrong secret: endpoint rejects the signature', is_wp_error( $wrong ) && 'SignatureDoesNotMatch' === ( $wrong->get_error_data()['s3_code'] ?? '' ), is_wp_error( $wrong ) ? $wrong->get_error_message() : 'accepted?!' );
$client = new Cloud();
$odd    = 'backups/odd names/ünïcode & spaces+plus=equals.txt';
$ok     = $client->put_string( $odd, 'odd', 'text/plain' );
wpc_check( 'keys with spaces, unicode, & + = are signed and stored correctly', true === $ok && 'odd' === $client->get_string( $odd ), is_wp_error( $ok ) ? $ok->get_error_message() : '' );
$client->delete( $odd );

// ---------------------------------------------------------------------------
echo "\n== Push a snapshot (SQL export + plugin archive) ==\n";
update_option( 'wpc_cloud_marker', 'pushed-state' );
wp_mkdir_p( WP_PLUGIN_DIR . '/wpc-cloud-fixture' );
file_put_contents( WP_PLUGIN_DIR . '/wpc-cloud-fixture/wpc-cloud-fixture.php', "<?php\n/**\n * Plugin Name: WPC Cloud Fixture\n * Version: 1.0\n */\n" );
$snap = $service->create_and_build( 'Cloud test', 'manual' );
$service->attach_archives( $snap['id'], FileArchive::targets( array( 'plugin' => 'wpc-cloud-fixture/wpc-cloud-fixture.php' ) ) );
$snap = $service->get( $snap['id'] );
wpc_check( 'snapshot has a plugin folder archive to ship along', 1 === count( SnapshotService::rollback_files( $snap ) ) );

$job    = '';
$step   = 0;
$phases = array();
for ( $i = 0; $i < 100; $i++ ) {
	$r = wpc_rest( 'POST', "cloud/push/{$snap['id']}", array( 'job' => $job, 'step' => $step ) );
	if ( 200 !== $r->get_status() ) {
		break;
	}
	$d        = $r->get_data();
	$phases[] = $d['phase'];
	$job      = (string) ( $d['job'] ?? '' );
	$step     = (int) ( $d['step'] ?? 0 );
	if ( $d['done'] ) {
		break;
	}
}
wpc_check( 'push finished: export → uploads → manifest', ! empty( $d['done'] ) && in_array( 'export', $phases, true ) && 'manifest' === end( $phases ), implode( ' → ', array_unique( $phases ) ) . ( 200 !== $r->get_status() ? ' ' . wpc_err( $r ) : '' ) );
$prefix   = (string) ( $d['key'] ?? '' );
$local    = MOCK_DATA . $prefix;
$manifest = json_decode( (string) @file_get_contents( $local . 'manifest.json' ), true );
wpc_check( 'objects landed in the bucket: db.sql.gz, rollback zip, manifest.json', is_file( $local . 'db.sql.gz' ) && 1 === count( glob( $local . 'rollback/*.zip' ) ?: array() ) && is_array( $manifest ), $prefix );
wpc_check( 'remote dump byte-identical to the local export (SHA-256)', is_array( $manifest ) && hash_file( 'sha256', $local . 'db.sql.gz' ) === $manifest['db']['sha256'] );
wpc_check( 'manifest lists every table with row counts', is_array( $manifest ) && count( $manifest['snapshot']['tables'] ) === $snap['table_count'] );
wpc_check( 'snapshot meta records the push', ! empty( $service->get( $snap['id'] )['meta']['cloud']['pushed_at'] ) );

$remote = wpc_rest( 'GET', 'cloud/remote' )->get_data()['backups'] ?? array();
$mine   = array_values( array_filter( $remote, static function ( $b ) use ( $prefix ) { return $b['key'] === $prefix; } ) );
wpc_check( 'GET /cloud/remote lists the backup', 1 === count( $mine ) && 'Cloud test' === $mine[0]['label'] && 1 === $mine[0]['files'], count( $remote ) . ' remote backups' );

// ---------------------------------------------------------------------------
echo "\n== Pull it back as a new local snapshot and restore ==\n";
$orig_rows = $snap['row_count'];
$service->delete( $snap['id'] );
update_option( 'wpc_cloud_marker', 'changed-after-push' );
$job = '';
for ( $i = 0; $i < 200; $i++ ) {
	$r = wpc_rest( 'POST', 'cloud/pull', array( 'key' => $prefix, 'job' => $job ) );
	if ( 200 !== $r->get_status() ) {
		break;
	}
	$d   = $r->get_data();
	$job = (string) ( $d['job'] ?? '' );
	if ( $d['done'] ) {
		break;
	}
}
$pulled = ! empty( $d['done'] ) ? $service->get( (int) $d['snapshot_id'] ) : null;
wpc_check( 'pull finished: download (checksums) → import', (bool) $pulled, 200 === $r->get_status() ? (string) ( $d['message'] ?? '' ) : wpc_err( $r ) );
wpc_check( 'new snapshot has trigger "cloud", same tables and rows', $pulled && 'cloud' === $pulled['trigger_type'] && $pulled['table_count'] === $snap['table_count'] && $pulled['row_count'] === $orig_rows, $pulled ? "{$pulled['table_count']} tables, {$pulled['row_count']} rows (orig {$orig_rows})" : '' );
wpc_check( 'pulled snapshot passes integrity verification', $pulled && true === ( $pulled['meta']['verified'] ?? null ) );
$pulled_files = $pulled ? SnapshotService::rollback_files( $pulled ) : array();
wpc_check( 'plugin archive came back and is attached (Full Undo possible)', 1 === count( $pulled_files ) && '' !== FileArchive::path( $pulled_files[0] ) && 0 === strpos( $pulled_files[0]['zip'], "snap-{$pulled['id']}-" ) );
wpc_check( 'dump kept as the snapshot\'s downloadable export', $pulled && 1 === count( (array) ( $pulled['meta']['exports'] ?? array() ) ) );

if ( ! $pulled ) {
	echo "\n{$GLOBALS['wpc_fails']} check(s) FAILED ({$GLOBALS['wpc_passes']} passed) — pull failed, stopping.\n";
	return;
}
$tick = $service->undo_update( $pulled['id'], 'db' );
wp_cache_flush();
wpc_check( 'restore of the pulled snapshot (atomic swap)', ! is_wp_error( $tick ) && $tick['done'] );
wpc_check( 'database is back to the pushed state', 'pushed-state' === get_option( 'wpc_cloud_marker' ) );

$fixture = WP_PLUGIN_DIR . '/wpc-cloud-fixture/wpc-cloud-fixture.php';
$before  = (string) file_get_contents( $fixture );
file_put_contents( $fixture, "\n// tampered after push\n", FILE_APPEND );
$files = $service->undo_files( $pulled['id'] );
clearstatcache();
wpc_check( 'files-only revert from the pulled archive', ! is_wp_error( $files ) && 1 === $files['files_restored'] && $before === file_get_contents( $fixture ) );

// ---------------------------------------------------------------------------
echo "\n== Tampered manifests (the bucket is not trusted) ==\n";
$real_manifest = (string) file_get_contents( $local . 'manifest.json' );
$m             = json_decode( $real_manifest, true );
wpc_check( 'pushed manifest carries a rollback_mac', 64 === strlen( (string) ( $m['rollback_mac'] ?? '' ) ) );
// 1. Same signed entries, but the path fields point somewhere else: ignored, rebuilt locally.
$m['rollback'][0]['target']       = '/var/www/html/wp-admin';
$m['rollback'][0]['installed_to'] = WP_PLUGIN_DIR . '/akismet';
file_put_contents( $local . 'manifest.json', wp_json_encode( $m ) );
$r     = wpc_pull_all( new CloudSync( $service ), $prefix );
$p2    = ( ! is_wp_error( $r ) && ! empty( $r['done'] ) ) ? $service->get( (int) $r['snapshot_id'] ) : null;
$entry = $p2 ? ( SnapshotService::rollback_files( $p2 )[0] ?? array() ) : array();
wpc_check( 'manifest target/installed_to ignored: path rebuilt for this site', wp_normalize_path( WP_PLUGIN_DIR . '/wpc-cloud-fixture' ) === ( $entry['target'] ?? '' ) && ! isset( $entry['installed_to'] ), (string) ( $entry['target'] ?? wpc_err( $r ) ) );
if ( $p2 ) {
	$service->delete( (int) $p2['id'] );
}
// 2. An attacker swaps in their own archive and fixes up the checksum: MAC fails, code not pulled.
$m                           = json_decode( $real_manifest, true );
$m['rollback'][0]['sha256'] = str_repeat( 'a', 64 );
file_put_contents( $local . 'manifest.json', wp_json_encode( $m ) );
$r  = wpc_pull_all( new CloudSync( $service ), $prefix );
$p3 = ( ! is_wp_error( $r ) && ! empty( $r['done'] ) ) ? $service->get( (int) $r['snapshot_id'] ) : null;
wpc_check( 'forged archive checksum: database pulled, code archive skipped', $p3 && array() === SnapshotService::rollback_files( $p3 ) && false !== strpos( (string) $r['message'], 'NOT pulled' ), is_wp_error( $r ) ? wpc_err( $r ) : (string) ( $r['message'] ?? '' ) );
if ( $p3 ) {
	$service->delete( (int) $p3['id'] );
}
// 3. Entry for a path outside plugins/themes/core: refused even when signed shapes are bent.
wpc_check( 'localize_entry refuses traversal and odd shapes', null === FileArchive::localize_entry( array( 'type' => 'plugin', 'slug' => '..', 'file' => '../x.php' ) ) && null === FileArchive::localize_entry( array( 'type' => 'core', 'slug' => 'wp-config.php', 'file' => 'wp-config.php' ) ) && null === FileArchive::localize_entry( array( 'type' => 'theme', 'slug' => 'a/b', 'file' => 'a/b' ) ) );
file_put_contents( $local . 'manifest.json', $real_manifest );

echo "\n== Integrity and safety of pulls ==\n";
$count = count( $service->all() );
file_put_contents( $local . 'db.sql.gz', 'x', FILE_APPEND );
$r1 = ( new CloudSync( $service ) )->pull( $prefix, '', INF );
$r2 = ( new CloudSync( $service ) )->pull( $prefix, (string) ( $r1['job'] ?? '' ), INF );
wpc_check( 'corrupted object in the bucket: SHA-256 mismatch, pull refused', is_wp_error( $r2 ) && false !== strpos( $r2->get_error_message(), 'SHA-256' ) && $count === count( $service->all() ), is_wp_error( $r2 ) ? $r2->get_error_message() : 'accepted' );

/** Uploads a hand-made backup folder with a valid manifest + checksum. */
function wpc_fake_backup( Cloud $client, string $folder, string $sql ): void {
	global $wpdb;
	$gz = gzencode( $sql );
	$client->put_string( $folder . 'db.sql.gz', $gz, 'application/gzip' );
	$client->put_string(
		$folder . 'manifest.json',
		(string) wp_json_encode(
			array(
				'wpchrono_cloud' => 1,
				'site'           => home_url(),
				'table_prefix'   => $wpdb->prefix,
				'pushed_at'      => gmdate( 'c' ),
				'snapshot'       => array( 'id' => 999, 'uid' => 'evil', 'label' => 'Evil', 'tables' => array() ),
				'db'             => array( 'key' => $folder . 'db.sql.gz', 'sha256' => hash( 'sha256', $gz ), 'bytes' => strlen( $gz ) ),
				'rollback'       => array(),
			)
		)
	);
}
function wpc_pull_all( CloudSync $sync, string $key ) {
	$job = '';
	for ( $i = 0; $i < 20; $i++ ) {
		$r = $sync->pull( $key, $job, INF );
		if ( is_wp_error( $r ) || $r['done'] ) {
			return $r;
		}
		$job = (string) $r['job'];
	}
	return $r;
}
$users_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
$evil         = $client->site_prefix() . 'evil1/';
wpc_fake_backup( $client, $evil, "CREATE TABLE `{$wpdb->prefix}x` (\n  `id` int NOT NULL\n) ENGINE=InnoDB;\nDROP TABLE `{$wpdb->users}`;\n-- End of WP Chrono export\n" );
$r = wpc_pull_all( new CloudSync( $service ), $evil );
wpc_check( 'malicious dump (DROP TABLE wp_users) refused, nothing executed', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'does not import' ) && $users_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ), is_wp_error( $r ) ? $r->get_error_message() : 'accepted' );
$evil2 = $client->site_prefix() . 'evil2/';
wpc_fake_backup( $client, $evil2, "CREATE TABLE `other_prefix_users` (\n  `id` int NOT NULL\n) ENGINE=InnoDB;\n-- End of WP Chrono export\n" );
$r = wpc_pull_all( new CloudSync( $service ), $evil2 );
wpc_check( 'dump touching another prefix\'s tables refused', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'does not belong to this site' ), is_wp_error( $r ) ? $r->get_error_message() : 'accepted' );
wpc_check( 'failed imports leave no half-built snapshot behind', $count === count( $service->all() ) );
$r = ( new CloudSync( $service ) )->pull( 'someone-else/site/abc/', '', INF );
wpc_check( 'pulling a folder outside this site\'s prefix refused', is_wp_error( $r ) );

// Cleanup.
if ( $pulled ) {
	$service->delete( $pulled['id'] );
}
FileArchive::rmtree( WP_PLUGIN_DIR . '/wpc-cloud-fixture' );
delete_option( 'wpc_cloud_marker' );

echo "\n" . ( $GLOBALS['wpc_fails'] ? "{$GLOBALS['wpc_fails']} check(s) FAILED ({$GLOBALS['wpc_passes']} passed)" : "All checks passed ({$GLOBALS['wpc_passes']} passed)" ) . "\n";
