<?php
/**
 * Uninstall: removes every table, option, transient, cron event and file
 * WP Chrono created, including all `_wpc_` (and legacy `_wpchrono_snap_`)
 * snapshot tables. On multisite every site of the network is cleaned.
 *
 * @package WPChrono
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/Core/Schema.php';
require_once __DIR__ . '/includes/Core/Storage.php';

use WPChrono\Core\Schema;
use WPChrono\Core\Storage;

global $wpdb;

/**
 * Removes what belongs to the current site: its own tables, storage folder,
 * recovery script, options, transients and cron events.
 */
function wpchrono_uninstall_site(): void {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	foreach ( Schema::internal_tables() as $wpchrono_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpchrono_table ) );
	}

	Storage::destroy();

	// The Emergency Recovery Script lives in the WordPress root.
	$wpchrono_recovery = get_option( 'wp_chrono_recovery' );
	if ( is_array( $wpchrono_recovery ) && preg_match( '/^wp-chrono-recovery-[a-z0-9]{16}\.php$/', (string) ( $wpchrono_recovery['file'] ?? '' ) ) && is_file( ABSPATH . $wpchrono_recovery['file'] ) ) {
		unlink( ABSPATH . $wpchrono_recovery['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	wp_unschedule_hook( 'wp_chrono_scheduled_snapshot' );
	wp_unschedule_hook( 'wp_chrono_scheduled_snapshot_continue' );

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

// Snapshot copies of every site share the `_wpc_` tag, so one sweep finds them all.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpchrono_tables = array();
foreach ( array( Schema::SNAPSHOT_TABLE_PREFIX, Schema::LEGACY_SNAPSHOT_TABLE_PREFIX ) as $wpchrono_tag ) {
	$wpchrono_tables = array_merge(
		$wpchrono_tables,
		$wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpchrono_tag ) . '%' ) )
	);
}
foreach ( $wpchrono_tables as $wpchrono_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpchrono_table ) );
}
// phpcs:enable

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
