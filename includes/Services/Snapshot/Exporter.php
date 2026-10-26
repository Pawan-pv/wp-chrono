<?php
/**
 * Streams a snapshot to a gzipped SQL dump inside the sealed Storage folder.
 *
 * Table names are rewritten to their original live names, so the dump can be
 * imported anywhere with the same prefix. Export is resumable: each tick
 * appends a new gzip member to the file (concatenated members are a valid
 * gzip stream) and records its position in the `wp_chrono_export_job` option.
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Snapshot;

use WP_Error;
use WPChrono\Core\Storage;
use WPChrono\Services\Database;
use WPChrono\Services\SnapshotService;

defined( 'ABSPATH' ) || exit;

final class Exporter {

	const OPTION = 'wp_chrono_export_job';

	const ROWS_PER_INSERT = 500;

	const FILE_PATTERN = '/^snapshot-\d{8}-\d{6}-[A-Za-z0-9]{32}\.sql\.gz$/';

	/** @var SnapshotService */
	private $snapshots;

	public function __construct( SnapshotService $snapshots ) {
		$this->snapshots = $snapshots;
	}

	/**
	 * Starts (empty `$job_id`) or continues an export.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function tick( int $snapshot_id, string $job_id, float $budget ) {
		$snapshot = $this->snapshots->get( $snapshot_id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( 'ready' !== $snapshot['status'] ) {
			return new WP_Error( 'wp_chrono_snapshot_state', 'Only ready snapshots can be exported.', array( 'status' => 409 ) );
		}

		$dir = Storage::ensure();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$job = get_option( self::OPTION );
		if ( '' === $job_id || ! is_array( $job ) || ( $job['id'] ?? '' ) !== $job_id || (int) $job['snapshot_id'] !== $snapshot_id ) {
			$job = $this->new_job( $snapshot_id );
		}

		$deadline = microtime( true ) + $budget;
		$path     = $dir . $job['file'];
		$gz       = gzopen( $path, 'ab6' );
		if ( ! $gz ) {
			return new WP_Error( 'wp_chrono_export_io', 'Could not open the export file for writing.' );
		}

		if ( ! $job['header'] ) {
			gzwrite( $gz, $this->header( $snapshot ) );
			$job['header'] = true;
		}

		$tables = array_values( $this->snapshots->tables( $snapshot_id ) );
		while ( $job['index'] < count( $tables ) ) {
			$row = $tables[ $job['index'] ];
			if ( ! $this->dump_table( $gz, $row, $job, $deadline ) ) {
				break; // Out of time mid-table.
			}
			$job['index']++;
			$job['last_key'] = '';
			$job['offset']   = 0;
			$job['started']  = false;
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		$done = $job['index'] >= count( $tables );
		if ( $done ) {
			// Snapshot copies carry no foreign keys (CREATE TABLE … LIKE drops
			// them), so add the originals once every table exists.
			$alters = '';
			foreach ( $tables as $row ) {
				foreach ( (array) json_decode( (string) $row['foreign_keys'], true ) as $fk ) {
					$alters .= 'ALTER TABLE ' . Database::quote_identifier( $row['source_table'] ) . " ADD {$fk};\n";
				}
			}
			if ( '' !== $alters ) {
				gzwrite( $gz, "\n--\n-- Foreign keys\n--\n{$alters}" );
			}
			gzwrite( $gz, "\nSET FOREIGN_KEY_CHECKS = 1;\n-- End of WP Chrono export\n" );
		}
		gzclose( $gz );
		clearstatcache( true, $path );

		if ( ! $done ) {
			update_option( self::OPTION, $job, false );
			return array(
				'done'         => false,
				'job'          => $job['id'],
				'tables_total' => count( $tables ),
				'tables_done'  => $job['index'],
				'bytes'        => (int) filesize( $path ),
			);
		}

		delete_option( self::OPTION );
		$export   = array(
			'file'       => $job['file'],
			'bytes'      => (int) filesize( $path ),
			'created_at' => gmdate( 'c' ),
		);
		$exports   = (array) ( $snapshot['meta']['exports'] ?? array() );
		$exports[] = $export;
		$this->snapshots->update_meta( $snapshot_id, array( 'exports' => $exports ) );

		return array(
			'done'         => true,
			'tables_total' => count( $tables ),
			'tables_done'  => count( $tables ),
			'file'         => $export['file'],
			'bytes'        => $export['bytes'],
			'download_url' => self::download_url( $export['file'] ),
		);
	}

	/**
	 * Nonce-protected admin-post URL that streams the file to an admin.
	 */
	public static function download_url( string $file ): string {
		// Raw URL (not wp_nonce_url(), which HTML-escapes `&` to `&amp;`): this
		// goes out as JSON and React sets it as href verbatim.
		return add_query_arg(
			array(
				'action'   => 'wp_chrono_download',
				'file'     => rawurlencode( $file ),
				'_wpnonce' => wp_create_nonce( 'wp_chrono_download_' . $file ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * `admin_post_wp_chrono_download` handler.
	 */
	public static function handle_download(): void {
		// Validated by the strict FILE_PATTERN whitelist below. (Not sanitize_file_name():
		// it rewrites "x.sql.gz" to "x.sql_.gz", which made every download fail.)
		$file = isset( $_GET['file'] ) ? basename( (string) wp_unslash( $_GET['file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- nonce verified and pattern-whitelisted below.
		if ( ! current_user_can( 'manage_options' ) || ! preg_match( self::FILE_PATTERN, $file ) ) {
			wp_die( esc_html__( 'You are not allowed to download this file.', 'wp-chrono' ), 403 );
		}
		check_admin_referer( 'wp_chrono_download_' . $file );

		$dir = Storage::ensure();
		if ( is_wp_error( $dir ) || ! is_file( $dir . $file ) ) {
			wp_die( esc_html__( 'Export file not found.', 'wp-chrono' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: application/gzip' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'Content-Length: ' . filesize( $dir . $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		readfile( $dir . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public static function delete_file( string $file ): void {
		if ( ! preg_match( self::FILE_PATTERN, $file ) ) {
			return;
		}
		$dir = Storage::ensure();
		if ( ! is_wp_error( $dir ) && is_file( $dir . $file ) ) {
			unlink( $dir . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function new_job( int $snapshot_id ): array {
		$previous = get_option( self::OPTION );
		if ( is_array( $previous ) && ! empty( $previous['file'] ) ) {
			self::delete_file( $previous['file'] ); // Abandoned partial export.
		}
		return array(
			'id'          => strtolower( wp_generate_password( 8, false ) ),
			'snapshot_id' => $snapshot_id,
			'file'        => Storage::filename( 'snapshot', 'sql.gz' ),
			'header'      => false,
			'index'       => 0,
			'started'     => false,
			'last_key'    => '',
			'offset'      => 0,
		);
	}

	/**
	 * @param array<string,mixed> $snapshot
	 */
	private function header( array $snapshot ): string {
		return sprintf(
			"-- WP Chrono export of snapshot #%d (%s)\n-- Created %s, exported %s\n-- Site: %s\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n",
			$snapshot['id'],
			str_replace( array( "\r", "\n" ), ' ', $snapshot['label'] ),
			$snapshot['created_at'],
			gmdate( 'c' ),
			home_url()
		);
	}

	/**
	 * Writes (the rest of) one table. Returns false when the deadline hit first.
	 *
	 * @param resource             $gz
	 * @param array<string,string> $row Tracking row.
	 * @param array<string,mixed>  $job Updated in place.
	 */
	private function dump_table( $gz, array $row, array &$job, float $deadline ): bool {
		global $wpdb;
		$orig = Database::quote_identifier( $row['source_table'] );
		$snap = Database::quote_identifier( $row['snapshot_table'] );

		if ( ! $job['started'] ) {
			$create = $wpdb->get_row( "SHOW CREATE TABLE {$snap}", ARRAY_N ); // phpcs:ignore WordPress.DB
			$sql    = preg_replace( '/^CREATE TABLE `[^`]+`/', 'CREATE TABLE ' . $orig, (string) $create[1] );
			$sql    = preg_replace( "/ COMMENT='(?:[^'\\\\]|\\\\.|'')*'\s*$/s", '', $sql );
			if ( '' !== $row['orig_comment'] ) {
				$sql .= " COMMENT='" . $this->escape( $row['orig_comment'] ) . "'";
			}
			gzwrite( $gz, "\n--\n-- Table {$row['source_table']}\n--\nDROP TABLE IF EXISTS {$orig};\n{$sql};\n" );
			$job['started'] = true;
		}

		$strategy = TableCopier::strategy( $row['snapshot_table'] );
		$columns  = TableCopier::column_list( $row['snapshot_table'] );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$keyed = 'single' !== $strategy['mode'] && $strategy['keys'];
		while ( true ) {
			if ( $keyed ) {
				$where = TableCopier::after_clause( $strategy['keys'], (string) $job['last_key'] );
				$rows  = $wpdb->get_results( "SELECT {$columns} FROM {$snap}{$where}" . TableCopier::order_clause( $strategy['keys'] ) . ' LIMIT ' . self::ROWS_PER_INSERT, ARRAY_A );
			} else {
				// Snapshot tables are never written to, so offset paging is stable here.
				$rows = $wpdb->get_results( "SELECT {$columns} FROM {$snap} LIMIT " . (int) $job['offset'] . ', ' . self::ROWS_PER_INSERT, ARRAY_A );
			}
			if ( ! $rows ) {
				return true;
			}

			$values = array();
			foreach ( $rows as $record ) {
				$values[] = '(' . implode( ',', array_map( array( $this, 'literal' ), $record ) ) . ')';
			}
			gzwrite( $gz, "INSERT INTO {$orig} ({$columns}) VALUES\n" . implode( ",\n", $values ) . ";\n" );

			if ( $keyed ) {
				$job['last_key'] = TableCopier::encode_key( $strategy['keys'], (array) end( $rows ) );
			} else {
				$job['offset'] += count( $rows );
			}
			if ( count( $rows ) < self::ROWS_PER_INSERT ) {
				return true;
			}
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
		}
		// phpcs:enable
	}

	/**
	 * @param string|null $value
	 */
	private function literal( $value ): string {
		return null === $value ? 'NULL' : "'" . $this->escape( (string) $value ) . "'";
	}

	/**
	 * Binary-safe escaping via the live mysqli connection. (wpdb::_real_escape
	 * would inject its placeholder-escape hashes for `%`, corrupting the dump.)
	 */
	private function escape( string $value ): string {
		global $wpdb;
		if ( $wpdb->dbh instanceof \mysqli ) {
			// $wpdb->_real_escape() would add placeholder escapes to '%', corrupting the dump file.
			return mysqli_real_escape_string( $wpdb->dbh, $value ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string
		}
		return addslashes( $value );
	}
}
