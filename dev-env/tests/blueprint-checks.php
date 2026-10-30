<?php
/**
 * Milestone 3.1: Blueprints. Builds a blueprint from local plugin / theme
 * zips (no wordpress.org needed) and runs "Reset to Dev Baseline" end-to-end
 * through the REST API, in the same order the SPA does.
 *
 *   docker compose exec wp wp eval-file /tests/blueprint-checks.php --user=chrono
 *
 * Prints BLUEPRINT=<id> (kept for the HTTP upload test in blueprint-checks.sh).
 */

use WPChrono\API\RestController;
use WPChrono\Services\BlueprintService;
use WPChrono\Services\Snapshot\FileArchive;

require '/tests/lib/upgrade-helpers.php';

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
function wpc_item( array $bp, string $kind, string $slug ): ?array {
	foreach ( (array) $bp[ $kind ] as $item ) {
		if ( $item['slug'] === $slug ) {
			return $item;
		}
	}
	return null;
}

global $wpdb;
$service    = new BlueprintService();
$orig_theme = get_stylesheet();
$orig_opts  = array();
foreach ( array( 'timezone_string', 'permalink_structure', 'posts_per_page' ) as $o ) {
	$orig_opts[ $o ] = get_option( $o );
}

// ---------------------------------------------------------------------------
echo "== Fixtures: local plugin / theme zips (offline) ==\n";
$alpha = wpc_plugin_zip( 'wpc-bp-alpha', '1.2', "register_activation_hook( __FILE__, function () { update_option( 'wpc_bp_alpha_activated', 'yes' ); } );" );
$beta  = wpc_plugin_zip( 'wpc-bp-beta', '3.4' );
$theme = wpc_theme_zip( 'wpc-bp-theme', '1.0' );
$junk  = wpc_pkg_dir() . 'not-a-plugin.zip';
$z     = new ZipArchive();
$z->open( $junk, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$z->addFromString( 'readme.txt', 'just text' );
$z->close();
wpc_plugin_zip( 'wpc-bp-gamma', '0.9', '', wpc_pkg_dir() . 'wpc-bp-gamma-0.9.zip' ); // for the HTTP upload test
wpc_check( 'zips built locally with ZipArchive', is_file( $alpha ) && is_file( $beta ) && is_file( $theme ) );

update_option( 'timezone_string', 'Europe/Berlin' );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'posts_per_page', 7 );

// ---------------------------------------------------------------------------
echo "\n== Capture the current site as a blueprint ==\n";
$r  = wpc_rest( 'POST', 'blueprints', array( 'name' => 'Agency baseline', 'description' => 'Test', 'bundle' => true ) );
$bp = $r->get_data();
$id = (string) ( $bp['id'] ?? '' );
$installed = count( get_plugins() ) - 1; // minus WP Chrono
wpc_check( 'POST /blueprints captured the site', 201 === $r->get_status() && $bp['plugin_count'] === $installed && $bp['theme_count'] >= 1, 201 === $r->get_status() ? "{$bp['plugin_count']} plugins, {$bp['theme_count']} theme(s), {$bp['option_count']} settings" : wpc_err( $r ) );
wpc_check( 'baseline settings captured', 'Europe/Berlin' === ( $bp['options']['timezone_string'] ?? '' ) && '/%postname%/' === ( $bp['options']['permalink_structure'] ?? '' ) );
wpc_check( 'every plugin / theme bundled as a local zip', ! array_filter( array_merge( $bp['plugins'], $bp['themes'] ), static function ( $i ) { return empty( $i['has_package'] ); } ), size_format( (int) $bp['package_bytes'], 1 ) . ' of packages' );
wpc_check( 'WP Chrono itself is never part of a blueprint', ! wpc_item( $bp, 'plugins', 'wp-chrono' ) );

foreach ( array( $alpha, $beta, $theme ) as $zip ) {
	$added = $service->add_package( $id, $zip );
}
$bp = BlueprintService::summary( $service->get( $id ) );
$a  = wpc_item( $bp, 'plugins', 'wpc-bp-alpha' );
$t  = wpc_item( $bp, 'themes', 'wpc-bp-theme' );
wpc_check( 'local plugin zips added (slug + version read from headers)', $a && '1.2' === $a['version'] && 'wpc-bp-alpha/wpc-bp-alpha.php' === $a['file'] && $a['has_package'] && wpc_item( $bp, 'plugins', 'wpc-bp-beta' ) );
wpc_check( 'uploaded theme becomes the blueprint\'s active theme', $t && $t['active'] && 1 === count( array_filter( $bp['themes'], static function ( $x ) { return $x['active']; } ) ) );
$bad = $service->add_package( $id, $junk );
wpc_check( 'zip without a plugin / theme header rejected', is_wp_error( $bad ), is_wp_error( $bad ) ? $bad->get_error_message() : '' );

// ---------------------------------------------------------------------------
echo "\n== Let the site drift away from the baseline ==\n";
$junk_file = wpc_install_plugin( 'wpc-bp-junk', '1.0' );
wp_clean_plugins_cache();
activate_plugin( $junk_file );
update_option( 'timezone_string', 'UTC' );
update_option( 'permalink_structure', '' );
update_option( 'posts_per_page', 10 );
wp_insert_post( array( 'post_title' => 'wpc-bp drift post', 'post_status' => 'publish' ) );
$admin    = get_user_by( 'login', 'chrono' );
$admin_pw = $admin->user_pass;
wpc_check( 'site drifted (junk plugin active, settings changed)', is_plugin_active( $junk_file ) && 'UTC' === get_option( 'timezone_string' ) );

// ---------------------------------------------------------------------------
echo "\n== Reset to Dev Baseline (REST, same sequence as the SPA) ==\n";
$r = wpc_rest( 'POST', "blueprints/{$id}/apply", array( 'step' => 0 ) );
wpc_check( 'apply without confirm: RESET rejected', 400 === $r->get_status() );

$pre  = wpc_rest( 'POST', 'reset/preflight', array( 'target' => 'full', 'snapshot' => true ) )->get_data();
$safe = (int) ( $pre['snapshot']['id'] ?? 0 );
for ( $i = 0; $i < 200 && $safe; $i++ ) {
	$b = wpc_rest( 'POST', "snapshots/{$safe}/build" )->get_data();
	if ( ! empty( $b['done'] ) ) {
		break;
	}
}
wpc_check( '1. safety snapshot of the drifted site', $safe > 0 && ! empty( $b['done'] ), "#{$safe}" );

$r = wpc_rest( 'POST', 'reset/full', array( 'confirm' => 'RESET', 'keep_theme' => false, 'keep_plugins' => false ) );
wpc_check( '2. full reset', 200 === $r->get_status(), 200 === $r->get_status() ? $r->get_data()['tables_dropped'] . ' tables dropped' : wpc_err( $r ) );

$step  = 0;
$steps = array();
for ( $i = 0; $i < 100; $i++ ) {
	$r = wpc_rest( 'POST', "blueprints/{$id}/apply", array( 'step' => $step, 'confirm' => 'RESET' ) );
	if ( 200 !== $r->get_status() ) {
		break;
	}
	$d       = $r->get_data();
	$steps[] = ( $d['ok'] ? 'ok' : 'FAILED' ) . ': ' . $d['label'] . ' — ' . implode( ' ', $d['log'] );
	if ( $d['done'] ) {
		break;
	}
	$step = (int) $d['next'];
}
$failed = array_filter( $steps, static function ( $s ) { return 0 === strpos( $s, 'FAILED' ); } );
wpc_check( '3. every blueprint step succeeded', ! empty( $d['done'] ) && ! $failed, count( $steps ) . ' steps' . ( $failed ? ': ' . implode( ' | ', $failed ) : '' ) );
foreach ( $steps as $s ) {
	echo "        {$s}\n";
}

wp_cache_flush();
wp_clean_plugins_cache();
wpc_check( 'plugins from local zips installed at the pinned versions', '1.2' === wpc_disk_version( 'wpc-bp-alpha/wpc-bp-alpha.php' ) && '3.4' === wpc_disk_version( 'wpc-bp-beta/wpc-bp-beta.php' ) );
wpc_check( 'and active (activation hooks ran)', is_plugin_active( 'wpc-bp-alpha/wpc-bp-alpha.php' ) && is_plugin_active( 'wpc-bp-beta/wpc-bp-beta.php' ) && 'yes' === get_option( 'wpc_bp_alpha_activated' ) );
wpc_check( 'blueprint theme installed and active', 'wpc-bp-theme' === get_stylesheet(), get_stylesheet() );
wpc_check( 'plugin not in the blueprint removed from disk', ! file_exists( WP_PLUGIN_DIR . '/wpc-bp-junk' ) );
wpc_check( 'baseline settings applied', 'Europe/Berlin' === get_option( 'timezone_string' ) && '/%postname%/' === get_option( 'permalink_structure' ) && 7 === (int) get_option( 'posts_per_page' ) );
wpc_check( 'content wiped by the reset', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = %s", 'wpc-bp drift post' ) ) );
$admin = get_user_by( 'login', 'chrono' );
wpc_check( 'admin kept: same ID and password hash', $admin && 2 === (int) $admin->ID && $admin_pw === $admin->user_pass );
wpc_check( 'WP Chrono still active, blueprint survived the reset', is_plugin_active( WP_CHRONO_BASENAME ) && ! is_wp_error( $service->get( $id ) ) );

// ---------------------------------------------------------------------------
echo "\n== Export / import (portable JSON) ==\n";
$plain = wpc_rest( 'GET', "blueprints/{$id}/export" );
$doc   = $plain->get_data();
$json  = (string) wp_json_encode( $doc );
wpc_check( 'GET /blueprints/{id}/export returns a portable document', 200 === $plain->get_status() && 1 === $doc['wpchrono_blueprint'] && false === strpos( $json, 'package_data' ) && false !== strpos( (string) $plain->get_headers()['Content-Disposition'], '.wpchrono-blueprint.json' ), size_format( strlen( $json ) ) );
$embed = wpc_rest( 'GET', "blueprints/{$id}/export", array( 'embed' => true ) )->get_data();
$ea    = null;
foreach ( $embed['plugins'] as $p ) {
	$ea = 'wpc-bp-alpha' === $p['slug'] ? $p : $ea;
}
wpc_check( 'embed=1 carries the zips (base64 + sha256)', $ea && ! empty( $ea['package_data'] ) && hash( 'sha256', base64_decode( $ea['package_data'] ) ) === $ea['sha256'] );

$imp = wpc_rest( 'POST', 'blueprints/import', array( 'blueprint' => wp_json_encode( $embed ) ) );
$new = $imp->get_data();
wpc_check( 'import of the embedded export restores packages', 201 === $imp->get_status() && ( wpc_item( $new, 'plugins', 'wpc-bp-alpha' )['has_package'] ?? false ) && $new['option_count'] === count( (array) $embed['options'] ), wpc_err( $imp ) );
$ea['package_data']  = base64_encode( 'tampered' . base64_decode( $ea['package_data'] ) );
$tampered            = $embed;
$tampered['plugins'] = array( $ea );
$r                   = wpc_rest( 'POST', 'blueprints/import', array( 'blueprint' => $tampered ) );
wpc_check( 'tampered embedded package rejected (sha256)', 400 === $r->get_status(), wpc_err( $r ) );
$r = wpc_rest( 'POST', 'blueprints/import', array( 'blueprint' => '{"hello":"world"}' ) );
wpc_check( 'non-blueprint JSON rejected', 400 === $r->get_status() );
$evil            = $doc;
$evil['plugins'] = array( array( 'slug' => 'x', 'file' => '../../wp-config.php' ) );
$r               = wpc_rest( 'POST', 'blueprints/import', array( 'blueprint' => $evil ) );
wpc_check( 'path traversal in an imported plugin file rejected', 400 === $r->get_status() );

$pkg = BlueprintService::package_path( (string) wpc_item( $service->get( $new['id'] ), 'plugins', 'wpc-bp-alpha' )['package'] );
wpc_rest( 'DELETE', "blueprints/{$new['id']}" );
wpc_check( 'deleting a blueprint removes packages only it used', '' !== $pkg && ! file_exists( $pkg ) && '' !== BlueprintService::package_path( (string) wpc_item( $service->get( $id ), 'plugins', 'wpc-bp-alpha' )['package'] ) );

// ---------------------------------------------------------------------------
echo "\n== wordpress.org slug installs (offline: intercepted API + downloads) ==\n";
$org_plugin = wpc_plugin_zip( 'wpc-org-demo', '1.0', '', wpc_pkg_dir() . 'org-wpc-org-demo.zip' );
$org_theme  = wpc_theme_zip( 'wpc-org-theme', '1.0' );
$served     = array();
$api        = static function ( $result, $action, $args ) {
	if ( in_array( $action, array( 'plugin_information', 'theme_information' ), true ) && in_array( $args->slug ?? '', array( 'wpc-org-demo', 'wpc-org-theme' ), true ) ) {
		$kind = 'plugin_information' === $action ? 'plugin' : 'theme';
		return (object) array( 'slug' => $args->slug, 'version' => '1.0', 'download_link' => "https://downloads.wordpress.org/{$kind}/{$args->slug}.1.0.zip" );
	}
	return $result;
};
add_filter( 'plugins_api', $api, 10, 3 );
add_filter( 'themes_api', $api, 10, 3 );
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( $org_plugin, $org_theme, &$served ) {
		if ( ! preg_match( '#^https://downloads\.wordpress\.org/(plugin|theme)/(wpc-org-[a-z]+)\.1\.0\.zip$#', $url, $m ) ) {
			return $pre;
		}
		$served[] = $url;
		$zip      = 'plugin' === $m[1] ? $org_plugin : $org_theme;
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			copy( $zip, $args['filename'] ); // download_url() streams to this file.
		}
		return array( 'headers' => array(), 'body' => empty( $args['stream'] ) ? file_get_contents( $zip ) : '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
	},
	10,
	3
);
$org = $service->import(
	array(
		'wpchrono_blueprint' => 1,
		'name'               => 'wordpress.org slugs',
		'remove_others'      => false,
		'plugins'            => array( array( 'slug' => 'wpc-org-demo', 'file' => 'wpc-org-demo/wpc-org-demo.php', 'name' => 'Wpc Org Demo', 'version' => '1.0', 'active' => true ) ),
		'themes'             => array( array( 'slug' => 'wpc-org-theme', 'name' => 'Wpc Org Theme', 'version' => '1.0', 'active' => false ) ),
		'options'            => array(),
	)
);
wpc_check( 'blueprint with wordpress.org slugs (no zips stored)', ! is_wp_error( $org ) && 'wporg' === $org['plugins'][0]['source'] && '' === $org['plugins'][0]['package'] );
$logs = array();
$ok   = true;
$step = 0;
do {
	$tick = $service->apply_step( $org['id'], $step );
	if ( is_wp_error( $tick ) ) {
		$ok = false;
		break;
	}
	$logs = array_merge( $logs, $tick['log'] );
	$ok   = $ok && $tick['ok'];
	$step = (int) $tick['next'];
} while ( ! $tick['done'] );
wp_clean_plugins_cache();
wpc_check( 'plugin downloaded from downloads.wordpress.org, installed and activated', $ok && '1.0' === wpc_disk_version( 'wpc-org-demo/wpc-org-demo.php' ) && is_plugin_active( 'wpc-org-demo/wpc-org-demo.php' ) && (bool) preg_grep( '/^Installed wpc-org-demo 1\.0 from wordpress\.org\./', $logs ), implode( ' ', $logs ) );
wpc_check( 'theme installed from wordpress.org the same way', wp_get_theme( 'wpc-org-theme' )->exists() && (bool) preg_grep( '/^Installed theme wpc-org-theme 1\.0 from wordpress\.org\./', $logs ) );
wpc_check( 'both packages came through the (intercepted) wordpress.org download URLs', 2 === count( $served ), implode( ', ', array_map( 'basename', $served ) ) );
remove_filter( 'plugins_api', $api, 10 );
remove_filter( 'themes_api', $api, 10 );
deactivate_plugins( 'wpc-org-demo/wpc-org-demo.php' );
delete_plugins( array( 'wpc-org-demo/wpc-org-demo.php' ) );
FileArchive::rmtree( get_theme_root() . '/wpc-org-theme' );
$service->delete( $org['id'] );

// Back to the original theme / settings so later suites see a normal site.
if ( wp_get_theme( $orig_theme )->exists() ) {
	switch_theme( $orig_theme );
}
foreach ( $orig_opts as $o => $v ) {
	update_option( $o, $v );
}
deactivate_plugins( array( 'wpc-bp-alpha/wpc-bp-alpha.php', 'wpc-bp-beta/wpc-bp-beta.php' ) );
delete_plugins( array( 'wpc-bp-alpha/wpc-bp-alpha.php', 'wpc-bp-beta/wpc-bp-beta.php' ) );
FileArchive::rmtree( get_theme_root() . '/wpc-bp-theme' );
delete_option( 'wpc_bp_alpha_activated' );

echo "BLUEPRINT={$id}\n";
wpc_summary();
