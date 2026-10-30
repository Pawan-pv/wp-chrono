<?php
/**
 * Shared fixtures for tests that drive WordPress's real upgraders offline.
 *
 * Packages are built with ZipArchive into a temp dir and served through
 * `upgrader_pre_download`, so Plugin_Upgrader / Theme_Upgrader run their real
 * code paths (maintenance mode, temp backups, upgrader_pre_install, …) with
 * no network access.
 */

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

if ( ! class_exists( 'WPC_Quiet_Skin' ) ) {
	/** Silent skin: collects feedback instead of echoing HTML and flushing buffers. */
	class WPC_Quiet_Skin extends Automatic_Upgrader_Skin {
		public $plugin_info   = array();
		public $plugin_active = false;
		public $theme_info    = null;
	}
}

// Offline stack: skip the post-update calls to api.wordpress.org (they only warn).
remove_action( 'upgrader_process_complete', 'wp_version_check' );
remove_action( 'upgrader_process_complete', 'wp_update_plugins' );
remove_action( 'upgrader_process_complete', 'wp_update_themes' );
remove_action( 'upgrader_process_complete', array( 'Language_Pack_Upgrader', 'async_upgrade' ), 20 );

$GLOBALS['wpc_fails']  = $GLOBALS['wpc_fails'] ?? 0;
$GLOBALS['wpc_passes'] = $GLOBALS['wpc_passes'] ?? 0;

if ( ! function_exists( 'wpc_check' ) ) {
	function wpc_check( string $label, bool $ok, string $extra = '' ): void {
		echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) . "\n";
		$ok ? $GLOBALS['wpc_passes']++ : $GLOBALS['wpc_fails']++;
	}
}

function wpc_pkg_dir(): string {
	$dir = get_temp_dir() . 'wpc-packages/';
	wp_mkdir_p( $dir );
	return $dir;
}

/**
 * Plugin main-file source. `$body` is appended after the header.
 */
function wpc_plugin_php( string $slug, string $version, string $body = '' ): string {
	$name = ucwords( str_replace( '-', ' ', $slug ) );
	return "<?php\n/**\n * Plugin Name: {$name}\n * Version: {$version}\n * Description: WP Chrono test fixture.\n */\n{$body}\n";
}

/**
 * Writes a plugin folder (or a single-file plugin when $slug ends in .php).
 *
 * @return string Plugin basename, e.g. "slug/slug.php".
 */
function wpc_install_plugin( string $slug, string $version, string $body = '' ): string {
	if ( '.php' === substr( $slug, -4 ) ) {
		$prev = wpc_mtime( WP_PLUGIN_DIR . '/' . $slug );
		file_put_contents( WP_PLUGIN_DIR . '/' . $slug, wpc_plugin_php( basename( $slug, '.php' ), $version, $body ) );
		wpc_backdate( $prev, WP_PLUGIN_DIR . '/' . $slug );
		return $slug;
	}
	$prev = wpc_mtime( WP_PLUGIN_DIR . "/{$slug}/{$slug}.php" );
	wp_mkdir_p( WP_PLUGIN_DIR . "/{$slug}" );
	file_put_contents( WP_PLUGIN_DIR . "/{$slug}/{$slug}.php", wpc_plugin_php( $slug, $version, $body ) );
	file_put_contents( WP_PLUGIN_DIR . "/{$slug}/readme.txt", "=== {$slug} ===\nStable tag: {$version}\n" );
	wp_mkdir_p( WP_PLUGIN_DIR . "/{$slug}/includes" );
	file_put_contents( WP_PLUGIN_DIR . "/{$slug}/includes/version.txt", $version );
	wpc_backdate( $prev, WP_PLUGIN_DIR . "/{$slug}/{$slug}.php", WP_PLUGIN_DIR . "/{$slug}/readme.txt", WP_PLUGIN_DIR . "/{$slug}/includes/version.txt" );
	return "{$slug}/{$slug}.php";
}

function wpc_mtime( string $file ): int {
	clearstatcache( true, $file );
	return file_exists( $file ) ? (int) filemtime( $file ) : 0;
}

/**
 * Gives hand-installed files an mtime in the past that always differs from the
 * copy they replace, as on a real site where the installed version predates
 * the update. OPcache revalidates by whole-second mtime: an update (or
 * reinstall) written in the same second as the cached copy would otherwise
 * never be seen by PHP-FPM, and on a fast machine (CI) the probe judged the
 * old code.
 */
function wpc_backdate( int $prev, string ...$files ): void {
	$t = time() - 600;
	if ( $prev > 0 && $prev <= $t ) {
		$t = $prev - 1;
	}
	foreach ( $files as $f ) {
		touch( $f, $t );
	}
	clearstatcache();
}

/**
 * Builds a plugin zip package (folder layout or single file).
 */
function wpc_plugin_zip( string $slug, string $version, string $body = '', string $path = '' ): string {
	$path = '' !== $path ? $path : wpc_pkg_dir() . basename( $slug, '.php' ) . "-{$version}.zip";
	$zip  = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	if ( '.php' === substr( $slug, -4 ) ) {
		$zip->addFromString( $slug, wpc_plugin_php( basename( $slug, '.php' ), $version, $body ) );
	} else {
		$zip->addFromString( "{$slug}/{$slug}.php", wpc_plugin_php( $slug, $version, $body ) );
		$zip->addFromString( "{$slug}/readme.txt", "=== {$slug} ===\nStable tag: {$version}\n" );
		$zip->addFromString( "{$slug}/includes/version.txt", $version );
		$zip->addFromString( "{$slug}/includes/new-in-{$version}.txt", 'only in this version' );
	}
	$zip->close();
	return $path;
}

function wpc_install_theme( string $slug, string $version, string $functions = '' ): void {
	$dir = get_theme_root() . "/{$slug}";
	wp_mkdir_p( $dir );
	file_put_contents( "{$dir}/style.css", "/*\nTheme Name: " . ucwords( str_replace( '-', ' ', $slug ) ) . "\nVersion: {$version}\n*/\n" );
	file_put_contents( "{$dir}/index.php", "<?php\n" );
	file_put_contents( "{$dir}/functions.php", "<?php\n{$functions}\n" );
}

function wpc_theme_zip( string $slug, string $version ): string {
	$path = wpc_pkg_dir() . "{$slug}-{$version}.zip";
	$zip  = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( "{$slug}/style.css", "/*\nTheme Name: " . ucwords( str_replace( '-', ' ', $slug ) ) . "\nVersion: {$version}\n*/\n" );
	$zip->addFromString( "{$slug}/index.php", "<?php\n" );
	$zip->addFromString( "{$slug}/functions.php", "<?php\n" );
	$zip->close();
	return $path;
}

/** Version read straight from disk (no plugin/theme caches). */
function wpc_disk_version( string $file ): string {
	$path = is_dir( get_theme_root() . "/{$file}" ) ? get_theme_root() . "/{$file}/style.css" : WP_PLUGIN_DIR . "/{$file}";
	clearstatcache();
	return is_file( $path ) ? (string) get_file_data( $path, array( 'Version' => 'Version' ) )['Version'] : '';
}

/**
 * Serves local zips for fake https://packages.invalid/{name} URLs.
 * WordPress deletes downloads after unpacking, so it gets a copy.
 */
function wpc_serve_packages(): void {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	add_filter(
		'upgrader_pre_download',
		static function ( $reply, $package ) {
			if ( ! preg_match( '#^https://packages\.invalid/(.+\.zip)$#', $package, $m ) ) {
				return $reply;
			}
			$copy = wp_tempnam( $m[1] );
			copy( wpc_pkg_dir() . $m[1], $copy );
			return $copy;
		},
		10,
		2
	);
}

/**
 * Real Plugin_Upgrader::bulk_upgrade() of plugin basenames to the versions
 * whose zips were built with wpc_plugin_zip().
 *
 * @param array<string,string> $updates basename => new version
 * @return array<string,mixed> Upgrader results keyed by basename.
 */
function wpc_bulk_upgrade_plugins( array $updates ): array {
	wpc_serve_packages();
	$transient               = new stdClass();
	$transient->last_checked = time();
	$transient->checked      = array();
	$transient->response     = array();
	foreach ( $updates as $file => $version ) {
		$slug                         = '.' === dirname( $file ) ? basename( $file, '.php' ) : dirname( $file );
		$transient->checked[ $file ]  = wpc_disk_version( $file );
		$transient->response[ $file ] = (object) array(
			'slug'        => $slug,
			'plugin'      => $file,
			'new_version' => $version,
			'package'     => "https://packages.invalid/{$slug}-{$version}.zip",
			'url'         => "https://packages.invalid/{$slug}",
		);
	}
	set_site_transient( 'update_plugins', $transient );
	$upgrader = new Plugin_Upgrader( new WPC_Quiet_Skin() );
	$results  = (array) $upgrader->bulk_upgrade( array_keys( $updates ) );
	delete_site_transient( 'update_plugins' );
	wp_clean_plugins_cache();
	return $results;
}

/**
 * Real Theme_Upgrader::bulk_upgrade().
 *
 * @param array<string,string> $updates slug => new version
 */
function wpc_bulk_upgrade_themes( array $updates ): array {
	wpc_serve_packages();
	$transient               = new stdClass();
	$transient->last_checked = time();
	$transient->checked      = array();
	$transient->response     = array();
	foreach ( $updates as $slug => $version ) {
		$transient->checked[ $slug ]  = wpc_disk_version( $slug );
		$transient->response[ $slug ] = array(
			'theme'       => $slug,
			'new_version' => $version,
			'package'     => "https://packages.invalid/{$slug}-{$version}.zip",
			'url'         => "https://packages.invalid/{$slug}",
		);
	}
	set_site_transient( 'update_themes', $transient );
	$upgrader = new Theme_Upgrader( new WPC_Quiet_Skin() );
	$results  = (array) $upgrader->bulk_upgrade( array_keys( $updates ) );
	delete_site_transient( 'update_themes' );
	wp_clean_themes_cache();
	return $results;
}

function wpc_upgrade_ok( array $results, array $keys ): bool {
	foreach ( $keys as $key ) {
		if ( ! isset( $results[ $key ] ) || is_wp_error( $results[ $key ] ) || false === $results[ $key ] || null === $results[ $key ] ) {
			return false;
		}
	}
	return true;
}

function wpc_summary(): void {
	echo "\n" . ( $GLOBALS['wpc_fails'] ? "{$GLOBALS['wpc_fails']} check(s) FAILED ({$GLOBALS['wpc_passes']} passed)" : "All checks passed ({$GLOBALS['wpc_passes']} passed)" ) . "\n";
}
