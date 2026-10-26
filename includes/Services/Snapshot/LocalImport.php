<?php
/**
 * Upload a WP Chrono `.sql.gz` back into the site and rebuild it as a
 * verified snapshot ("restore is always free" — no phpMyAdmin, no paywall).
 *
 * 1. Chunked upload into sealed storage (chunk(): offsets must line up, so a
 *    dropped or repeated chunk is detected; chunks stay below PHP's upload
 *    limits, so any size works).
 * 2. Validation: the first line must be the Exporter header
 *    `-- WP Chrono export of snapshot #N (label)`; the Importer then checks
 *    the gzip stream, the end-of-export marker and every statement.
 * 3. Import in resumable ticks through Snapshot\Importer, into a new
 *    snapshot with trigger `import`, which restores with the normal atomic
 *    swap.
 *
 * State lives in the autoload=off option `wp_chrono_import_job`.
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Snapshot;

use WP_Error;
use WPChrono\Core\Storage;
use WPChrono\Services\SnapshotService;

defined( 'ABSPATH' ) || exit;

final class LocalImport {

	const OPTION = 'wp_chrono_import_job';

	const HEADER = '/^-- WP Chrono export of snapshot #(\d+) \((.*)\)\s*$/';

	/** Largest chunk the client should send (also capped by PHP's upload limits). */
	const MAX_CHUNK = 8388608;

	/** @var SnapshotService */
	private $snapshots;

	public function __construct( SnapshotService $snapshots ) {
		$this->snapshots = $snapshots;
	}

	public static function max_chunk(): int {
		return (int) max( 65536, min( self::MAX_CHUNK, wp_max_upload_size() - 65536 ) );
	}

	/**
	 * Appends one uploaded chunk. On the final chunk the file is validated and
	 * the import starts.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function chunk( string $upload_id, int $offset, string $chunk_path, bool $final, string $name = '' ) {
		$job = get_option( self::OPTION );
		if ( '' === $upload_id ) {
			if ( 0 !== $offset ) {
				return new WP_Error( 'wp_chrono_import_offset', 'A new upload must start at offset 0.', array( 'status' => 409 ) );
			}
			$job = $this->new_job( $name );
			if ( is_wp_error( $job ) ) {
				return $job;
			}
		} elseif ( ! is_array( $job ) || ( $job['upload'] ?? '' ) !== $upload_id || 'upload' !== $job['phase'] ) {
			return new WP_Error( 'wp_chrono_import_missing', 'This upload no longer exists. Start again.', array( 'status' => 404 ) );
		}
		clearstatcache( true, $job['path'] );
		$have = is_file( $job['path'] ) ? (int) filesize( $job['path'] ) : 0;
		if ( $offset !== $have ) {
			return new WP_Error( 'wp_chrono_import_offset', sprintf( 'Expected the chunk at byte %d, got %d.', $have, $offset ), array( 'status' => 409, 'expected' => $have ) );
		}
		$in  = fopen( $chunk_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out = fopen( $job['path'], 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $in || ! $out ) {
			return new WP_Error( 'wp_chrono_import_io', 'Could not write the upload to storage.', array( 'status' => 500 ) );
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		clearstatcache( true, $job['path'] );
		$job['bytes'] = (int) filesize( $job['path'] );
		update_option( self::OPTION, $job, false );

		if ( ! $final ) {
			return array( 'done' => false, 'phase' => 'upload', 'upload' => $job['upload'], 'bytes' => $job['bytes'], 'message' => sprintf( 'Uploaded %s', size_format( $job['bytes'], 1 ) ) );
		}
		return $this->begin( $job );
	}

	/**
	 * CLI: imports a file from disk to completion.
	 *
	 * @return array<string,mixed>|WP_Error Final tick.
	 */
	public function from_file( string $path ) {
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'wp_chrono_import_io', "Cannot read {$path}." );
		}
		$job = $this->new_job( basename( $path ) );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( ! copy( $path, $job['path'] ) ) {
			return new WP_Error( 'wp_chrono_import_io', 'Could not copy the file into storage.' );
		}
		$job['bytes'] = (int) filesize( $job['path'] );
		$tick         = $this->begin( $job );
		while ( ! is_wp_error( $tick ) && empty( $tick['done'] ) ) {
			$tick = $this->tick( (string) $tick['job'], INF );
		}
		return $tick;
	}

	/**
	 * One import tick.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function tick( string $job_id, float $budget = SnapshotService::TICK_BUDGET ) {
		$job = get_option( self::OPTION );
		if ( ! is_array( $job ) || ( $job['id'] ?? '' ) !== $job_id || 'import' !== $job['phase'] ) {
			return new WP_Error( 'wp_chrono_import_missing', 'This import no longer exists. Start again.', array( 'status' => 404 ) );
		}
		$state  = $job['import'];
		$result = ( new Importer( $this->snapshots ) )->tick( $state, microtime( true ) + $budget );
		if ( is_wp_error( $result ) ) {
			$this->cancel();
			return $result;
		}
		$job['import'] = $state;
		if ( ! $result ) {
			update_option( self::OPTION, $job, false );
			return array( 'done' => false, 'phase' => 'import', 'job' => $job['id'], 'statements' => $state['statements'], 'message' => sprintf( 'Importing tables: %d statements so far.', $state['statements'] ) );
		}

		$id = (int) $state['snapshot_id'];
		$this->snapshots->attach_export( $id, basename( $job['path'] ) );
		delete_option( self::OPTION );
		$snapshot = $this->snapshots->get( $id );
		return array(
			'done'        => true,
			'phase'       => 'ready',
			'snapshot_id' => $id,
			'snapshot'    => $snapshot,
			'message'     => sprintf(
				'Imported %s as snapshot #%d: %d tables, %s rows%s. Restore it like any other snapshot.',
				$job['name'] ?: 'the file',
				$id,
				$snapshot['table_count'],
				number_format_i18n( $snapshot['row_count'] ),
				! empty( $snapshot['meta']['verified'] ) ? ', verified' : ', VERIFICATION FAILED'
			),
		);
	}

	/**
	 * Drops a half-finished upload or import: the uploaded file, the
	 * decompressed temp file and the still-building snapshot. (A finished
	 * import has already removed its job, so its snapshot is never touched.)
	 */
	public function cancel(): void {
		$job = get_option( self::OPTION );
		if ( is_array( $job ) ) {
			$id = (int) ( $job['import']['snapshot_id'] ?? 0 );
			if ( $id ) {
				$snapshot = $this->snapshots->get( $id );
				if ( ! is_wp_error( $snapshot ) && 'building' === $snapshot['status'] ) {
					$this->snapshots->delete( $id );
				}
				@unlink( (string) ( $job['import']['sql'] ?? '' ) ); // phpcs:ignore
			}
			@unlink( (string) $job['path'] ); // phpcs:ignore
		}
		delete_option( self::OPTION );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private function new_job( string $name ) {
		$this->cancel(); // One import at a time; abandon any previous one.
		$dir = Storage::ensure();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$job = array(
			'id'     => strtolower( wp_generate_password( 6, false ) ),
			'upload' => strtolower( wp_generate_password( 12, false ) ),
			'phase'  => 'upload',
			'name'   => (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', basename( $name ) ), // Display only.
			'path'   => $dir . Storage::filename( 'snapshot', 'sql.gz' ),
			'bytes'  => 0,
			'import' => null,
		);
		update_option( self::OPTION, $job, false );
		return $job;
	}

	/**
	 * Validates the header and starts the Importer.
	 *
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>|WP_Error
	 */
	private function begin( array $job ) {
		$header = self::read_header( $job['path'] );
		if ( is_wp_error( $header ) ) {
			$this->cancel();
			return $header;
		}
		$manifest = array(
			'site'     => $header['site'],
			'key'      => '',
			'snapshot' => array( 'id' => $header['id'], 'label' => $header['label'], 'created_at' => $header['created'], 'tables' => array() ),
		);
		$state = ( new Importer( $this->snapshots ) )->start( $job['path'], $manifest, 'import' );
		if ( is_wp_error( $state ) ) {
			$this->cancel();
			return $state;
		}
		$job['phase']  = 'import';
		$job['import'] = $state;
		update_option( self::OPTION, $job, false );
		return array( 'done' => false, 'phase' => 'import', 'job' => $job['id'], 'statements' => 0, 'message' => sprintf( 'Validated a WP Chrono export of snapshot #%d (%s) from %s. Importing…', $header['id'], $header['label'], $header['site'] ?: 'an unknown site' ) );
	}

	/**
	 * Parses the Exporter header lines.
	 *
	 * @return array{id:int,label:string,created:string,site:string}|WP_Error
	 */
	public static function read_header( string $path ) {
		$gz = @gzopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $gz ) {
			return new WP_Error( 'wp_chrono_import_format', 'That is not a readable .sql.gz file.', array( 'status' => 400 ) );
		}
		$lines = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$line    = @gzgets( $gz, 4096 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$lines[] = false === $line ? '' : rtrim( $line, "\r\n" );
		}
		gzclose( $gz );
		if ( ! preg_match( self::HEADER, $lines[0], $m ) ) {
			return new WP_Error( 'wp_chrono_import_format', 'This file was not exported by WP Chrono (missing the "-- WP Chrono export of snapshot" header). Only WP Chrono .sql.gz exports can be imported.', array( 'status' => 400 ) );
		}
		return array(
			'id'      => (int) $m[1],
			'label'   => $m[2],
			'created' => preg_match( '/^-- Created (\S+)/', $lines[1], $c ) ? rtrim( $c[1], ',' ) : '',
			'site'    => preg_match( '/^-- Site: (\S+)/', $lines[2], $s ) ? $s[1] : '',
		);
	}
}
