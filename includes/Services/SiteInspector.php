<?php
/**
 * Read-only metrics about the database, disk, snapshots and environment.
 *
 * @package WPChrono
 */

namespace WPChrono\Services;

use WPChrono\Core\Schema;
use WPChrono\Core\Storage;
use WPChrono\Services\Reset\Files;

defined( 'ABSPATH' ) || exit;

final class SiteInspector {

	/**
	 * @return array<string,mixed>
	 */
	public function status(): array {
		$database = $this->database();
		$disk     = $this->disk();
		return array(
			'database'      => $database,
			'snapshots'     => $this->snapshots(),
			'disk'          => $disk,
			'environment'   => $this->environment(),
			'storage'       => array( 'protected' => Storage::is_protected() ),
			'storage_usage' => $this->storage_usage( $database['snapshot_bytes'], $disk['free_bytes'] ),
		);
	}

	/**
	 * How much space WP Chrono uses (snapshot tables + archives + exports in
	 * the sealed storage folder) and whether it warrants a warning: above the
	 * configured threshold, or above 25% of the free disk space.
	 *
	 * @param int|null       $snapshot_bytes Bytes in snapshot tables; null to measure.
	 * @param int|null|false $free_bytes     Free disk bytes (null = unknown); false to measure.
	 * @return array<string,mixed>
	 */
	public function storage_usage( ?int $snapshot_bytes = null, $free_bytes = false ): array {
		global $wpdb;
		if ( null === $snapshot_bytes ) {
			$snapshot_bytes = (int) $this->database()['snapshot_bytes'];
		}
		if ( false === $free_bytes ) {
			$free_bytes = $this->disk()['free_bytes'];
		}
		$dir        = get_option( Storage::OPTION_DIR );
		$file_bytes = is_string( $dir ) && '' !== $dir ? Files::size( trailingslashit( WP_CONTENT_DIR ) . $dir ) : 0;
		$total      = $snapshot_bytes + $file_bytes;
		$threshold  = Settings::get()['storage_warn_mb'] * MB_IN_BYTES;
		$disk_share = null === $free_bytes ? null : (int) floor( $free_bytes * 0.25 );

		$reason = null;
		if ( $total > $threshold ) {
			$reason = sprintf( 'WP Chrono uses %s, above your %s warning threshold.', size_format( $total, 1 ), size_format( $threshold ) );
		} elseif ( null !== $disk_share && $total > $disk_share ) {
			$reason = sprintf( 'WP Chrono uses %s, more than 25%% of the %s of free disk space.', size_format( $total, 1 ), size_format( (int) $free_bytes, 1 ) );
		}

		// What "Prune old auto-snapshots" (keep the newest one) would free.
		$prunable = array( 'count' => 0, 'bytes' => 0 );
		$table    = Schema::snapshots_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$autos = $wpdb->get_col( $wpdb->prepare( "SELECT size_bytes FROM {$table} WHERE trigger_type = %s AND status = %s ORDER BY created_at DESC, id DESC", 'auto', 'ready' ) );
			$older = array_slice( array_map( 'intval', $autos ), 1 );
			$prunable = array( 'count' => count( $older ), 'bytes' => array_sum( $older ) );
		}

		// Storage cap: total GB limit and auto-snapshot count limit.
		$settings       = Settings::get();
		$capBytes       = (int) $settings['storage_cap_gb'] * GB_IN_BYTES;
		$capAutos       = (int) $settings['storage_cap_autos'];
		$readyAutos     = array_filter(
			$wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE trigger_type = %s AND status = %s ORDER BY created_at DESC, id DESC", 'auto', 'ready' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			static function ( $id ) {
				return '' !== $id;
			}
		);

		return array(
			'snapshot_bytes'  => $snapshot_bytes,
			'file_bytes'      => $file_bytes,
			'total_bytes'     => $total,
			'threshold_bytes' => $threshold,
			'free_bytes'      => $free_bytes,
			'protected'       => Storage::is_protected(),
			'warn'            => null !== $reason,
			'reason'          => $reason,
			'prunable'        => $prunable,
			// Storage cap.
			'cap_bytes'       => $capBytes,
			'cap_autos'       => $capAutos,
			'cap_used_bytes'  => $total > $capBytes,
			'cap_used_autos'  => count( $readyAutos ) > $capAutos,
			'over_cap'        => $total > $capBytes || count( $readyAutos ) > $capAutos,
		);
	}

	/**
	 * Splits every table in the schema into live site tables (our prefix) and
	 * snapshot copies (`_wpc_`), using INFORMATION_SCHEMA so one
	 * query covers sizes and row estimates for all of them.
	 *
	 * @return array<string,mixed>
	 */
	public function database(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_estimate, DATA_LENGTH + INDEX_LENGTH AS bytes
				FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = %s',
				$wpdb->dbname,
				'BASE TABLE'
			),
			ARRAY_A
		);

		$internal = Schema::internal_tables();
		$live     = array();
		$snapshot = array( 'tables' => 0, 'bytes' => 0 );

		foreach ( (array) $rows as $row ) {
			$name = (string) $row['name'];
			if ( Schema::is_snapshot_table( $name ) ) {
				$snapshot['tables']++;
				$snapshot['bytes'] += (int) $row['bytes'];
			} elseif ( Database::belongs_to_site( $name ) && ! in_array( $name, $internal, true ) ) {
				$live[] = array(
					'name'  => $name,
					'rows'  => (int) $row['row_estimate'],
					'bytes' => (int) $row['bytes'],
				);
			}
		}

		usort(
			$live,
			static function ( $a, $b ) {
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		return array(
			'size_bytes'           => array_sum( array_column( $live, 'bytes' ) ),
			'table_count'          => count( $live ),
			'row_estimate'         => array_sum( array_column( $live, 'rows' ) ),
			'largest_tables'       => array_slice( $live, 0, 5 ),
			'snapshot_bytes'       => $snapshot['bytes'],
			'snapshot_table_count' => $snapshot['tables'],
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function snapshots(): array {
		global $wpdb;
		$table = Schema::snapshots_table();

		// The registry may not exist yet if activation was skipped (e.g. mu-plugin copy).
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return array( 'count' => 0, 'last_auto' => null );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'ready' ) );
		$last_auto = $wpdb->get_row(
			$wpdb->prepare( "SELECT uid, label, status, created_at FROM {$table} WHERE trigger_type = %s ORDER BY created_at DESC LIMIT 1", 'auto' ),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'count'     => $count,
			'last_auto' => $last_auto ? array(
				'uid'        => $last_auto['uid'],
				'label'      => $last_auto['label'],
				'status'     => $last_auto['status'],
				'created_at' => SnapshotService::utc_iso( $last_auto['created_at'] ),
			) : null,
		);
	}

	/**
	 * disk_free_space() is commonly disabled on shared hosts; report null then.
	 *
	 * @return array<string,int|null>
	 */
	public function disk(): array {
		$free  = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$total = function_exists( 'disk_total_space' ) ? @disk_total_space( WP_CONTENT_DIR ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return array(
			'free_bytes'  => false === $free ? null : (int) $free,
			'total_bytes' => false === $total ? null : (int) $total,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function environment(): array {
		global $wpdb, $wp_version;

		return array(
			'wp_version'     => $wp_version,
			'php_version'    => PHP_VERSION,
			'db_version'     => $wpdb->db_server_info(),
			'table_prefix'   => $wpdb->prefix,
			'multisite'      => is_multisite(),
			'woocommerce'    => class_exists( 'WooCommerce' ),
			'active_plugins' => count( (array) get_option( 'active_plugins', array() ) ),
			'theme'          => wp_get_theme()->get( 'Name' ),
		);
	}
}
