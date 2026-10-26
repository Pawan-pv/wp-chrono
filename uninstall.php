<?php
/**
 * Uninstall: removes every table, option and file WP Chrono created,
 * including all `_wpc_` (and legacy `_wpchrono_snap_`) snapshot tables.
 *
 * @package WPChrono
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/Core/Schema.php';
require_once __DIR__ . '/includes/Core/Storage.php';

use WPChrono\Core\Schema;
use WPChrono\Core\Storage;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpchrono_tables = array();
foreach ( array( Schema::SNAPSHOT_TABLE_PREFIX, Schema::LEGACY_SNAPSHOT_TABLE_PREFIX ) as $wpchrono_tag ) {
	$wpchrono_tables = array_merge(
		$wpchrono_tables,
		$wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpchrono_tag ) . '%' ) )
	);
}
foreach ( array_merge( $wpchrono_tables, Schema::internal_tables() ) as $wpchrono_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $wpchrono_table ) . '`' );
}
// phpcs:enable

Storage::destroy();

// The Emergency Recovery Script lives in the WordPress root.
$wpchrono_recovery = get_option( 'wp_chrono_recovery' );
if ( is_array( $wpchrono_recovery ) && preg_match( '/^wp-chrono-recovery-[a-z0-9]{16}\.php$/', (string) ( $wpchrono_recovery['file'] ?? '' ) ) && is_file( ABSPATH . $wpchrono_recovery['file'] ) ) {
	unlink( ABSPATH . $wpchrono_recovery['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wp_chrono_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
