<?php
/**
 * Zero-downtime restore.
 *
 * 1. Staging: snapshot copies are chunk-copied into `_wpc_stg_{job}_*` tables
 *    across resumable ticks. The snapshot itself is never consumed, so it can
 *    be restored again.
 * 2. Swap: one multi-table `RENAME TABLE` moves live tables to
 *    `_wpc_old_{job}_*` and staging tables into their place. RENAME is atomic,
 *    so the site sees either the old or the restored database, never a mix.
 * 3. Repair: WP Chrono's own options, DB-stored salts and the acting admin's
 *    account + session are written back, the auth cookie is re-issued and
 *    fresh nonces are returned, so the SPA stays signed in.
 *
 * Job state lives in the `wp_chrono_restore_job` option.
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Snapshot;

use WP_Error;
use WPChrono\Core\Schema;
use WPChrono\Services\Database;
use WPChrono\Services\Session;
use WPChrono\Services\SnapshotService;

defined( 'ABSPATH' ) || exit;

final class Restorer {

	const OPTION = 'wp_chrono_restore_job';

	/** A job untouched for this long is considered abandoned. */
	const STALE_AFTER = HOUR_IN_SECONDS;

	/** @var SnapshotService */
	private $snapshots;

	public function __construct( SnapshotService $snapshots ) {
		$this->snapshots = $snapshots;
	}

	/**
	 * The running job, or null. Stale jobs are reported as null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function active_job(): ?array {
		$job = get_option( self::OPTION );
		if ( ! is_array( $job ) || empty( $job['id'] ) ) {
			return null;
		}
		return ( time() - (int) $job['touched'] ) > self::STALE_AFTER ? null : $job;
	}

	/**
	 * @param bool $with_files Also revert the plugin / theme folders archived
	 *                         with this snapshot. They are verified and staged
	 *                         now, and swapped in right after the table swap.
	 * @param bool $include_network Multisite main site: also restore the shared
	 *                         network tables (users, blogs, …). Off by default.
	 * @return array<string,mixed>|WP_Error
	 */
	public function start( int $snapshot_id, bool $drop_extra, bool $with_files = false, bool $include_network = false ) {
		global $wpdb;
		$snapshot = $this->snapshots->get( $snapshot_id );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( 'ready' !== $snapshot['status'] ) {
			return new WP_Error( 'wp_chrono_snapshot_state', sprintf( 'Snapshot #%d is not ready (status: %s).', $snapshot_id, $snapshot['status'] ), array( 'status' => 409 ) );
		}
		if ( self::active_job() ) {
			return new WP_Error( 'wp_chrono_restore_running', __( 'Another restore is already in progress.', 'wp-chrono' ), array( 'status' => 409 ) );
		}
		$this->cancel(); // Clears any stale job's staging tables.

		$tables  = $this->snapshots->tables( $snapshot_id );
		$network = $include_network ? array() : Database::network_tables();
		$tables  = array_diff_key( $tables, array_flip( $network ) );
		foreach ( $tables as $orig => $row ) {
			if ( 0 !== strpos( $orig, $wpdb->prefix ) ) {
				return new WP_Error( 'wp_chrono_prefix_mismatch', sprintf( 'Snapshot table %s does not use this site\'s prefix (%s).', $orig, $wpdb->prefix ), array( 'status' => 409 ) );
			}
			if ( ! TableCopier::exists( $row['snapshot_table'] ) ) {
				return new WP_Error( 'wp_chrono_snapshot_damaged', sprintf( 'Snapshot table %s is missing; this snapshot cannot be restored.', $row['snapshot_table'] ), array( 'status' => 409 ) );
			}
		}

		$job_id = strtolower( wp_generate_password( 6, false ) );
		$staged = array();
		if ( $with_files ) {
			$entries = SnapshotService::rollback_files( $snapshot );
			if ( ! $entries ) {
				return new WP_Error( 'wp_chrono_no_rollback_files', sprintf( 'Snapshot #%d has no plugin or theme files to revert. Restore the database only.', $snapshot_id ), array( 'status' => 409 ) );
			}
			$staged = FileArchive::stage( $entries, $job_id );
			if ( is_wp_error( $staged ) ) {
				return $staged;
			}
		}

		$job = array(
			'id'          => $job_id,
			'snapshot_id' => $snapshot_id,
			'drop_extra'  => $drop_extra,
			'started'     => microtime( true ),
			'touched'     => time(),
			'files'       => $staged,
			'network'     => $network, // Shared tables left untouched (never "extra" either).
			'tables'      => array(),
		);
		foreach ( $tables as $orig => $row ) {
			$job['tables'][] = array(
				'orig'     => $orig,
				'snap'     => $row['snapshot_table'],
				'stg'      => Schema::snapshot_table_name( 'stg_' . $job_id, $orig ),
				'comment'  => $row['orig_comment'],
				'fks'      => $row['foreign_keys'] ? (array) json_decode( $row['foreign_keys'], true ) : array(),
				'total'    => (int) $row['row_count'],
				'mode'     => '',
				'keys'     => array(),
				'last_key' => '',
				'rows'     => 0,
				'done'     => false,
			);
		}
		update_option( self::OPTION, $job, false );

		return $this->progress( $job );
	}

	/**
	 * One tick: stage more rows, or swap when staging is complete.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function tick( string $job_id, float $budget ) {
		global $wpdb;
		$job = self::active_job();
		if ( ! $job || $job['id'] !== $job_id ) {
			return new WP_Error( 'wp_chrono_restore_missing', __( 'This restore job no longer exists. Start the restore again.', 'wp-chrono' ), array( 'status' => 404 ) );
		}

		$deadline = microtime( true ) + $budget;
		$current  = '';
		foreach ( $job['tables'] as &$table ) {
			if ( $table['done'] ) {
				continue;
			}
			$current = $table['orig'];
			$stg     = Database::quote_identifier( $table['stg'] );

			if ( '' === $table['mode'] ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$stg}" ); // phpcs:ignore WordPress.DB
				if ( false === $wpdb->query( "CREATE TABLE {$stg} LIKE " . Database::quote_identifier( $table['snap'] ) ) ) { // phpcs:ignore WordPress.DB
					return $this->abort( $job, "Could not create staging table for {$table['orig']}: {$wpdb->last_error}" );
				}
				$strategy       = TableCopier::strategy( $table['snap'] );
				$table['mode']  = $strategy['mode'];
				$table['keys']  = $strategy['keys'];
			}

			$state = array(
				'mode'     => $table['mode'],
				'keys'     => $table['keys'],
				'columns'  => TableCopier::column_list( $table['snap'] ),
				'last_key' => $table['last_key'],
				'rows'     => $table['rows'],
			);
			do {
				$finished = TableCopier::copy_batch( $table['snap'], $table['stg'], $state );
				if ( null === $finished ) {
					return $this->abort( $job, "Staging copy of {$table['orig']} failed: {$wpdb->last_error}" );
				}
				$table['last_key'] = $state['last_key'];
				$table['rows']     = $state['rows'];
				if ( $finished ) {
					// Swap the snapshot's JSON tag for the table's original comment.
					TableCopier::set_comment( $table['stg'], (string) $table['comment'] );
					$table['done'] = true;
				}
				if ( microtime( true ) >= $deadline ) {
					break 2;
				}
			} while ( ! $finished );
		}
		unset( $table );

		$job['touched'] = time();
		update_option( self::OPTION, $job, false );

		foreach ( $job['tables'] as $table ) {
			if ( ! $table['done'] ) {
				return $this->progress( $job, $current );
			}
		}

		return $this->swap( $job );
	}

	/**
	 * Drops staging tables and forgets the job.
	 */
	public function cancel(): void {
		global $wpdb;
		$job = get_option( self::OPTION );
		if ( is_array( $job ) && ! empty( $job['id'] ) ) {
			$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( Schema::SNAPSHOT_TABLE_PREFIX . 'stg_' . $job['id'] . '_' ) . '%' ) ); // phpcs:ignore WordPress.DB
			Database::without_foreign_keys(
				static function () use ( $tables, $wpdb ) {
					foreach ( $tables as $table ) {
						$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::quote_identifier( $table ) ); // phpcs:ignore WordPress.DB
					}
				}
			);
			if ( ! empty( $job['files'] ) ) {
				FileArchive::discard( (array) $job['files'], (string) $job['id'] );
			}
		}
		delete_option( self::OPTION );
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>|WP_Error
	 */
	private function swap( array $job ) {
		global $wpdb;
		$swap_started = microtime( true );

		// --- Capture what must survive the swap -----------------------------
		$admin = Session::acting_admin();
		if ( is_wp_error( $admin ) ) {
			return $admin;
		}
		$user_id  = (int) $admin->ID;
		$user_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID = %d", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$cap_key  = $wpdb->get_blog_prefix() . 'capabilities';
		$level    = $wpdb->get_blog_prefix() . 'user_level';
		$user_meta = array(
			$cap_key => get_user_meta( $user_id, $cap_key, true ),
			$level   => get_user_meta( $user_id, $level, true ),
		);
		$sessions = get_user_meta( $user_id, 'session_tokens', true );
		$options  = Session::capture_options( Session::SALT_OPTIONS, array( 'wp_chrono_' ) );
		unset( $options[ self::OPTION ] );

		// --- Atomic swap -----------------------------------------------------
		$live    = array_keys( Database::site_tables() );
		$origs   = array_column( $job['tables'], 'orig' );
		$renames = array();
		foreach ( $job['tables'] as $table ) {
			if ( in_array( $table['orig'], $live, true ) ) {
				$renames[] = $this->rename_clause( $table['orig'], Schema::snapshot_table_name( 'old_' . $job['id'], $table['orig'] ) );
			}
			$renames[] = $this->rename_clause( $table['stg'], $table['orig'] );
		}
		$kept  = array_values( (array) ( $job['network'] ?? array() ) );
		$extra = array_values( array_diff( $live, $origs, $kept ) );
		if ( $job['drop_extra'] ) {
			foreach ( $extra as $table ) {
				$renames[] = $this->rename_clause( $table, Schema::snapshot_table_name( 'old_' . $job['id'], $table ) );
			}
		}

		// One FOREIGN_KEY_CHECKS=0 window covers the swap, dropping the parked
		// tables and re-adding foreign keys. The parked tables go first because
		// they still hold constraints whose names must be unique per database.
		$log      = $kept ? array( sprintf( 'Kept %d network-wide tables untouched (%s); subsite users and blogs are unaffected.', count( $kept ), implode( ', ', $kept ) ) ) : array();
		$old_like = $wpdb->esc_like( Schema::SNAPSHOT_TABLE_PREFIX . 'old_' . $job['id'] . '_' ) . '%';
		$swap_ms  = 0;
		$offline  = ! empty( $job['files'] ) && FileArchive::maintenance_on();
		$swapped  = Database::without_foreign_keys(
			static function () use ( $renames, $job, $old_like, $wpdb, $swap_started, &$swap_ms, &$log ) {
				// phpcs:disable WordPress.DB
				if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) {
					return false;
				}
				$swap_ms = (int) round( ( microtime( true ) - $swap_started ) * 1000 );

				foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_like ) ) as $table ) {
					$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::quote_identifier( $table ) );
				}

				$added = 0;
				foreach ( $job['tables'] as $table ) {
					foreach ( (array) ( $table['fks'] ?? array() ) as $fk ) {
						if ( false === $wpdb->query( 'ALTER TABLE ' . Database::quote_identifier( $table['orig'] ) . ' ADD ' . $fk ) ) {
							$log[] = "Could not re-add a foreign key on {$table['orig']}: {$wpdb->last_error}";
						} else {
							$added++;
						}
					}
				}
				if ( $added ) {
					$log[] = sprintf( 'Re-created %d foreign key constraint(s).', $added );
				}
				// phpcs:enable
				return true;
			}
		);
		if ( false === $swapped ) {
			FileArchive::maintenance_off( $offline );
			// RENAME TABLE is all-or-nothing: the live site is untouched. Keep
			// staging (tables and files) so the swap can be retried.
			return new WP_Error( 'wp_chrono_swap_failed', 'Atomic swap failed; the live database and files were not changed. ' . $wpdb->last_error, array( 'status' => 500 ) );
		}
		wp_cache_flush();

		// The database now matches the snapshot; put back the matching files.
		$files_restored = 0;
		$stale_opcache  = false;
		if ( ! empty( $job['files'] ) ) {
			$files          = FileArchive::commit( (array) $job['files'] );
			$files_restored = $files['restored'];
			$stale_opcache  = $files_restored > 0 && empty( $files['opcache_cleared'] );
			$log            = array_merge( $log, $files['log'] );
			FileArchive::discard( array(), (string) $job['id'] );
		}
		FileArchive::maintenance_off( $offline, $stale_opcache );

		// --- Repair the restored database ------------------------------------
		Session::restore_options( $options );
		delete_option( self::OPTION );
		$active = (array) get_option( 'active_plugins', array() );
		if ( ! in_array( WP_CHRONO_BASENAME, $active, true ) ) {
			$active[] = WP_CHRONO_BASENAME;
			update_option( 'active_plugins', $active );
			$log[] = 'Re-activated WP Chrono (it was inactive in the snapshot).';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( $user_row && ! $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID = %d", $user_id ) ) ) {
			$wpdb->insert( $wpdb->users, $user_row );
			foreach ( $user_meta as $key => $value ) {
				update_user_meta( $user_id, $key, $value );
			}
			$log[] = sprintf( 'Your account (%s) did not exist in the snapshot and was re-created.', $user_row['user_login'] );
		}
		// phpcs:enable
		$restored_sessions = get_user_meta( $user_id, 'session_tokens', true );
		update_user_meta(
			$user_id,
			'session_tokens',
			array_merge( is_array( $restored_sessions ) ? $restored_sessions : array(), is_array( $sessions ) ? $sessions : array() )
		);
		clean_user_cache( $user_id );
		wp_cache_flush();

		$nonces = Session::refresh( $user_id );

		$this->snapshots->update_meta( (int) $job['snapshot_id'], array( 'last_restored_at' => gmdate( 'c' ) ) );
		do_action( 'wp_chrono_snapshot_restored', $job['snapshot_id'] );

		return array(
			'done'            => true,
			'phase'           => 'swapped',
			'snapshot_id'     => $job['snapshot_id'],
			'tables_restored' => count( $job['tables'] ),
			'tables_removed'  => $job['drop_extra'] ? count( $extra ) : 0,
			'tables_kept'     => $job['drop_extra'] ? array() : $extra,
			'rows_copied'     => array_sum( array_column( $job['tables'], 'rows' ) ),
			'files_restored'  => $files_restored,
			'swap_ms'         => $swap_ms,
			'duration_ms'     => (int) round( ( microtime( true ) - (float) $job['started'] ) * 1000 ),
			'nonces'          => $nonces,
			'log'             => $log,
			'redirect'        => admin_url( 'admin.php?page=wp-chrono#/snapshots' ),
		);
	}

	private function rename_clause( string $from, string $to ): string {
		return Database::quote_identifier( $from ) . ' TO ' . Database::quote_identifier( $to );
	}

	/**
	 * @param array<string,mixed> $job
	 */
	private function abort( array $job, string $message ): WP_Error {
		$this->cancel();
		return new WP_Error( 'wp_chrono_restore_failed', $message . ' The live database was not changed.', array( 'status' => 500 ) );
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function progress( array $job, string $current = '' ): array {
		$done = 0;
		foreach ( $job['tables'] as $table ) {
			$done += $table['done'] ? 1 : 0;
		}
		return array(
			'done'          => false,
			'phase'         => 'staging',
			'job'           => $job['id'],
			'snapshot_id'   => $job['snapshot_id'],
			'tables_total'  => count( $job['tables'] ),
			'tables_done'   => $done,
			'rows_copied'   => array_sum( array_column( $job['tables'], 'rows' ) ),
			'rows_total'    => array_sum( array_column( $job['tables'], 'total' ) ),
			'current_table' => $current,
			'cursor'        => $done,
		);
	}
}
