<?php
/**
 * Milestone 1: full update rollback (files + database), via WordPress's real
 * Plugin_Upgrader / Theme_Upgrader, offline.
 *
 *   docker compose exec wp wp eval-file /tests/rollback-checks.php --user=chrono
 */

use WPChrono\Core\Storage;
use WPChrono\Services\Settings;
use WPChrono\Services\Snapshot\FileArchive;
use WPChrono\Services\SnapshotService;

require '/tests/lib/upgrade-helpers.php';

$service = new SnapshotService();

/** Newest auto-snapshot created after $after. */
function wpc_new_autos( SnapshotService $service, int $after ): array {
	return array_values(
		array_filter(
			$service->all(),
			static function ( $s ) use ( $after ) {
				return $s['id'] > $after && 'auto' === $s['trigger_type'];
			}
		)
	);
}

function wpc_max_id( SnapshotService $service ): int {
	return (int) max( array_merge( array( 0 ), array_column( $service->all(), 'id' ) ) );
}

/** Upgrades and returns the batch's auto-snapshot. */
function wpc_upgrade_batch( SnapshotService $service, array $plugins, array $themes = array() ): ?array {
	delete_transient( SnapshotService::AUTO_LOCK );
	$before  = wpc_max_id( $service );
	$results = $plugins ? wpc_bulk_upgrade_plugins( $plugins ) : array();
	if ( $themes ) {
		$results += wpc_bulk_upgrade_themes( $themes );
	}
	$GLOBALS['wpc_last_results'] = $results;
	$new = wpc_new_autos( $service, $before );
	return $new ? $service->get( $new[0]['id'] ) : null;
}

function wpc_leftovers(): array {
	return array_merge(
		glob( WP_PLUGIN_DIR . '/.wpc-*' ) ?: array(),
		glob( get_theme_root() . '/.wpc-*' ) ?: array(),
		glob( WP_CONTENT_DIR . '/upgrade/wpc-rollback-*' ) ?: array()
	);
}

Settings::update( array( 'auto_snapshot' => true, 'auto_retention' => 10 ) );

// ---------------------------------------------------------------------------
echo "== Fixtures ==\n";
$a      = wpc_install_plugin( 'wpc-roll-a', '1.0' );
$b      = wpc_install_plugin( 'wpc-roll-b', '1.0' );
$single = wpc_install_plugin( 'wpc-roll-single.php', '1.0' );
wpc_install_theme( 'wpc-roll-theme', '1.0' );
foreach ( array( '2.0', '3.0', '4.0' ) as $v ) {
	wpc_plugin_zip( 'wpc-roll-a', $v );
	wpc_plugin_zip( 'wpc-roll-b', $v );
	wpc_plugin_zip( 'wpc-roll-single.php', $v );
}
wpc_theme_zip( 'wpc-roll-theme', '2.0' );
wp_clean_plugins_cache();
activate_plugins( array( $a, $b, $single ) );
update_option( 'wpc_roll_marker', 'v1' );
wpc_check( 'v1.0 plugins installed and active', '1.0' === wpc_disk_version( $a ) && is_plugin_active( $b ) && is_plugin_active( $single ) );

// ---------------------------------------------------------------------------
echo "\n== Bulk update of 2 plugins archives both into ONE pre-update snapshot ==\n";
$snap = wpc_upgrade_batch( $service, array( $a => '2.0', $b => '2.0' ) );
wpc_check( 'bulk_upgrade() succeeded', wpc_upgrade_ok( $GLOBALS['wpc_last_results'], array( $a, $b ) ) );
wpc_check( 'both plugins now v2.0 on disk', '2.0' === wpc_disk_version( $a ) && '2.0' === wpc_disk_version( $b ) );
$files = $snap ? SnapshotService::rollback_files( $snap ) : array();
wpc_check( 'exactly 1 auto-snapshot with 2 file archives', $snap && 2 === count( $files ), $snap ? count( $files ) . " archives on #{$snap['id']}" : 'no snapshot' );

$storage = Storage::ensure();
$byslug  = array_column( $files, null, 'slug' );
$entry   = $byslug['wpc-roll-a'] ?? array();
wpc_check(
	'archive named snap-{id}-plugin-{slug}-{old_version}-{random}.zip in sealed storage',
	$entry && preg_match( '/^snap-' . $snap['id'] . '-plugin-wpc-roll-a-1\.0-[a-z0-9]{16}\.zip$/', $entry['zip'] ) && is_file( $storage . 'rollbacks/' . $entry['zip'] ),
	$entry['zip'] ?? ''
);
wpc_check(
	'manifest records type, slug, old/new version, sha256',
	$entry && 'plugin' === $entry['type'] && '1.0' === $entry['old_version'] && '2.0' === $entry['new_version'] && hash_file( 'sha256', $storage . 'rollbacks/' . $entry['zip'] ) === $entry['sha256'],
	sprintf( '%s %s → %s', $entry['slug'] ?? '?', $entry['old_version'] ?? '?', $entry['new_version'] ?? '?' )
);
$zip = new ZipArchive();
$zip->open( $storage . 'rollbacks/' . $entry['zip'] );
wpc_check( 'archive holds the v1.0 files (not the new ones)', false !== strpos( (string) $zip->getFromName( 'wpc-roll-a/wpc-roll-a.php' ), 'Version: 1.0' ) && false === $zip->locateName( 'wpc-roll-a/includes/new-in-2.0.txt' ) );
$zip->close();

// ---------------------------------------------------------------------------
echo "\n== Mode: database only ==\n";
update_option( 'wpc_roll_marker', 'v2' ); // What v2.0's migration would do.
$r = $service->undo_update( $snap['id'], 'db' );
wp_cache_flush();
wpc_check( 'db-only restore finished', ! is_wp_error( $r ) && $r['done'] && 0 === $r['files_restored'] );
wpc_check( 'database back to v1', 'v1' === get_option( 'wpc_roll_marker' ) );
wpc_check( 'files untouched (still v2.0)', '2.0' === wpc_disk_version( $a ) );

// ---------------------------------------------------------------------------
echo "\n== Mode: files only ==\n";
update_option( 'wpc_roll_marker', 'v2' );
$r = $service->undo_update( $snap['id'], 'files' );
wp_cache_flush();
wpc_check( 'files-only revert finished', ! is_wp_error( $r ) && 2 === $r['files_restored'], is_wp_error( $r ) ? $r->get_error_message() : implode( ' ', $r['log'] ) );
wpc_check( 'both plugin folders back to v1.0', '1.0' === wpc_disk_version( $a ) && '1.0' === wpc_disk_version( $b ) );
wpc_check( 'files added by v2.0 are gone (whole folder swapped)', ! file_exists( WP_PLUGIN_DIR . '/wpc-roll-a/includes/new-in-2.0.txt' ) );
wpc_check( 'database untouched (still v2)', 'v2' === get_option( 'wpc_roll_marker' ) );

// ---------------------------------------------------------------------------
echo "\n== Mode: full undo (files + database), incl. single-file plugin and a theme ==\n";
update_option( 'wpc_roll_marker', 'v1' );
$snap2 = wpc_upgrade_batch( $service, array( $a => '3.0', $b => '3.0', $single => '3.0' ), array( 'wpc-roll-theme' => '2.0' ) );
wpc_check( 'folder plugins + theme updated in place', '3.0' === wpc_disk_version( $a ) && '3.0' === wpc_disk_version( $b ) && '2.0' === wpc_disk_version( 'wpc-roll-theme' ) );
// WordPress can't update a single-file plugin in place: it deletes the old
// file and installs the package into a new folder. The archive still has it.
wpc_check( 'WordPress removed the single-file plugin during its update', '' === wpc_disk_version( $single ) );
$files2 = $snap2 ? SnapshotService::rollback_files( $snap2 ) : array();
$types  = array_count_values( array_column( $files2, 'type' ) );
wpc_check( '1 snapshot carries 3 plugin + 1 theme archives', 3 === ( $types['plugin'] ?? 0 ) && 1 === ( $types['theme'] ?? 0 ), wp_json_encode( $types ) );
update_option( 'wpc_roll_marker', 'v3' );

$r = $service->undo_update( $snap2['id'], 'full' );
wp_cache_flush();
wpc_check( 'full undo finished', ! is_wp_error( $r ) && $r['done'] && 4 === $r['files_restored'], is_wp_error( $r ) ? $r->get_error_message() : "{$r['files_restored']} folders, swap {$r['swap_ms']} ms" );
wpc_check( 'plugin files back to v1.0', '1.0' === wpc_disk_version( $a ) && '1.0' === wpc_disk_version( $b ) );
wpc_check( 'single-file plugin restored at its original path, v1.0', '1.0' === wpc_disk_version( $single ) );
$single_entry = array_values( array_filter( $files2, static function ( $e ) { return 'wpc-roll-single.php' === $e['slug']; } ) )[0] ?? array();
wpc_check( 'manifest flagged it is_single_file and recorded where the update went', ! empty( $single_entry['is_single_file'] ) && false !== strpos( (string) ( $single_entry['installed_to'] ?? '' ), 'wpc-roll-single-3.0-' ), basename( (string) ( $single_entry['installed_to'] ?? '?' ) ) );
wpc_check( 'the folder WordPress moved the update into was removed', ! glob( WP_PLUGIN_DIR . '/wpc-roll-single-3.0-*' ) );
wp_clean_plugins_cache();
$dupes = array_filter( get_plugins(), static function ( $p ) { return 'Wpc Roll Single' === $p['Name']; } );
wpc_check( 'no duplicate plugin left in get_plugins()', 1 === count( $dupes ), implode( ', ', array_keys( $dupes ) ) );
wpc_check( 'undo log mentions the cleanup', (bool) preg_grep( '/^Removed wpc-roll-single-3\.0-/', (array) $r['log'] ) );
wpc_check( 'theme back to v1.0', '1.0' === wpc_disk_version( 'wpc-roll-theme' ) );
wpc_check( 'database back to v1 in the same operation', 'v1' === get_option( 'wpc_roll_marker' ) );
wpc_check( 'plugins still active after undo', is_plugin_active( $a ) && is_plugin_active( $single ) );
wpc_check( 'no staging leftovers (.wpc-new / .wpc-old / upgrade dirs)', ! wpc_leftovers(), implode( ', ', array_map( 'basename', wpc_leftovers() ) ) );

// ---------------------------------------------------------------------------
echo "\n== Tampered archive: refused, nothing changed ==\n";
$snap3 = wpc_upgrade_batch( $service, array( $a => '4.0', $b => '4.0' ) );
update_option( 'wpc_roll_marker', 'v4' );
$files3 = SnapshotService::rollback_files( $snap3 );
file_put_contents( FileArchive::path( $files3[1] ), 'x', FILE_APPEND );
$r = $service->undo_update( $snap3['id'], 'full' );
wp_cache_flush();
wpc_check( 'checksum mismatch rejected', is_wp_error( $r ) && 'wp_chrono_rollback_checksum' === $r->get_error_code(), is_wp_error( $r ) ? $r->get_error_message() : 'no error' );
wpc_check( 'live files untouched (both still v4.0)', '4.0' === wpc_disk_version( $a ) && '4.0' === wpc_disk_version( $b ) );
wpc_check( 'database untouched (still v4)', 'v4' === get_option( 'wpc_roll_marker' ) );
wpc_check( 'the intact archive staged first was cleaned up too', ! wpc_leftovers() );

// ---------------------------------------------------------------------------
echo "\n== Deleting / pruning a snapshot deletes its archives ==\n";
$paths = array_filter( array_map( array( FileArchive::class, 'path' ), $files2 ) );
wpc_check( 'archives exist before delete', 4 === count( $paths ) );
$service->delete( $snap2['id'] );
wpc_check( 'archives removed with the snapshot', ! array_filter( $paths, 'file_exists' ) );

Settings::update( array( 'auto_retention' => 1 ) );
$service->prune_auto();
$left = array_filter( array_map( array( FileArchive::class, 'path' ), SnapshotService::rollback_files( $snap ) ) );
wpc_check( 'retention pruning removes archives of pruned auto-snapshots', ! $left );
Settings::update( array( 'auto_retention' => 5 ) );

// ---------------------------------------------------------------------------
echo "\n== Self-protection ==\n";
$targets = FileArchive::targets( array( 'plugin' => WP_CHRONO_BASENAME ) );
wpc_check( 'WP Chrono never archives / rolls back itself', ! $targets );
$targets = FileArchive::targets( array( 'plugin' => '../../wp-config.php', 'theme' => '../x' ) );
wpc_check( 'path traversal in hook_extra ignored', ! $targets );

// Cleanup.
deactivate_plugins( array( $a, $b, $single ) );
delete_plugins( array( $a, $b, $single ) );
FileArchive::rmtree( get_theme_root() . '/wpc-roll-theme' );
delete_option( 'wpc_roll_marker' );
delete_transient( SnapshotService::AUTO_LOCK );

wpc_summary();
