<?php
/**
 * Offline real-upgrader test: WordPress's own Plugin_Upgrader::bulk_upgrade()
 * updates two local dummy plugins from v1.0 to v2.0 using zip packages built
 * here with ZipArchive. `upgrader_pre_download` hands WordPress the local zip
 * instead of downloading, so no network is needed.
 *
 *   docker compose exec wp wp eval-file /tests/upgrader-checks.php --user=chrono
 */

use WPChrono\Services\Settings;
use WPChrono\Services\SnapshotService;

$GLOBALS['wpc_fails'] = 0;

function wpc_check( string $label, bool $ok, string $extra = '' ): void {
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) . "\n";
	if ( ! $ok ) {
		$GLOBALS['wpc_fails']++;
	}
}

function wpc_plugin_source( string $slug, string $version ): string {
	$name = ucwords( str_replace( '-', ' ', $slug ) );
	return "<?php\n/**\n * Plugin Name: {$name}\n * Version: {$version}\n * Description: WP Chrono upgrader test fixture.\n */\n";
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

/** Silent skin: collects feedback instead of echoing HTML and flushing buffers. */
class WPC_Quiet_Skin extends Automatic_Upgrader_Skin {
	public $plugin_info   = array();
	public $plugin_active = false;
}

$service = new SnapshotService();
$slugs   = array( 'wpc-dummy-a', 'wpc-dummy-b' );
$files   = array();

// ---------------------------------------------------------------------------
echo "== Fixtures: v1.0 installed, v2.0 zip packages ==\n";
$tmp = get_temp_dir() . 'wpc-upgrader/';
wp_mkdir_p( $tmp );
foreach ( $slugs as $slug ) {
	$file           = "{$slug}/{$slug}.php";
	$files[ $slug ] = $file;
	wp_mkdir_p( WP_PLUGIN_DIR . "/{$slug}" );
	file_put_contents( WP_PLUGIN_DIR . "/{$file}", wpc_plugin_source( $slug, '1.0' ) );

	$zip = new ZipArchive();
	$zip->open( "{$tmp}{$slug}-2.0.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( $file, wpc_plugin_source( $slug, '2.0' ) );
	$zip->addFromString( "{$slug}/readme.txt", "=== {$slug} ===\nStable tag: 2.0\n" );
	$zip->close();

	// Each plugin's own DB state, as a real plugin would keep it.
	update_option( str_replace( '-', '_', $slug ) . '_db_version', '1.0' );
}
wp_clean_plugins_cache();
activate_plugins( array_values( $files ) );
wpc_check( 'dummy plugins installed at v1.0 and active', '1.0' === get_plugin_data( WP_PLUGIN_DIR . '/' . $files['wpc-dummy-a'], false, false )['Version'] && is_plugin_active( $files['wpc-dummy-b'] ) );

// Advertise v2.0 in the update transient, pointing at fake package URLs.
$updates               = new stdClass();
$updates->last_checked = time();
$updates->checked      = array();
$updates->response     = array();
foreach ( $files as $slug => $file ) {
	$updates->checked[ $file ]  = '1.0';
	$updates->response[ $file ] = (object) array(
		'slug'        => $slug,
		'plugin'      => $file,
		'new_version' => '2.0',
		'package'     => "https://packages.invalid/{$slug}-2.0.zip",
		'url'         => "https://packages.invalid/{$slug}",
	);
}
set_site_transient( 'update_plugins', $updates );

// Serve packages from disk. WordPress deletes the file after unpacking, so hand it a copy.
add_filter(
	'upgrader_pre_download',
	static function ( $reply, $package ) use ( $tmp ) {
		if ( ! preg_match( '#^https://packages\.invalid/([a-z0-9.-]+\.zip)$#', $package, $m ) ) {
			return $reply;
		}
		$copy = wp_tempnam( $m[1] );
		copy( $tmp . $m[1], $copy );
		return $copy;
	},
	10,
	2
);

// Count how often WordPress fires the hook the auto-snapshot listens on.
$pre_install_calls = 0;
add_filter(
	'upgrader_pre_install',
	static function ( $response ) use ( &$pre_install_calls ) {
		$pre_install_calls++;
		return $response;
	},
	5
);

// ---------------------------------------------------------------------------
echo "\n== Real Plugin_Upgrader::bulk_upgrade() ==\n";
Settings::update( array( 'auto_snapshot' => true, 'auto_retention' => 5 ) );
delete_transient( SnapshotService::AUTO_LOCK );
$max_before = (int) max( array_merge( array( 0 ), array_column( $service->all(), 'id' ) ) );

$skin     = new WPC_Quiet_Skin();
$upgrader = new Plugin_Upgrader( $skin );
$results  = $upgrader->bulk_upgrade( array_values( $files ) );
$output   = implode( ' | ', array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) );

$ok = is_array( $results );
foreach ( $files as $file ) {
	$ok = $ok && isset( $results[ $file ] ) && ! is_wp_error( $results[ $file ] ) && false !== $results[ $file ];
}
wpc_check( 'bulk_upgrade() succeeded for both plugins', $ok, $ok ? '' : substr( $output, -400 ) );
wp_clean_plugins_cache();
foreach ( $files as $slug => $file ) {
	$version = get_plugin_data( WP_PLUGIN_DIR . "/{$file}", false, false )['Version'];
	wpc_check( "{$slug} upgraded to v2.0", '2.0' === $version, $version );
}
wpc_check( 'upgrader_pre_install fired once per plugin', 2 === $pre_install_calls, (string) $pre_install_calls );

$new = array_values(
	array_filter(
		$service->all(),
		static function ( $s ) use ( $max_before ) {
			return $s['id'] > $max_before && 'auto' === $s['trigger_type'];
		}
	)
);
wpc_check( 'exactly 1 batch auto-snapshot for 2 plugins', 1 === count( $new ), count( $new ) . ' created' );
$auto = $new[0] ?? null;
wpc_check( 'auto-snapshot ready, taken before the first package', $auto && 'ready' === $auto['status'] && false !== strpos( $auto['label'], 'wpc-dummy-a' ), $auto['label'] ?? '' );

// ---------------------------------------------------------------------------
echo "\n== Undo the update: files + database back to v1.0 ==\n";
// What v2.0's migration routine would do on its first load:
update_option( 'wpc_dummy_a_db_version', '2.0' );
update_option( 'wpc_dummy_b_db_version', '2.0' );

if ( $auto ) {
	$archives = SnapshotService::rollback_files( $service->get( $auto['id'] ) );
	wpc_check( 'both plugins\' v1.0 folders archived with the auto-snapshot', 2 === count( $archives ), count( $archives ) . ' archives' );
	$tick = $service->undo_update( $auto['id'], 'full' );
	wp_cache_flush();
	wpc_check( 'full undo (files + database) finished', ! is_wp_error( $tick ) && $tick['done'] && 2 === $tick['files_restored'] );
	wpc_check( 'plugin database state back to v1.0', '1.0' === get_option( 'wpc_dummy_a_db_version' ) && '1.0' === get_option( 'wpc_dummy_b_db_version' ) );
	wp_clean_plugins_cache();
	foreach ( $files as $slug => $file ) {
		$version = get_plugin_data( WP_PLUGIN_DIR . "/{$file}", false, false )['Version'];
		wpc_check( "{$slug} files back to v1.0", '1.0' === $version, $version );
	}
	wpc_check( 'both plugins still active after rollback', is_plugin_active( $files['wpc-dummy-a'] ) && is_plugin_active( $files['wpc-dummy-b'] ) );
}

delete_site_transient( 'update_plugins' );
echo "\n" . ( $GLOBALS['wpc_fails'] ? "{$GLOBALS['wpc_fails']} check(s) FAILED" : 'All checks passed' ) . "\n";
