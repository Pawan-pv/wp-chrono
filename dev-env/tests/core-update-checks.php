<?php
/**
 * WordPress core updates: a real Core_Upgrader update of the dev site from
 * its WordPress (6.7.x) to the 7.1.3 tarball in dev-env/cache (converted to
 * the zip core expects, served offline), then:
 *   - the pre-update snapshot archives wp-admin, wp-includes and the root files;
 *   - Full Undo puts core files AND the database back;
 *   - Safe Updates undoes a core update that breaks a plugin, and blocks
 *     that core version from auto-updates.
 *
 *   wp eval-file /tests/core-update-checks.php --user=chrono
 *
 * DESTRUCTIVE for the dev site's core files (ends on the original version).
 */

require __DIR__ . '/lib/upgrade-helpers.php';
require_once ABSPATH . 'wp-admin/includes/update.php';

use WPChrono\Services\SafeUpdates;
use WPChrono\Services\Settings;
use WPChrono\Services\Snapshot\FileArchive;
use WPChrono\Services\SnapshotService;

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
add_filter( 'pre_wp_mail', '__return_true' );
// Offline: core's checksum / version API calls fail fast instead of timing out.
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	return false !== strpos( $url, 'api.wordpress.org' ) ? new WP_Error( 'offline', 'offline test' ) : $pre;
}, 10, 3 );

echo "== Setup ==\n";
$from = FileArchive::core_version();
$to   = '7.1.3';
$pkg  = wpc_pkg_dir() . "wordpress-{$to}.zip";
if ( ! is_file( $pkg ) ) {
	$tmp = get_temp_dir() . 'wpc-core-src/';
	if ( ! is_dir( $tmp . 'wordpress' ) ) {
		wp_mkdir_p( $tmp );
		$tar = new PharData( "/cache/wordpress-{$to}.tar.gz" );
		$tar->extractTo( $tmp, null, true );
	}
	$zip = new ZipArchive();
	$zip->open( $pkg, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp . 'wordpress', FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$zip->addFile( $file->getPathname(), 'wordpress/' . substr( $file->getPathname(), strlen( $tmp ) + strlen( 'wordpress/' ) ) );
	}
	$zip->close();
}
wpc_check( "dev site on WordPress {$from}, {$to} package ready", version_compare( $from, $to, '<' ) && is_file( $pkg ), size_format( (int) @filesize( $pkg ) ) );
$db_before = (int) get_option( 'db_version' );

$settings_before = Settings::get();
// An extra page proves core updates run the full page list, not just the home page.
Settings::update( array( 'auto_snapshot' => true, 'safe_updates' => true, 'safe_updates_urls' => array( home_url( '/?wpc_core_extra=1' ) ) ) );
delete_option( SafeUpdates::STATE );

/** Real Core_Upgrader::upgrade() to $to, package served from wpc_pkg_dir(). */
function wpc_core_update( string $to ) {
	delete_transient( SnapshotService::AUTO_LOCK );
	wpc_serve_packages();
	$offer = (object) array(
		'response' => 'upgrade',
		'download' => "https://packages.invalid/wordpress-{$to}.zip",
		'locale'   => 'en_US',
		'packages' => (object) array( 'full' => "https://packages.invalid/wordpress-{$to}.zip", 'no_content' => false, 'new_bundled' => false, 'partial' => false, 'rollback' => false ),
		'current'  => $to,
		'version'  => $to,
		'php_version'   => '7.4',
		'mysql_version' => '5.5.5',
		'new_bundled'   => '',
		'partial_version' => false,
	);
	$upgrader = new Core_Upgrader( new WPC_Quiet_Skin() );
	ob_start();
	$result = $upgrader->upgrade( $offer, array( 'attempt_rollback' => false, 'do_rollback' => false ) );
	ob_end_clean();
	wp_cache_flush();
	return $result;
}
function wpc_last_auto(): array {
	foreach ( ( new SnapshotService() )->all() as $s ) {
		if ( 'auto' === $s['trigger_type'] ) {
			return ( new SnapshotService() )->get( $s['id'] );
		}
	}
	return array();
}

echo "\n== Core update: snapshot + archive ==\n";
$result = wpc_core_update( $to );
wpc_check( "Core_Upgrader updated WordPress {$from} → {$to}", ! is_wp_error( $result ) && FileArchive::core_version() === $to, is_wp_error( $result ) ? $result->get_error_message() : FileArchive::core_version() );
$snap  = wpc_last_auto();
$files = SnapshotService::rollback_files( $snap );
$core  = array_values( array_filter( $files, static function ( $f ) { return 'core' === $f['type']; } ) );
$slugs = wp_list_pluck( $core, 'slug' );
wpc_check( 'pre-update snapshot labelled for core', "Before updating WordPress {$from}" === ( $snap['label'] ?? '' ), $snap['label'] ?? '' );
wpc_check( 'wp-admin + wp-includes + root files archived', in_array( 'wp-admin', $slugs, true ) && in_array( 'wp-includes', $slugs, true ) && in_array( 'wp-settings.php', $slugs, true ) && in_array( 'index.php', $slugs, true ), count( $core ) . ' entries, ' . size_format( array_sum( wp_list_pluck( $core, 'bytes' ) ) ) );
wpc_check( 'wp-config.php and wp-content never archived', ! in_array( 'wp-config.php', $slugs, true ) && ! in_array( 'wp-content', $slugs, true ) );
wpc_check( "versions recorded ({$from} → {$to})", $from === ( $core[0]['old_version'] ?? '' ) && $to === ( $core[0]['new_version'] ?? '' ) );
wpc_check( 'Safe Updates checked the core update: passed', 'passed' === ( $snap['meta']['safe_update']['status'] ?? '' ), $snap['meta']['safe_update']['message'] ?? '' );
wpc_check( 'core update: the extra page was in the baseline and in the after-check', true === ( $snap['meta']['safe_update']['baseline']['pages']['url_1']['ok'] ?? null ) && true === ( $snap['meta']['safe_update']['after']['pages']['url_1']['ok'] ?? null ), (string) ( $snap['meta']['safe_update']['after']['pages']['url_1']['reason'] ?? 'missing' ) );

echo "\n== Full Undo of the core update ==\n";
$undo = ( new SnapshotService() )->undo_update( (int) $snap['id'], 'full' );
wp_cache_flush();
wpc_check( 'undo finished, core folders + files reverted', ! is_wp_error( $undo ) && $undo['files_restored'] >= 3, is_wp_error( $undo ) ? $undo->get_error_message() : $undo['files_restored'] . ' items' );
wpc_check( "files back to WordPress {$from}", FileArchive::core_version() === $from, FileArchive::core_version() );
wpc_check( 'database back too (db_version)', (int) get_option( 'db_version' ) === $db_before, (string) get_option( 'db_version' ) );
wpc_check( "WordPress's update lock not restored with the snapshot", false === get_option( 'core_updater.lock' ) );
wpc_check( 'wp-config.php untouched', is_file( ABSPATH . 'wp-config.php' ) && false !== strpos( (string) file_get_contents( ABSPATH . 'wp-config.php' ), 'DB_NAME' ) );
wpc_check( 'no staging leftovers in the site root', ! glob( ABSPATH . '.wpc-new-*' ) && ! glob( ABSPATH . '.wpc-old-*' ) ); // No GLOB_BRACE on musl.
$probe = SafeUpdates::probe();
wpc_check( 'site loads after the undo', true === $probe['front']['ok'], $probe['front']['reason'] );

echo "\n== Core update that breaks a plugin → Safe Updates undoes it ==\n";
$picky = wpc_install_plugin( 'wpc-core-picky', '1.0', "if ( version_compare( \$GLOBALS['wp_version'], '7.0', '>=' ) ) { wpc_core_picky_needs_old_wordpress(); }" );
activate_plugin( $picky );
$result = wpc_core_update( $to );
$snap   = wpc_last_auto();
$su     = $snap['meta']['safe_update'] ?? array();
wpc_check( 'fatal after the core update detected → undone', 'undone' === ( $su['status'] ?? '' ), ( $su['status'] ?? '?' ) . ': ' . substr( (string) ( $su['message'] ?? '' ), 0, 160 ) );
wpc_check( 'the error points at the plugin that broke', false !== strpos( (string) ( $su['error']['file'] ?? '' ), 'plugins/wpc-core-picky/' ) );
wpc_check( "message names WordPress {$to}", false !== strpos( (string) ( $su['message'] ?? '' ), "WordPress {$to}" ) );
wpc_check( "files back to WordPress {$from}", FileArchive::core_version() === $from, FileArchive::core_version() );
wpc_check( 'database back too (db_version)', (int) get_option( 'db_version' ) === $db_before );
wpc_check( 'site loads again', true === ( $su['recheck']['front']['ok'] ?? null ) );
wpc_check( "WordPress {$to} blocked from auto-updates", false === apply_filters( 'auto_update_core', true, (object) array( 'current' => $to ) ) && true === apply_filters( 'auto_update_core', true, (object) array( 'current' => '7.1.4' ) ) );

echo "\n== Cleanup ==\n";
deactivate_plugins( $picky );
delete_plugins( array( $picky ) );
delete_option( SafeUpdates::STATE );
delete_transient( SnapshotService::AUTO_LOCK );
Settings::update( $settings_before );
wpc_check( "dev site still on WordPress {$from}, fixture removed", FileArchive::core_version() === $from && ! is_dir( WP_PLUGIN_DIR . '/wpc-core-picky' ) );

echo "\n" . ( $GLOBALS['wpc_fails'] ? "{$GLOBALS['wpc_fails']} check(s) FAILED ({$GLOBALS['wpc_passes']} passed)" : "All checks passed ({$GLOBALS['wpc_passes']} passed)" ) . "\n";
