<?php
/**
 * Pushes snapshots to, and pulls them back from, S3-compatible storage.
 *
 * Remote layout (one folder per snapshot, under the site's prefix):
 *
 *   {prefix}{site}/{uid}/db.sql.gz              the snapshot's SQL export
 *   {prefix}{site}/{uid}/rollback/{zip}         plugin / theme archives, if any
 *   {prefix}{site}/{uid}/manifest.json          written LAST: snapshot facts,
 *                                               table list, SHA-256 of every file
 *
 * A folder without a manifest is an interrupted push and is ignored.
 *
 * Both directions are resumable and stateless between ticks except for a
 * small job option: push uploads one file per tick (exporting first when the
 * snapshot has no .sql.gz yet); pull downloads one file per tick, checks its
 * SHA-256 against the manifest, then hands the dump to the Importer, which
 * rebuilds it as a new local snapshot (trigger `cloud`) that restores through
 * the normal atomic swap — including "Full Undo" when archives came along.
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Snapshot;

use WP_Error;
use WPChrono\Core\Storage;
use WPChrono\Services\CloudStorageService;
use WPChrono\Services\SnapshotService;

defined( 'ABSPATH' ) || exit;

final class CloudSync {

	const PULL_OPTION = 'wp_chrono_cloud_pull_job';

	/** @var SnapshotService */
	private $snapshots;

	/** @var CloudStorageService */
	private $cloud;

	public function __construct( SnapshotService $snapshots, ?CloudStorageService $cloud = null ) {
		$this->snapshots = $snapshots;
		$this->cloud     = $cloud ?? new CloudStorageService();
	}

	// ---------------------------------------------------------------------
	// Push
	// ---------------------------------------------------------------------

	/**
	 * One push tick: exports first if needed, then uploads one file.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function push( int $snapshot_id, string $job = '', int $step = 0, float $budget = SnapshotService::TICK_BUDGET ) {
		$snapshot = $this->snapshots->get( $snapshot_id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( 'ready' !== $snapshot['status'] ) {
			return new WP_Error( 'wp_chrono_snapshot_state', 'Only ready snapshots can be pushed.', array( 'status' => 409 ) );
		}

		$export = $this->current_export( $snapshot );
		if ( null === $export ) {
			$tick = $this->snapshots->export_tick( $snapshot_id, $job, $budget );
			if ( is_wp_error( $tick ) ) {
				return $tick;
			}
			return array(
				'done'    => false,
				'phase'   => 'export',
				'job'     => $tick['done'] ? '' : (string) ( $tick['job'] ?? '' ),
				'step'    => 0,
				'message' => sprintf( 'Exporting to .sql.gz: %d/%d tables', $tick['tables_done'], $tick['tables_total'] ),
			);
		}

		$files = $this->push_files( $snapshot, $export );
		$total = count( $files ) + 1; // + manifest
		if ( $step < count( $files ) ) {
			$file = $files[ $step ];
			$put  = $this->cloud->put_file( $file['key'], $file['path'], $file['type'] );
			if ( is_wp_error( $put ) ) {
				return $put;
			}
			return array(
				'done'    => false,
				'phase'   => 'upload',
				'job'     => '',
				'step'    => $step + 1,
				'total'   => $total,
				'message' => sprintf( 'Uploaded %s (%s)', basename( $file['key'] ), size_format( (int) filesize( $file['path'] ), 1 ) ),
			);
		}

		$prefix   = $this->folder( $snapshot );
		$manifest = $this->manifest( $snapshot, $files, $prefix );
		$put      = $this->cloud->put_string( $prefix . 'manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), 'application/json' );
		if ( is_wp_error( $put ) ) {
			return $put;
		}
		$bytes = array_sum( array_column( $files, 'bytes' ) );
		$this->snapshots->update_meta( $snapshot_id, array( 'cloud' => array( 'key' => $prefix, 'pushed_at' => gmdate( 'c' ), 'bytes' => $bytes, 'files' => count( $files ) ) ) );
		return array(
			'done'    => true,
			'phase'   => 'manifest',
			'step'    => $total,
			'total'   => $total,
			'key'     => $prefix,
			'bytes'   => $bytes,
			'message' => sprintf( 'Pushed snapshot #%d to %s (%d files, %s).', $snapshot_id, $prefix, count( $files ), size_format( $bytes, 1 ) ),
		);
	}

	/**
	 * Newest export of a snapshot that still exists on disk.
	 *
	 * @param array<string,mixed> $snapshot
	 * @return array{file:string,path:string}|null
	 */
	private function current_export( array $snapshot ): ?array {
		$dir = Storage::ensure();
		if ( is_wp_error( $dir ) ) {
			return null;
		}
		foreach ( array_reverse( (array) ( $snapshot['meta']['exports'] ?? array() ) ) as $export ) {
			$file = basename( (string) $export['file'] );
			if ( preg_match( Exporter::FILE_PATTERN, $file ) && is_file( $dir . $file ) ) {
				return array( 'file' => $file, 'path' => $dir . $file );
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed>       $snapshot
	 * @param array{file:string,path:string} $export
	 * @return array<int,array{key:string,path:string,type:string,sha256:string,bytes:int,entry?:array}>
	 */
	private function push_files( array $snapshot, array $export ): array {
		$prefix = $this->folder( $snapshot );
		$files  = array(
			array(
				'key'    => $prefix . 'db.sql.gz',
				'path'   => $export['path'],
				'type'   => 'application/gzip',
				'sha256' => (string) hash_file( 'sha256', $export['path'] ),
				'bytes'  => (int) filesize( $export['path'] ),
			),
		);
		foreach ( SnapshotService::rollback_files( $snapshot ) as $entry ) {
			$path = FileArchive::path( $entry );
			if ( '' === $path ) {
				continue;
			}
			$files[] = array(
				'key'    => $prefix . 'rollback/' . basename( (string) $entry['zip'] ),
				'path'   => $path,
				'type'   => 'application/zip',
				'sha256' => (string) $entry['sha256'],
				'bytes'  => (int) filesize( $path ),
				'entry'  => $entry,
			);
		}
		return $files;
	}

	/**
	 * @param array<string,mixed>            $snapshot
	 * @param array<int,array<string,mixed>> $files
	 * @return array<string,mixed>
	 */
	private function manifest( array $snapshot, array $files, string $prefix ): array {
		$tables = array();
		foreach ( $this->snapshots->tables( (int) $snapshot['id'] ) as $source => $row ) {
			$tables[] = array(
				'source_table' => $source,
				'row_count'    => (int) $row['row_count'],
				'orig_comment' => (string) $row['orig_comment'],
				'foreign_keys' => $row['foreign_keys'] ? (array) json_decode( (string) $row['foreign_keys'], true ) : array(),
			);
		}
		$rollback = array();
		foreach ( array_slice( $files, 1 ) as $file ) {
			$rollback[] = array_merge( (array) $file['entry'], array( 'key' => $file['key'] ) );
		}
		global $wpdb;
		return array(
			'wpchrono_cloud' => 1,
			'plugin_version' => WP_CHRONO_VERSION,
			'site'           => home_url(),
			'table_prefix'   => $wpdb->prefix,
			'pushed_at'      => gmdate( 'c' ),
			'key'            => $prefix,
			'snapshot'       => array(
				'id'           => (int) $snapshot['id'],
				'uid'          => $snapshot['uid'],
				'label'        => $snapshot['label'],
				'trigger_type' => $snapshot['trigger_type'],
				'created_at'   => $snapshot['created_at'],
				'table_count'  => (int) $snapshot['table_count'],
				'row_count'    => (int) $snapshot['row_count'],
				'size_bytes'   => (int) $snapshot['size_bytes'],
				'tables'       => $tables,
			),
			'db'             => array( 'key' => $files[0]['key'], 'sha256' => $files[0]['sha256'], 'bytes' => $files[0]['bytes'] ),
			'rollback'       => $rollback,
		);
	}

	/**
	 * @param array<string,mixed> $snapshot
	 */
	private function folder( array $snapshot ): string {
		return $this->cloud->site_prefix() . sanitize_key( (string) $snapshot['uid'] ) . '/';
	}

	// ---------------------------------------------------------------------
	// Remote listing
	// ---------------------------------------------------------------------

	/**
	 * Complete backups (folders with a manifest) for this site, newest first.
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function remote() {
		$objects = $this->cloud->list_objects( $this->cloud->site_prefix(), 5000 );
		if ( is_wp_error( $objects ) ) {
			return $objects;
		}
		$sizes = array();
		foreach ( $objects as $o ) {
			$folder             = substr( $o['key'], 0, (int) strrpos( rtrim( $o['key'], '/' ), '/' ) + 1 );
			$folder             = preg_replace( '#rollback/$#', '', $folder );
			$sizes[ $folder ]   = ( $sizes[ $folder ] ?? 0 ) + $o['size'];
		}
		$backups = array();
		foreach ( $objects as $o ) {
			if ( '/manifest.json' !== substr( $o['key'], -14 ) || count( $backups ) >= 100 ) {
				continue;
			}
			$raw      = $this->cloud->get_string( $o['key'] );
			$manifest = is_wp_error( $raw ) ? null : json_decode( $raw, true );
			if ( ! is_array( $manifest ) || 1 !== (int) ( $manifest['wpchrono_cloud'] ?? 0 ) ) {
				continue;
			}
			$folder    = substr( $o['key'], 0, -13 );
			$snap      = (array) $manifest['snapshot'];
			$backups[] = array(
				'key'          => $folder,
				'label'        => (string) ( $snap['label'] ?? '' ),
				'snapshot_id'  => (int) ( $snap['id'] ?? 0 ),
				'trigger_type' => (string) ( $snap['trigger_type'] ?? '' ),
				'created_at'   => (string) ( $snap['created_at'] ?? '' ),
				'pushed_at'    => (string) ( $manifest['pushed_at'] ?? $o['modified'] ),
				'table_count'  => (int) ( $snap['table_count'] ?? 0 ),
				'row_count'    => (int) ( $snap['row_count'] ?? 0 ),
				'bytes'        => (int) ( $sizes[ $folder ] ?? 0 ),
				'files'        => count( (array) ( $manifest['rollback'] ?? array() ) ),
				'site'         => (string) ( $manifest['site'] ?? '' ),
			);
		}
		usort(
			$backups,
			static function ( $a, $b ) {
				return strcmp( $b['pushed_at'], $a['pushed_at'] );
			}
		);
		return $backups;
	}

	// ---------------------------------------------------------------------
	// Pull
	// ---------------------------------------------------------------------

	/**
	 * One pull tick. Start with `$key` (a remote folder) and no job; continue
	 * with the returned job ID.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function pull( string $key, string $job_id = '', float $budget = SnapshotService::TICK_BUDGET ) {
		$deadline = microtime( true ) + $budget;
		$job      = get_option( self::PULL_OPTION );
		if ( '' === $job_id || ! is_array( $job ) || ( $job['id'] ?? '' ) !== $job_id ) {
			$job = $this->start_pull( $key );
			if ( is_wp_error( $job ) ) {
				return $job;
			}
			update_option( self::PULL_OPTION, $job, false );
			return $this->pull_progress( $job, 'Read manifest: ' . count( $job['files'] ) . ' file(s) to download.' );
		}

		if ( 'download' === $job['phase'] ) {
			$file   = $job['files'][ $job['step'] ];
			$result = $this->cloud->get_file( $file['key'], $file['path'] );
			if ( is_wp_error( $result ) ) {
				return $this->abort_pull( $job, $result->get_error_message() );
			}
			if ( ! hash_equals( (string) $file['sha256'], (string) hash_file( 'sha256', $file['path'] ) ) ) {
				return $this->abort_pull( $job, sprintf( '%s failed its SHA-256 check after download.', basename( $file['key'] ) ) );
			}
			$job['step']++;
			$message = sprintf( 'Downloaded %s (%s), checksum OK.', basename( $file['key'] ), size_format( (int) filesize( $file['path'] ), 1 ) );
			if ( $job['step'] >= count( $job['files'] ) ) {
				$job['phase'] = 'import';
				$state        = ( new Importer( $this->snapshots ) )->start( $job['files'][0]['path'], $job['manifest'] );
				if ( is_wp_error( $state ) ) {
					return $this->abort_pull( $job, $state->get_error_message() );
				}
				$job['import'] = $state;
			}
			update_option( self::PULL_OPTION, $job, false );
			return $this->pull_progress( $job, $message );
		}

		// Import phase.
		$state  = $job['import'];
		$result = ( new Importer( $this->snapshots ) )->tick( $state, $deadline );
		if ( is_wp_error( $result ) ) {
			$job['import'] = null;
			return $this->abort_pull( $job, $result->get_error_message() );
		}
		$job['import'] = $state;
		if ( ! $result ) {
			update_option( self::PULL_OPTION, $job, false );
			return $this->pull_progress( $job, sprintf( 'Importing tables: %d statements so far.', $state['statements'] ) );
		}
		return $this->finish_pull( $job );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private function start_pull( string $key ) {
		$key = trim( $key );
		if ( '' === $key || false !== strpos( $key, '..' ) || 0 !== strpos( $key, $this->cloud->site_prefix() ) ) {
			return new WP_Error( 'wp_chrono_cloud_key', 'That remote backup does not belong to this site.', array( 'status' => 400 ) );
		}
		$key = trailingslashit( $key );
		$raw = $this->cloud->get_string( $key . 'manifest.json' );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$manifest = json_decode( $raw, true );
		if ( ! is_array( $manifest ) || 1 !== (int) ( $manifest['wpchrono_cloud'] ?? 0 ) || empty( $manifest['db']['key'] ) ) {
			return new WP_Error( 'wp_chrono_cloud_manifest', 'The remote manifest is missing or invalid.', array( 'status' => 502 ) );
		}
		global $wpdb;
		if ( (string) ( $manifest['table_prefix'] ?? $wpdb->prefix ) !== $wpdb->prefix ) {
			return new WP_Error( 'wp_chrono_cloud_prefix', sprintf( 'This backup uses table prefix %s, this site uses %s.', $manifest['table_prefix'], $wpdb->prefix ), array( 'status' => 409 ) );
		}

		$dir = Storage::ensure();
		$rb  = FileArchive::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		if ( is_wp_error( $rb ) ) {
			return $rb;
		}
		$files = array(
			array(
				'key'    => (string) $manifest['db']['key'],
				'path'   => $dir . Storage::filename( 'snapshot', 'sql.gz' ),
				'sha256' => (string) $manifest['db']['sha256'],
			),
		);
		foreach ( (array) ( $manifest['rollback'] ?? array() ) as $entry ) {
			if ( empty( $entry['key'] ) || 0 !== strpos( (string) $entry['key'], $key ) ) {
				continue;
			}
			$files[] = array(
				'key'    => (string) $entry['key'],
				'path'   => $rb . 'pull-' . strtolower( wp_generate_password( 8, false ) ) . '-' . basename( (string) $entry['zip'] ),
				'sha256' => (string) $entry['sha256'],
				'entry'  => $entry,
			);
		}
		$manifest['key'] = $key;
		return array(
			'id'       => strtolower( wp_generate_password( 6, false ) ),
			'key'      => $key,
			'phase'    => 'download',
			'step'     => 0,
			'files'    => $files,
			'manifest' => $manifest,
			'import'   => null,
		);
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function finish_pull( array $job ): array {
		$id = (int) $job['import']['snapshot_id'];
		$db = $job['files'][0];

		// Attach the dump as the snapshot's export and the archives as its
		// rollback manifest, renamed to this snapshot's ID.
		$rollback = array();
		foreach ( array_slice( $job['files'], 1 ) as $file ) {
			$entry = (array) $file['entry'];
			$name  = preg_replace( '/^snap-\d+-/', "snap-{$id}-", basename( (string) $entry['zip'] ) );
			$dest  = dirname( $file['path'] ) . '/' . $name;
			if ( is_file( $dest ) ) {
				$name = 'c' . strtolower( wp_generate_password( 4, false ) ) . '-' . $name;
				$dest = dirname( $file['path'] ) . '/' . $name;
			}
			rename( $file['path'], $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			unset( $entry['key'] );
			$entry['zip'] = $name;
			$rollback[]   = $entry;
		}
		$this->snapshots->attach_export( $id, basename( $db['path'] ) );
		if ( $rollback ) {
			$this->snapshots->update_meta( $id, array( 'rollback' => array( 'files' => $rollback, 'skipped' => array() ) ) );
		}
		delete_option( self::PULL_OPTION );

		$snapshot = $this->snapshots->get( $id );
		return array(
			'done'        => true,
			'phase'       => 'ready',
			'snapshot_id' => $id,
			'snapshot'    => $snapshot,
			'message'     => sprintf(
				'Pulled as snapshot #%d: %d tables, %s rows%s%s. Restore it like any other snapshot.',
				$id,
				$snapshot['table_count'],
				number_format_i18n( $snapshot['row_count'] ),
				$rollback ? sprintf( ', %d plugin/theme archive(s)', count( $rollback ) ) : '',
				! empty( $snapshot['meta']['verified'] ) ? ', verified' : ', VERIFICATION FAILED'
			),
		);
	}

	/**
	 * @param array<string,mixed> $job
	 */
	private function abort_pull( array $job, string $message ): WP_Error {
		foreach ( (array) $job['files'] as $file ) {
			if ( is_file( $file['path'] ) ) {
				@unlink( $file['path'] ); // phpcs:ignore
			}
		}
		delete_option( self::PULL_OPTION );
		return new WP_Error( 'wp_chrono_cloud_pull', $message, array( 'status' => 502 ) );
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function pull_progress( array $job, string $message ): array {
		return array(
			'done'    => false,
			'phase'   => $job['phase'],
			'job'     => $job['id'],
			'step'    => $job['step'],
			'total'   => count( $job['files'] ),
			'message' => $message,
		);
	}
}
