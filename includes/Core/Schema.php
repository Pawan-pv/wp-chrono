<?php
/**
 * Database schema and table-naming conventions.
 *
 * @package WPChrono
 */

namespace WPChrono\Core;

defined( 'ABSPATH' ) || exit;

final class Schema {

	const VERSION = '3';

	/**
	 * Snapshot tables are named `_wpc_{snap_id}_{hash8}_{short_orig}` (<= 64
	 * chars) and carry a JSON COMMENT naming the original table. The tag
	 * deliberately does not share the site's `$wpdb->prefix`, so
	 * `SHOW TABLES LIKE 'wp_%'` never picks up snapshot copies.
	 */
	const SNAPSHOT_TABLE_PREFIX = '_wpc_';

	/** Tag used by pre-release builds; still recognised so nothing is orphaned. */
	const LEGACY_SNAPSHOT_TABLE_PREFIX = '_wpchrono_snap_';

	/**
	 * `_wpc_{tag}_{hash8}_{short_orig}`, never longer than MySQL's 64-char limit.
	 * The md5 fragment keeps names unique even when two long originals truncate
	 * to the same prefix. Tags: snapshot ID, `stg_{job}` (restore staging) or
	 * `old_{job}` (live tables parked during the swap).
	 */
	public static function snapshot_table_name( string $tag, string $orig ): string {
		$head = self::SNAPSHOT_TABLE_PREFIX . $tag . '_' . substr( md5( $orig ), 0, 8 ) . '_';
		return $head . substr( $orig, 0, 64 - strlen( $head ) );
	}

	public static function is_snapshot_table( string $name ): bool {
		return 0 === strpos( $name, self::SNAPSHOT_TABLE_PREFIX ) || 0 === strpos( $name, self::LEGACY_SNAPSHOT_TABLE_PREFIX );
	}

	public static function snapshots_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpchrono_snapshots';
	}

	public static function snapshot_tables_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpchrono_snapshot_tables';
	}

	/**
	 * Tables owned by WP Chrono itself. They are excluded from snapshots and
	 * survive resets, otherwise a reset would erase the record of the snapshots
	 * you would need to undo it.
	 *
	 * @return string[]
	 */
	public static function internal_tables(): array {
		return array( self::snapshots_table(), self::snapshot_tables_table() );
	}

	/**
	 * dbDelta-compatible DDL (two spaces after PRIMARY KEY are required).
	 */
	public static function ddl(): string {
		global $wpdb;
		$charset   = $wpdb->get_charset_collate();
		$snapshots = self::snapshots_table();
		$tables    = self::snapshot_tables_table();

		return "CREATE TABLE {$snapshots} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uid varchar(32) NOT NULL,
  label varchar(191) NOT NULL DEFAULT '',
  trigger_type varchar(20) NOT NULL DEFAULT 'manual',
  status varchar(20) NOT NULL DEFAULT 'pending',
  source_prefix varchar(64) NOT NULL DEFAULT '',
  table_count int(10) unsigned NOT NULL DEFAULT 0,
  row_count bigint(20) unsigned NOT NULL DEFAULT 0,
  size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  meta longtext NULL,
  created_at datetime NOT NULL,
  completed_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uid (uid),
  KEY status (status),
  KEY trigger_created (trigger_type,created_at)
) {$charset};
CREATE TABLE {$tables} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  snapshot_id bigint(20) unsigned NOT NULL,
  position int(10) unsigned NOT NULL DEFAULT 0,
  source_table varchar(64) NOT NULL,
  snapshot_table varchar(64) NOT NULL,
  copy_mode varchar(10) NOT NULL DEFAULT '',
  key_columns varchar(255) NOT NULL DEFAULT '',
  last_key text NULL,
  row_count bigint(20) unsigned NOT NULL DEFAULT 0,
  rows_copied bigint(20) unsigned NOT NULL DEFAULT 0,
  size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  schema_hash char(32) NOT NULL DEFAULT '',
  create_sql longtext NULL,
  orig_comment varchar(2048) NOT NULL DEFAULT '',
  foreign_keys longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  PRIMARY KEY  (id),
  UNIQUE KEY snapshot_table (snapshot_table),
  KEY snapshot_position (snapshot_id,position)
) {$charset};";
	}
}
