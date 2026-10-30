<?php
/**
 * Uninstall: removes every table, option, transient, cron event and file
 * WP Chrono created, including this install's `_wpc_` (and legacy
 * `_wpchrono_snap_`) snapshot tables — never another install's in a shared
 * database. On multisite every site of the network is cleaned.
 *
 * @package WPChrono
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/Core/Schema.php';
require_once __DIR__ . '/includes/Core/Storage.php';
require_once __DIR__ . '/includes/Services/Database.php';

use WPChrono\Core\Schema;
use WPChrono\Core\Storage;
use WPChrono\Services\Database;

global $wpdb;

/**
 * Removes what belongs to the current site: its own tables, storage folder,
 * recovery script, options, transients and cron events.
 */
function wpchrono_uninstall_site(): void {
	global $wpdb;

	// The recovery script is a login page in the web root: never leave it behind.
	$wpchrono_recovery = get_option( 'wp_chrono_recovery' );
	if ( is_array( $wpchrono_recovery ) && preg_match( '/^wp-chrono-recovery-[a-z0-9]{16}\.php$/', (string) ( $wpchrono_recovery['file'] ?? '' ) ) && is_file( ABSPATH . $wpchrono_recovery['file'] ) ) {
		unlink( ABSPATH . $wpchrono_recovery['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	delete_option( 'wp_chrono_recovery' );
	wp_unschedule_hook( 'wp_chrono_scheduled_snapshot' );
	wp_unschedule_hook( 'wp_chrono_scheduled_snapshot_continue' );

	// Settings → "Keep snapshots if WP Chrono is deleted".
	$wpchrono_settings = get_option( 'wp_chrono_settings' );
	if ( is_array( $wpchrono_settings ) && ! empty( $wpchrono_settings['keep_data_on_uninstall'] ) ) {
		return;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	foreach ( wpchrono_owned_snapshot_tables() as $wpchrono_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpchrono_table ) );
	}

	foreach ( Schema::internal_tables() as $wpchrono_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpchrono_table ) );
	}

	Storage::destroy();

	// Through the API first, so a persistent object cache drops them too.
	delete_transient( 'wp_chrono_license_check' );
	delete_transient( '_wpchrono_auto_snap_lock' );

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( 'wp_chrono_' ) . '%',
			$wpdb->esc_like( '_transient_wp_chrono_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wp_chrono_' ) . '%',
			$wpdb->esc_like( '_transient__wpchrono_' ) . '%',
			$wpdb->esc_like( '_transient_timeout__wpchrono_' ) . '%'
		)
	);
	// phpcs:enable
}

/**
 * Snapshot copies that belong to the current site. `_wpc_` tables carry no
 * install ID in their name, and several WordPress installs may share one
 * database (one per table prefix), so a blanket `_wpc_%` sweep would delete
 * another install's backups. Owned = listed in this site's tracking table, or
 * tagged (table comment) with an original of this site, or a restore
 * staging / parked table whose embedded original carries this site's prefix.
 *
 * @return string[]
 */
function wpchrono_owned_snapshot_tables(): array {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$owned = array();
	$track = Schema::snapshot_tables_table();
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $track ) ) ) === $track ) {
		$owned = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT snapshot_table FROM %i', $track ) );
	}
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT TABLE_NAME AS name, TABLE_COMMENT AS comment FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND ( TABLE_NAME LIKE %s OR TABLE_NAME LIKE %s )',
			$wpdb->dbname,
			$wpdb->esc_like( Schema::SNAPSHOT_TABLE_PREFIX ) . '%',
			$wpdb->esc_like( Schema::LEGACY_SNAPSHOT_TABLE_PREFIX ) . '%'
		),
		ARRAY_A
	);
	// phpcs:enable
	$mine = array( Database::class, 'belongs_to_site' );
	foreach ( (array) $rows as $row ) {
		$name = (string) $row['name'];
		$meta = json_decode( (string) $row['comment'], true );
		if ( is_array( $meta ) && isset( $meta['orig'] ) ) {
			if ( $mine( (string) $meta['orig'] ) ) {
				$owned[] = $name;
			}
			continue;
		}
		// Staging / parked tables: `_wpc_{stg|old}_{job}_{md5(orig):8}_{orig}`.
		// Only untruncated names whose hash proves the embedded original.
		if ( preg_match( '/^' . preg_quote( Schema::SNAPSHOT_TABLE_PREFIX, '/' ) . '(?:stg|old)_[a-z0-9]+_([0-9a-f]{8})_(.+)$/', $name, $m )
			&& substr( md5( $m[2] ), 0, 8 ) === $m[1] && $mine( $m[2] ) ) {
			$owned[] = $name;
		}
	}
	return array_values( array_unique( $owned ) );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $wpchrono_site ) {
		switch_to_blog( (int) $wpchrono_site );
		wpchrono_uninstall_site();
		restore_current_blog();
	}
} else {
	wpchrono_uninstall_site();
}

// Update-check cache: wp_options on a single site, wp_sitemeta on multisite.
delete_site_transient( 'wp_chrono_update_info' );
