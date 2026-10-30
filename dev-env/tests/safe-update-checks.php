<?php
/**
 * Safe Updates, end to end with WordPress's real Plugin_Upgrader and real
 * loopback requests (the dev stack serves WordPress on port 80; its home URL
 * is http://localhost:8088, so probes are routed there with a Host header).
 *
 *   wp eval-file /tests/safe-update-checks.php --user=chrono
 *
 * Fixtures: wpc-safe-ok (healthy update) and wpc-safe-bad, whose v2.0 writes
 * to the database on load and then fatals.
 */

require __DIR__ . '/lib/upgrade-helpers.php';

use WPChrono\Services\SafeUpdates;
use WPChrono\Services\Settings;
use WPChrono\Services\SnapshotService;

echo "== Setup ==\n";
$wpc_home = untrailingslashit( home_url() );
add_filter( 'wp_chrono_safe_updates_probe_url', static function ( $url ) use ( $wpc_home ) {
	return str_replace( $wpc_home, 'http://localhost', $url );
} );
add_filter( 'http_request_args', static function ( $args, $url ) use ( $wpc_home ) {
	if ( 0 === strpos( $url, 'http://localhost/' ) ) {
		$args['headers']['Host'] = wp_parse_url( $wpc_home, PHP_URL_HOST ) . ':' . wp_parse_url( $wpc_home, PHP_URL_PORT );
	}
	return $args;
}, 10, 2 );
// Default 2 s probe delay kept: this stack runs PHP-FPM + OPcache (revalidate_freq=2), like most hosts.
$GLOBALS['wpc_mails'] = array();
add_filter( 'pre_wp_mail', static function ( $null, $atts ) {
	$GLOBALS['wpc_mails'][] = $atts;
	return true;
}, 10, 2 );

$settings_before = Settings::get();
Settings::update( array( 'auto_snapshot' => true, 'safe_updates' => true ) );
delete_option( SafeUpdates::STATE );
delete_transient( SnapshotService::AUTO_LOCK );

$wpc_bad_v1 = "if ( false === get_option( 'wpc_safe_db' ) ) { add_option( 'wpc_safe_db', 'v1' ); }";
$wpc_bad_v2 = "update_option( 'wpc_safe_db', 'v2' );\nwpc_safe_undefined_function();";
$ok  = wpc_install_plugin( 'wpc-safe-ok', '1.0' );
$bad = wpc_install_plugin( 'wpc-safe-bad', '1.0', $wpc_bad_v1 );
activate_plugin( $ok );
activate_plugin( $bad );
update_option( 'wpc_safe_db', 'v1' );
wpc_plugin_zip( 'wpc-safe-ok', '2.0' );
wpc_plugin_zip( 'wpc-safe-bad', '2.0', $wpc_bad_v2 );

$probe = SafeUpdates::probe();
wpc_check( 'probe reaches the site and reads the scrape result', true === $probe['front']['ok'], $probe['front']['reason'] );
wpc_check( 'no admin probe from WP-CLI (no cookies to borrow)', null === $probe['admin'] );

/** The newest auto-snapshot and its Safe Updates record. */
function wpc_last_auto(): array {
	foreach ( ( new SnapshotService() )->all() as $s ) {
		if ( 'auto' === $s['trigger_type'] ) {
			return ( new SnapshotService() )->get( $s['id'] );
		}
	}
	return array();
}
function wpc_reset_batch(): void {
	delete_transient( SnapshotService::AUTO_LOCK );
	wp_cache_flush();
}

echo "\n== Healthy update ==\n";
wpc_reset_batch();
wpc_bulk_upgrade_plugins( array( $ok => '2.0' ) );
$snap = wpc_last_auto();
$su   = $snap['meta']['safe_update'] ?? array();
wpc_check( 'baseline stored on the pre-update snapshot', true === ( $su['baseline']['front']['ok'] ?? null ) );
wpc_check( 'healthy update → "passed", nothing undone', 'passed' === ( $su['status'] ?? '' ), $su['message'] ?? '' );
wpc_check( 'wpc-safe-ok stays on 2.0', '2.0' === wpc_disk_version( $ok ) );
wpc_check( 'no email for a healthy update', 0 === count( $GLOBALS['wpc_mails'] ) );

echo "\n== Broken update (writes to the database, then fatals) ==\n";
wpc_reset_batch();
ob_start();
wpc_bulk_upgrade_plugins( array( $bad => '2.0' ) );
ob_end_clean();
wp_cache_flush();
$snap = wpc_last_auto();
$su   = $snap['meta']['safe_update'] ?? array();
wpc_check( 'fatal detected → "undone"', 'undone' === ( $su['status'] ?? '' ), ( $su['status'] ?? '?' ) . ': ' . ( $su['message'] ?? '' ) );
wpc_check( 'the fatal error was captured (message, file)', false !== strpos( (string) ( $su['error']['message'] ?? '' ), 'wpc_safe_undefined_function' ) && false !== strpos( (string) ( $su['error']['file'] ?? '' ), 'plugins/wpc-safe-bad/' ), ( $su['error']['file'] ?? '' ) . ':' . ( $su['error']['line'] ?? '' ) );
wpc_check( 'culprit identified: wpc-safe-bad 1.0 → 2.0', 'wpc-safe-bad' === ( $su['culprit']['slug'] ?? '' ) && '2.0' === ( $su['culprit']['new_version'] ?? '' ) && '1.0' === ( $su['culprit']['old_version'] ?? '' ) );
wpc_check( 'files back to 1.0', '1.0' === wpc_disk_version( $bad ) );
wpc_check( 'database back too (option written by 2.0 reverted)', 'v1' === get_option( 'wpc_safe_db' ), (string) get_option( 'wpc_safe_db' ) );
wpc_check( 'plugin still active', is_plugin_active( $bad ) );
wpc_check( 'site re-probed after the undo: works', true === ( $su['recheck']['front']['ok'] ?? null ) );
wpc_check( 'batch lock cleared (next update gets a fresh snapshot)', false === get_transient( SnapshotService::AUTO_LOCK ) );
$state = SafeUpdates::state();
wpc_check( 'admin notice queued (survives the database restore)', 'undone' === ( $state['notice']['status'] ?? '' ) && (int) $state['notice']['snapshot'] === (int) $snap['id'] );
wpc_check( 'wpc-safe-bad 2.0 blocked from auto-updates', '2.0' === ( $state['blocked'][ 'plugin:' . $bad ] ?? '' ) );
wpc_check( 'admin emailed once', 1 === count( $GLOBALS['wpc_mails'] ) && false !== strpos( $GLOBALS['wpc_mails'][0]['subject'], 'undone automatically' ) && get_option( 'admin_email' ) === $GLOBALS['wpc_mails'][0]['to'] );
wpc_check( 'email names the plugin and the error', false !== strpos( $GLOBALS['wpc_mails'][0]['message'] ?? '', 'Wpc Safe Bad 2.0' ) && false !== strpos( $GLOBALS['wpc_mails'][0]['message'] ?? '', 'wpc_safe_undefined_function' ) );

echo "\n== Auto-update block ==\n";
wpc_check( 'auto_update_plugin: the undone version is refused', false === apply_filters( 'auto_update_plugin', true, (object) array( 'plugin' => $bad, 'new_version' => '2.0' ) ) );
wpc_check( 'auto_update_plugin: a newer version is allowed', true === apply_filters( 'auto_update_plugin', true, (object) array( 'plugin' => $bad, 'new_version' => '2.0.1' ) ) );
wpc_check( 'auto_update_plugin: other plugins unaffected', true === apply_filters( 'auto_update_plugin', true, (object) array( 'plugin' => $ok, 'new_version' => '2.0' ) ) );

echo "\n== Mixed batch: one healthy + one broken plugin ==\n";
wpc_install_plugin( 'wpc-safe-ok', '1.0' );
wpc_plugin_zip( 'wpc-safe-ok', '3.0' );
wpc_reset_batch();
ob_start();
wpc_bulk_upgrade_plugins( array( $ok => '3.0', $bad => '2.0' ) );
ob_end_clean();
wp_cache_flush();
$su = wpc_last_auto()['meta']['safe_update'] ?? array();
wpc_check( 'whole batch undone (database + both plugins)', 'undone' === ( $su['status'] ?? '' ) && '1.0' === wpc_disk_version( $ok ) && '1.0' === wpc_disk_version( $bad ) && 'v1' === get_option( 'wpc_safe_db' ), ( $su['status'] ?? 'no status' ) . ': ' . ( $su['message'] ?? '' ) . ' ' . wp_json_encode( $su['undo_error'] ?? null ) );
wpc_check( 'culprit is the broken one, not the healthy one', 'wpc-safe-bad' === ( $su['culprit']['slug'] ?? '' ) );

echo "\n== Never acts on uncertainty ==\n";
add_filter( 'wp_chrono_safe_updates_probe_url', $wpc_dead = static function () {
	return 'http://localhost:9/'; // Nothing listens: loopback "blocked".
}, 20 );
wpc_reset_batch();
wpc_bulk_upgrade_plugins( array( $bad => '2.0' ) );
$su = wpc_last_auto()['meta']['safe_update'] ?? array();
wpc_check( 'loopback unavailable → "unverified", update left in place', 'unverified' === ( $su['status'] ?? '' ) && '2.0' === wpc_disk_version( $bad ), $su['message'] ?? '' );
remove_filter( 'wp_chrono_safe_updates_probe_url', $wpc_dead, 20 );
// Put 1.0 back by hand (2.0 fatals on every request).
wpc_install_plugin( 'wpc-safe-bad', '1.0', $wpc_bad_v1 );
update_option( 'wpc_safe_db', 'v1' );

echo "\n== Setting off ==\n";
Settings::update( array( 'safe_updates' => false ) );
wpc_reset_batch();
wpc_bulk_upgrade_plugins( array( $bad => '2.0' ) );
$su = wpc_last_auto()['meta']['safe_update'] ?? null;
wpc_check( 'Safe Updates off → no probe, nothing undone', null === $su && '2.0' === wpc_disk_version( $bad ) );
wpc_install_plugin( 'wpc-safe-bad', '1.0', $wpc_bad_v1 );
update_option( 'wpc_safe_db', 'v1' );
Settings::update( array( 'safe_updates' => true ) );

echo "\n== Visitors' data is never rolled back ==\n";
// A comment (think: an order) arrives while the broken update is running.
wpc_install_plugin( 'wpc-safe-bad', '1.0', $wpc_bad_v1 );
update_option( 'wpc_safe_db', 'v1' );
$GLOBALS['wpc_mails'] = array();
$wpc_cid = 0;
add_action( 'upgrader_process_complete', $wpc_visitor = static function () use ( &$wpc_cid ) {
	$wpc_cid = (int) wp_insert_comment( array( 'comment_post_ID' => 0, 'comment_content' => 'Order placed during the update', 'comment_approved' => 1 ) );
}, 5 );
wpc_reset_batch();
ob_start();
wpc_bulk_upgrade_plugins( array( $bad => '2.0' ) );
ob_end_clean();
remove_action( 'upgrader_process_complete', $wpc_visitor, 5 );
wp_cache_flush();
$su = wpc_last_auto()['meta']['safe_update'] ?? array();
wpc_check( 'new record since the snapshot → files-only undo', 'undone' === ( $su['status'] ?? '' ) && 'files' === ( $su['mode'] ?? '' ) && '1.0' === wpc_disk_version( $bad ), ( $su['status'] ?? '' ) . ' / ' . ( $su['mode'] ?? '' ) );
wpc_check( 'the visitor\'s comment survived', $wpc_cid > 0 && null !== get_comment( $wpc_cid ) );
wpc_check( 'message says the database was kept, and why', false !== strpos( (string) ( $su['message'] ?? '' ), 'NOT rolled back' ) && false !== strpos( (string) ( $su['message'] ?? '' ), '1 new comment' ), (string) ( $su['message'] ?? '' ) );
wp_delete_comment( $wpc_cid, true );
update_option( 'wpc_safe_db', 'v1' );

echo "\n== One-off server errors are not \"broken\" ==\n";
add_filter( 'wp_chrono_safe_updates_retry_delay', '__return_zero' );
$wpc_fake = array( 'left' => 0, 'code' => 502 );
add_filter( 'pre_http_request', $wpc_fake_filter = static function ( $pre, $args, $url ) use ( &$wpc_fake ) {
	if ( $wpc_fake['left'] > 0 && false !== strpos( $url, 'wp_scrape_key=' ) ) {
		$wpc_fake['left']--;
		return array( 'headers' => array(), 'body' => 'Bad gateway', 'response' => array( 'code' => $wpc_fake['code'], 'message' => 'Error' ), 'cookies' => array(), 'filename' => null );
	}
	return $pre;
}, 10, 3 );
add_action( 'upgrader_process_complete', $wpc_arm = static function () use ( &$wpc_fake ) {
	$wpc_fake['left'] = $wpc_fake['code'] === 503 ? 99 : 1;
}, 5 );
wpc_plugin_zip( 'wpc-safe-ok', '5.0' );
wpc_reset_batch();
wpc_bulk_upgrade_plugins( array( $ok => '5.0' ) );
$su = wpc_last_auto()['meta']['safe_update'] ?? array();
wpc_check( 'a single 502 after a healthy update → confirmed healthy, nothing undone', 'passed' === ( $su['status'] ?? '' ) && '5.0' === wpc_disk_version( $ok ), ( $su['status'] ?? '' ) . ': ' . ( $su['message'] ?? '' ) . ' ' . wp_json_encode( $su['baseline']['front']['error'] ?? null ) );
$wpc_fake['code'] = 503;
wpc_plugin_zip( 'wpc-safe-ok', '6.0' );
wpc_reset_batch();
wpc_bulk_upgrade_plugins( array( $ok => '6.0' ) );
$wpc_fake['left'] = 0;
$su = wpc_last_auto()['meta']['safe_update'] ?? array();
wpc_check( 'HTTP 503 (maintenance) on every probe → "unverified", nothing undone', 'unverified' === ( $su['status'] ?? '' ) && '6.0' === wpc_disk_version( $ok ) && false !== strpos( (string) ( $su['message'] ?? '' ), '503' ), ( $su['status'] ?? '' ) . ': ' . ( $su['message'] ?? '' ) );
remove_filter( 'pre_http_request', $wpc_fake_filter, 10 );
remove_action( 'upgrader_process_complete', $wpc_arm, 5 );
wpc_install_plugin( 'wpc-safe-ok', '1.0' );

echo "\n== Background auto-update: core rolled the files back ==\n";
// Core 6.6+ restores the plugin FILES itself and reports it; Safe Updates then
// puts the database back as well (Full Undo of the run).
wpc_reset_batch();
do_action( 'pre_auto_update', 'plugin', (object) array( 'plugin' => $ok ), 'test' );
wpc_plugin_zip( 'wpc-safe-ok', '4.0' );
wpc_bulk_upgrade_plugins( array( $ok => '4.0' ) );
$su = wpc_last_auto()['meta']['safe_update'] ?? array();
wpc_check( 'inside an auto-update run the per-batch check waits for the end of the run', 'watching' === ( $su['status'] ?? '' ) );
update_option( 'wpc_safe_db', 'changed-by-4.0' );
$result = new WP_Error( 'plugin_update_fatal_error_rollback_successful', "The update for 'wpc-safe-ok' contained a fatal error. The previously installed version has been restored." );
do_action( 'automatic_updates_complete', array( 'plugin' => array( (object) array( 'name' => 'Wpc Safe Ok', 'item' => (object) array( 'slug' => 'wpc-safe-ok' ), 'result' => $result ) ) ) );
wp_cache_flush();
$su = wpc_last_auto()['meta']['safe_update'] ?? array();
wpc_check( 'core rollback reported → Full Undo (database back too)', 'undone' === ( $su['status'] ?? '' ) && 'v1' === get_option( 'wpc_safe_db' ) && '1.0' === wpc_disk_version( $ok ), ( $su['status'] ?? 'no status' ) . ' / db=' . get_option( 'wpc_safe_db' ) . ' / ok=' . wpc_disk_version( $ok ) . ' / ' . ( $su['mode'] ?? '' ) . ' ' . wp_json_encode( $su['fresh'] ?? null ) );

echo "\n== Admin notice, REST setting ==\n";
ob_start();
SafeUpdates::notice();
$html = (string) ob_get_clean();
wpc_check( 'admin notice shown to admins, links to the snapshots', false !== strpos( $html, 'notice-warning' ) && false !== strpos( $html, 'undone automatically' ) && false !== strpos( $html, 'wp_chrono_safe_dismiss' ) );
$args = rest_get_server()->get_routes()['/wp-chrono/v1/settings'][1]['args'] ?? array();
wpc_check( 'REST /settings accepts safe_updates (boolean)', 'boolean' === ( $args['safe_updates']['type'] ?? '' ) );
wpc_check( 'GET /settings reports safe_updates', array_key_exists( 'safe_updates', Settings::get() ) && true === Settings::get()['safe_updates'] );

echo "\n== Cleanup ==\n";
deactivate_plugins( array( $ok, $bad ) );
delete_plugins( array( $ok, $bad ) );
delete_option( 'wpc_safe_db' );
delete_option( SafeUpdates::STATE );
delete_transient( SnapshotService::AUTO_LOCK );
Settings::update( $settings_before );
wpc_check( 'fixtures removed, settings restored', ! is_dir( WP_PLUGIN_DIR . '/wpc-safe-bad' ) && Settings::get() == $settings_before );

echo "\n" . ( $GLOBALS['wpc_fails'] ? "{$GLOBALS['wpc_fails']} check(s) FAILED ({$GLOBALS['wpc_passes']} passed)" : "All checks passed ({$GLOBALS['wpc_passes']} passed)" ) . "\n";
