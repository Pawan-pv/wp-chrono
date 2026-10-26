<?php
/**
 * Turns a WP Chrono `.sql.gz` export back into a local snapshot, so a backup
 * pulled from cloud storage is restored with the same verified, atomic
 * staging + RENAME TABLE swap as any other snapshot.
 *
 * The dump is decompressed once, then replayed in resumable ticks. Every
 * table is created as its `_wpc_{id}_…` snapshot copy, never under its live
 * name. Only the statement shapes Exporter writes are accepted —
 * DROP TABLE IF EXISTS / CREATE TABLE / INSERT INTO for this site's tables,
 * foreign-key ALTERs (recorded, not executed) and SET — so a tampered dump
 * can't run arbitrary SQL against live tables. (wpdb never runs multiple
 * statements per query, so a `;` inside a value can't chain one either.)
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Snapshot;

use WP_Error;
use WPChrono\Core\Schema;
use WPChrono\Services\Database;
use WPChrono\Services\SnapshotService;

defined( 'ABSPATH' ) || exit;

final class Importer {

	/** @var SnapshotService */
	private $snapshots;

	public function __construct( SnapshotService $snapshots ) {
		$this->snapshots = $snapshots;
	}

	/**
	 * Registers a `cloud` snapshot and decompresses the dump next to it.
	 *
	 * @param array<string,mixed> $manifest Cloud manifest, or one built from the dump header (label, tables, …).
	 * @param string              $trigger  Snapshot trigger: `cloud` (pulled) or `import` (uploaded file).
	 * @return array<string,mixed>|WP_Error Import state for tick().
	 */
	public function start( string $gz_path, array $manifest, string $trigger = 'cloud' ) {
		global $wpdb;
		$snap = (array) ( $manifest['snapshot'] ?? array() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			Schema::snapshots_table(),
			array(
				'uid'           => strtolower( wp_generate_password( 8, false ) ),
				'label'         => mb_substr( sanitize_text_field( ( 'import' === $trigger ? 'Imported: ' : 'Cloud: ' ) . ( (string) ( $snap['label'] ?? '' ) ?: 'snapshot #' . ( $snap['id'] ?? '?' ) ) ), 0, 191 ),
				'trigger_type'  => 'import' === $trigger ? 'import' : 'cloud',
				'status'        => 'building',
				'source_prefix' => $wpdb->prefix,
				'table_count'   => count( (array) ( $snap['tables'] ?? array() ) ),
				'meta'          => wp_json_encode(
					array(
						'note'  => sprintf( '%s (originally #%s from %s, %s).', 'import' === $trigger ? 'Imported from an uploaded .sql.gz' : 'Pulled from cloud storage', $snap['id'] ?? '?', $manifest['site'] ?? '?', $snap['created_at'] ?? '?' ),
						'cloud' => array( 'pulled_from' => $manifest['key'] ?? '', 'original' => array_intersect_key( $snap, array_flip( array( 'id', 'uid', 'label', 'trigger_type', 'created_at' ) ) ) ),
					)
				),
				'created_at'    => current_time( 'mysql', true ),
			)
		);
		if ( ! $ok ) {
			return new WP_Error( 'wp_chrono_import', 'Could not register the imported snapshot: ' . $wpdb->last_error );
		}
		$id  = (int) $wpdb->insert_id;
		$sql = preg_replace( '/\.gz$/', '', $gz_path ) . '.import';
		$in  = gzopen( $gz_path, 'rb' );
		$out = fopen( $sql, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $in || ! $out ) {
			return $this->fail( $id, 'Could not decompress the downloaded dump.' );
		}
		$tail = '';
		while ( ! gzeof( $in ) ) {
			$chunk = @gzread( $in, 1048576 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $chunk ) {
				gzclose( $in );
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				@unlink( $sql ); // phpcs:ignore
				return $this->fail( $id, 'The .sql.gz file is corrupt (gzip data error).', 422 );
			}
			fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$tail = substr( $tail . $chunk, -256 );
		}
		gzclose( $in );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		// Exporter always ends with this footer; without it the file is truncated.
		if ( false === strpos( $tail, '-- End of WP Chrono export' ) ) {
			@unlink( $sql ); // phpcs:ignore
			return $this->fail( $id, 'The dump is incomplete (no end-of-export marker); it may have been truncated during download or upload.', 422 );
		}

		$known = array();
		foreach ( (array) ( $snap['tables'] ?? array() ) as $t ) {
			$known[ (string) $t['source_table'] ] = array(
				'comment' => (string) ( $t['orig_comment'] ?? '' ),
				'fks'     => array_values( (array) ( $t['foreign_keys'] ?? array() ) ),
			);
		}
		return array(
			'snapshot_id' => $id,
			'sql'         => $sql,
			'offset'      => 0,
			'tables'      => array(), // orig => tracking row id
			'known'       => $known,
			'position'    => 0,
			'statements'  => 0,
		);
	}

	/**
	 * Replays statements until the deadline.
	 *
	 * @param array<string,mixed> $state Updated in place.
	 * @return bool|WP_Error True when finished (snapshot ready + verified).
	 */
	public function tick( array &$state, float $deadline ) {
		global $wpdb;
		$fh = fopen( $state['sql'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return $this->fail( (int) $state['snapshot_id'], 'The decompressed dump is missing.' );
		}
		fseek( $fh, (int) $state['offset'] );
		$wpdb->query( "SET @wpc_mode = @@SESSION.sql_mode, SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$statement = '';
		$result    = true;
		while ( false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			if ( '' === $statement && ( '' === trim( $line ) || 0 === strpos( $line, '--' ) ) ) {
				$state['offset'] = ftell( $fh );
				continue;
			}
			$statement .= $line;
			if ( ';' !== substr( rtrim( $line ), -1 ) ) {
				continue;
			}
			$applied = $this->apply( rtrim( trim( $statement ), ';' ), $state );
			if ( is_wp_error( $applied ) ) {
				$result = $applied;
				break;
			}
			$statement       = '';
			$state['offset'] = ftell( $fh );
			$state['statements']++;
			if ( microtime( true ) >= $deadline ) {
				$result = false;
				break;
			}
		}
		$eof = feof( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$wpdb->query( 'SET SESSION sql_mode = @wpc_mode' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( is_wp_error( $result ) ) {
			@unlink( $state['sql'] ); // phpcs:ignore
			$data = $result->get_error_data();
			return $this->fail( (int) $state['snapshot_id'], $result->get_error_message(), (int) ( is_array( $data ) ? ( $data['status'] ?? 500 ) : 500 ) );
		}
		if ( false === $result || ! $eof ) {
			return false;
		}
		return $this->finish( $state );
	}

	/**
	 * @param array<string,mixed> $state
	 * @return true|WP_Error
	 */
	private function apply( string $sql, array &$state ) {
		global $wpdb;
		$id = (int) $state['snapshot_id'];

		if ( preg_match( '/^SET\s/i', $sql ) || preg_match( '/^DROP TABLE IF EXISTS `((?:[^`]|``)+)`$/', $sql ) ) {
			return true; // Session settings / pre-create drops: not needed for snapshot copies.
		}

		if ( preg_match( '/^CREATE TABLE `((?:[^`]|``)+)` \(/', $sql, $m ) ) {
			$orig = str_replace( '``', '`', $m[1] );
			if ( ! Database::belongs_to_site( $orig ) ) {
				return new WP_Error( 'wp_chrono_import_prefix', sprintf( 'The dump contains table %s, which does not belong to this site (prefix %s).', $orig, $wpdb->prefix ), array( 'status' => 422 ) );
			}
			$copy = Schema::snapshot_table_name( (string) $id, $orig );
			// Foreign keys and the table comment are restored from the manifest instead.
			$fks  = array();
			$body = preg_replace_callback(
				'/,\s*\n\s*(CONSTRAINT\s+`(?:[^`]|``)+`\s+FOREIGN KEY\s[^\n]*?)(?=,?\s*\n)/',
				static function ( $c ) use ( &$fks ) {
					$fks[] = rtrim( $c[1], ',' );
					return '';
				},
				$sql
			);
			$orig_comment = preg_match( "/ COMMENT='((?:[^'\\\\]|\\\\.|'')*)'\s*$/s", (string) $body, $cm ) ? stripcslashes( str_replace( "''", "'", $cm[1] ) ) : '';
			$body         = preg_replace( "/ COMMENT='(?:[^'\\\\]|\\\\.|'')*'\s*$/s", '', (string) $body );
			$body = 'CREATE TABLE ' . Database::quote_identifier( $copy ) . substr( (string) $body, strlen( $m[0] ) - 2 );
			$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::quote_identifier( $copy ) ); // phpcs:ignore WordPress.DB
			if ( false === $wpdb->query( $body ) ) { // phpcs:ignore WordPress.DB
				return new WP_Error( 'wp_chrono_import_sql', "Could not create the copy of {$orig}: {$wpdb->last_error}" );
			}
			// Manifest (cloud) wins; an uploaded file carries the comment in its CREATE.
			$known = $state['known'][ $orig ] ?? array( 'comment' => $orig_comment, 'fks' => array() );
			$fks   = $known['fks'] ? $known['fks'] : $fks;
			TableCopier::set_comment( $copy, SnapshotService::table_tag( $id, $orig, $fks ) );
			$create = TableCopier::normalized_create( $copy );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				Schema::snapshot_tables_table(),
				array(
					'snapshot_id'    => $id,
					'position'       => $state['position']++,
					'source_table'   => $orig,
					'snapshot_table' => $copy,
					'copy_mode'      => 'import',
					'create_sql'     => $create,
					'schema_hash'    => md5( $create ),
					'orig_comment'   => $known['comment'],
					'foreign_keys'   => $fks ? wp_json_encode( $fks ) : null,
					'status'         => 'copying',
				)
			);
			$state['tables'][ $orig ] = (int) $wpdb->insert_id;
			return true;
		}

		if ( preg_match( '/^INSERT INTO `((?:[^`]|``)+)` \(/', $sql, $m ) ) {
			$orig = str_replace( '``', '`', $m[1] );
			if ( ! isset( $state['tables'][ $orig ] ) ) {
				return new WP_Error( 'wp_chrono_import_order', "The dump inserts into {$orig} before creating it." );
			}
			$copy     = Schema::snapshot_table_name( (string) $id, $orig );
			$inserted = $wpdb->query( 'INSERT INTO ' . Database::quote_identifier( $copy ) . substr( $sql, strlen( $m[0] ) - 2 ) ); // phpcs:ignore WordPress.DB
			if ( false === $inserted ) {
				return new WP_Error( 'wp_chrono_import_sql', "Could not import rows of {$orig}: {$wpdb->last_error}" );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET rows_copied = rows_copied + %d WHERE id = %d', Schema::snapshot_tables_table(), $inserted, $state['tables'][ $orig ] ) );
			return true;
		}

		if ( preg_match( '/^ALTER TABLE `((?:[^`]|``)+)` ADD (CONSTRAINT `(?:[^`]|``)+` FOREIGN KEY .+)$/s', $sql, $m ) ) {
			$orig = str_replace( '``', '`', $m[1] );
			if ( isset( $state['tables'][ $orig ] ) && empty( $state['known'][ $orig ]['fks'] ) ) {
				$state['known'][ $orig ]['fks'][] = $m[2];
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( Schema::snapshot_tables_table(), array( 'foreign_keys' => wp_json_encode( $state['known'][ $orig ]['fks'] ) ), array( 'id' => $state['tables'][ $orig ] ) );
			}
			return true; // Recorded; re-added on restore, never executed against a copy.
		}

		return new WP_Error( 'wp_chrono_import_statement', 'The dump contains a statement WP Chrono does not import: ' . mb_substr( $sql, 0, 80 ), array( 'status' => 422 ) );
	}

	/**
	 * @param array<string,mixed> $state
	 * @return true|WP_Error
	 */
	private function finish( array $state ) {
		global $wpdb;
		$id    = (int) $state['snapshot_id'];
		$track = Schema::snapshot_tables_table();
		foreach ( $state['tables'] as $orig => $row_id ) {
			$copy = Schema::snapshot_table_name( (string) $id, $orig );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$track} SET status = 'done', row_count = rows_copied, size_bytes = %d WHERE id = %d", TableCopier::size( $copy ), $row_id ) );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS t, COALESCE(SUM(row_count),0) AS r, COALESCE(SUM(size_bytes),0) AS b FROM {$track} WHERE snapshot_id = %d AND status = 'done'", $id ), ARRAY_A );
		$wpdb->update(
			Schema::snapshots_table(),
			array(
				'status'       => 'ready',
				'table_count'  => (int) $totals['t'],
				'row_count'    => (int) $totals['r'],
				'size_bytes'   => (int) $totals['b'],
				'completed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
		// phpcs:enable
		@unlink( $state['sql'] ); // phpcs:ignore
		$this->snapshots->verify( $id );
		return true;
	}

	/** 422 when the file itself is at fault (corrupt, truncated, refused), 500 for server-side failures. */
	private function fail( int $id, string $message, int $status = 500 ): WP_Error {
		$this->snapshots->delete( $id );
		return new WP_Error( 'wp_chrono_import_failed', $message . ' The imported snapshot was discarded; nothing live was changed.', array( 'status' => $status ) );
	}
}
