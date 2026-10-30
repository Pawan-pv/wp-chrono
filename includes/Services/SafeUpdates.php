<?php
/**
 * Safe Updates: notices when a plugin / theme update breaks the site and
 * undoes it automatically — files AND database — from the snapshot WP
 * Chrono takes before every update batch.
 *
 * 1. Before the batch (SnapshotService::before_update) the site is probed
 *    once: a loopback request to the home page (and to wp-admin with the
 *    updating admin's cookies) using WordPress's own fatal-error "scrape"
 *    (`wp_scrape_key`), the mechanism core uses for its auto-update rollback.
 *    The result is the baseline, stored on the snapshot.
 * 2. When the batch is done the probe runs again: `upgrader_process_complete`
 *    for updates from wp-admin / WP-CLI, `_core_updated_successfully` for
 *    WordPress core (wp-admin exits right after it), `automatic_updates_complete`
 *    for background auto-updates (after core's own per-plugin check).
 * 3. A page that worked before and now has a PHP fatal error or a 5xx — or a
 *    plugin core already had to roll back — means the batch is undone with
 *    Full Undo, the admin is told (updater screen / WP-CLI, admin notice,
 *    email) and that exact version is no longer auto-updated.
 *
 * Never acts on uncertainty: if the baseline already failed or a probe gets
 * no answer (loopback blocked, timeout), the result is "unverified" and
 * nothing is undone. A failure must repeat on every confirmation probe, and
 * HTTP 503 (maintenance / overload) never counts. When visitors created
 * records since the snapshot (orders, comments, users…), only the files are
 * put back so their data is never lost; the admin is told and can still run
 * a Full Undo by hand. State lives in the snapshot meta (WP Chrono's own
 * tables survive the database restore); the notice and the auto-update block
 * are written after the restore.
 *
 * @package WPChrono
 */

namespace WPChrono\Services;

use WP_Error;
use WPChrono\Services\Snapshot\FileArchive;

defined( 'ABSPATH' ) || exit;

final class SafeUpdates {

	/** {notice: ?array, blocked: array<string,string>} — "plugin:<file>" / "theme:<slug>" => version. */
	const STATE = 'wp_chrono_safe_updates';

	const FATAL_TYPES = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );

	/** Core 6.6+ auto-update results that mean "this update fataled". */
	const CORE_ROLLBACK_CODES = array( 'plugin_update_fatal_error_rollback_successful', 'plugin_update_fatal_error_rollback_failed' );

	/** @var array<int,bool> Snapshots already checked in this request. */
	private static $checked = array();

	public static function register(): void {
		add_action( 'upgrader_process_complete', array( self::class, 'after_batch' ), 20, 2 );
		add_action( '_core_updated_successfully', array( self::class, 'after_core_update' ), 5 );
		add_action( 'automatic_updates_complete', array( self::class, 'after_auto_updates' ) );
		add_filter( 'auto_update_plugin', array( self::class, 'allow_auto_update' ), 20, 2 );
		add_filter( 'auto_update_theme', array( self::class, 'allow_auto_update' ), 20, 2 );
		add_filter( 'auto_update_core', array( self::class, 'allow_auto_update' ), 20, 2 );
		if ( is_admin() ) {
			add_action( 'admin_notices', array( self::class, 'notice' ) );
			add_action( 'admin_init', array( self::class, 'maybe_dismiss' ) );
		}
	}

	public static function enabled(): bool {
		return Settings::get()['auto_snapshot'] && Settings::get()['safe_updates'];
	}

	// ---------------------------------------------------------------------
	// Probing
	// ---------------------------------------------------------------------

	/**
	 * Loads the home page (and wp-admin when an admin is updating
	 * interactively) in fresh PHP processes and reports fatal errors.
	 *
	 * @return array{front:array<string,mixed>,admin:?array<string,mixed>,at:string}
	 */
	public static function probe(): array {
		$cookies = array();
		if ( is_user_logged_in() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$cookies = wp_unslash( $_COOKIE ); // Same as core's plugin-editor loopback.
		}
		return array(
			'front' => self::probe_url( home_url( '/' ), array() ),
			'admin' => $cookies ? self::probe_url( admin_url( '/' ), $cookies ) : null,
			'at'    => gmdate( 'c' ),
		);
	}

	/**
	 * @param array<string,string> $cookies
	 * @return array{ok:?bool,status:int,error:?array<string,mixed>,reason:string}
	 */
	private static function probe_url( string $url, array $cookies ): array {
		$result = self::probe_once( $url, $cookies );
		if ( 503 === $result['status'] && null !== self::maintenance_timestamp() ) {
			// The web server's OPcache can still hold an older `.maintenance`
			// (another timestamp, e.g. from the previous update's or undo's
			// maintenance window), so our bypass key was refused. Wait until
			// OPcache re-reads the file, then try once more.
			sleep( min( 10, max( 0, (int) ini_get( 'opcache.revalidate_freq' ) ) + 1 ) );
			$result = self::probe_once( $url, $cookies );
		}
		return $result;
	}

	/**
	 * @param array<string,string> $cookies
	 * @return array{ok:?bool,status:int,error:?array<string,mixed>,reason:string}
	 */
	private static function probe_once( string $url, array $cookies ): array {
		// While `.maintenance` exists (bulk / multisite updates), only a key
		// derived from its timestamp gets past the maintenance page.
		$upgrading = self::maintenance_timestamp();
		if ( null !== $upgrading ) {
			$key   = md5( (string) $upgrading );
			$nonce = (string) $upgrading;
		} else {
			$key   = md5( wp_generate_password( 32, false ) );
			$nonce = wp_generate_password( 20, false );
		}
		set_transient( 'scrape_key_' . $key, $nonce, 60 );

		/**
		 * URL the Safe Updates probe loads (before the scrape arguments are added).
		 *
		 * @param string $url  home_url( '/' ) or admin_url( '/' ).
		 * @param string $kind 'front' or 'admin'.
		 */
		$url     = (string) apply_filters( 'wp_chrono_safe_updates_probe_url', $url, empty( $cookies ) ? 'front' : 'admin' );
		$headers = array( 'Cache-Control' => 'no-cache' );
		if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.ValidatedSanitizedInput -- loopback Basic auth, as core does.
		}
		$response = wp_remote_get(
			add_query_arg( array( 'wp_scrape_key' => $key, 'wp_scrape_nonce' => $nonce ), $url ),
			array(
				'timeout'     => 30,
				'redirection' => 0, // The scrape result is printed even on a redirect; following one could leave the host.
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'cookies'     => $cookies,
				'headers'     => $headers,
			)
		);
		delete_transient( 'scrape_key_' . $key );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => null, 'status' => 0, 'error' => null, 'reason' => 'No answer: ' . $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$start  = "###### wp_scraping_result_start:$key ######";
		$pos    = strpos( $body, $start );
		$result = null;
		if ( false !== $pos ) {
			$rest   = substr( $body, $pos + strlen( $start ) );
			$end    = strpos( $rest, "###### wp_scraping_result_end:$key ######" );
			$result = json_decode( trim( false === $end ? $rest : substr( $rest, 0, $end ) ), true );
		}

		if ( is_array( $result ) && isset( $result['type'] ) && in_array( (int) $result['type'], self::FATAL_TYPES, true ) ) {
			$error = array(
				'message' => wp_strip_all_tags( (string) ( $result['message'] ?? '' ) ),
				'file'    => (string) ( $result['file'] ?? '' ),
				'line'    => (int) ( $result['line'] ?? 0 ),
			);
			return array( 'ok' => false, 'status' => $status, 'error' => $error, 'reason' => 'PHP fatal error' );
		}
		if ( 503 === $status ) {
			// Maintenance mode / overload (often a plugin migrating its tables
			// right after an update): not proof of a broken update.
			return array( 'ok' => null, 'status' => $status, 'error' => null, 'reason' => 'HTTP 503 (maintenance or overload)' );
		}
		if ( $status >= 500 ) {
			return array( 'ok' => false, 'status' => $status, 'error' => null, 'reason' => "HTTP $status" );
		}
		if ( true === $result ) {
			return array( 'ok' => true, 'status' => $status, 'error' => null, 'reason' => "HTTP $status" );
		}
		// No scrape result (e.g. a cache answered, or the nonce check failed): don't guess.
		return array( 'ok' => null, 'status' => $status, 'error' => null, 'reason' => "HTTP $status without a WordPress scrape result" );
	}

	private static function maintenance_timestamp(): ?int {
		$file = ABSPATH . '.maintenance';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$upgrading = null;
		include $file; // Defines $upgrading, like wp_is_maintenance_mode().
		return is_int( $upgrading ) ? $upgrading : null;
	}

	// ---------------------------------------------------------------------
	// The update lifecycle
	// ---------------------------------------------------------------------

	/**
	 * Called by SnapshotService::before_update() once per batch, with the
	 * probe taken before anything was replaced.
	 *
	 * @param array<string,mixed> $baseline From probe().
	 */
	public static function watch( int $snapshot_id, array $baseline ): void {
		( new SnapshotService() )->update_meta( $snapshot_id, array( 'safe_update' => array( 'status' => 'watching', 'baseline' => $baseline ) ) );
	}

	/**
	 * `upgrader_process_complete` (wp-admin, WP-CLI, REST). Background
	 * auto-updates are checked once at the end of the run instead.
	 *
	 * @param \WP_Upgrader        $upgrader
	 * @param array<string,mixed> $hook_extra
	 */
	public static function after_batch( $upgrader, $hook_extra ): void {
		$hook_extra = (array) $hook_extra;
		if ( 'update' !== ( $hook_extra['action'] ?? '' ) || ! in_array( $hook_extra['type'] ?? '', array( 'plugin', 'theme', 'core' ), true ) || did_action( 'pre_auto_update' ) ) {
			return;
		}
		$id = self::watched();
		if ( ! $id || isset( self::$checked[ $id ] ) ) {
			return;
		}
		$outcome = self::check( $id );
		if ( isset( $outcome['message'] ) && 'passed' !== $outcome['status'] && 'unverified' !== $outcome['status'] ) {
			self::tell_updater( $upgrader, (string) $outcome['message'] );
		}
	}

	/**
	 * `_core_updated_successfully` (priority 5, before SnapshotService records
	 * nothing more and before wp-admin's redirect to the About page exits).
	 * After an undo the redirect is dropped so the update screen shows why.
	 */
	public static function after_core_update(): void {
		if ( did_action( 'pre_auto_update' ) ) {
			return;
		}
		$id = self::watched();
		if ( ! $id || isset( self::$checked[ $id ] ) ) {
			return;
		}
		$outcome = self::check( $id );
		if ( in_array( $outcome['status'], array( 'undone', 'undo_failed' ), true ) ) {
			remove_action( '_core_updated_successfully', '_redirect_to_about_wordpress' );
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				\WP_CLI::warning( (string) $outcome['message'] );
			} elseif ( function_exists( 'show_message' ) ) {
				show_message( '<strong>' . esc_html( (string) $outcome['message'] ) . '</strong>' );
			}
		}
	}

	/**
	 * `automatic_updates_complete`: one check for the whole run. A plugin that
	 * core already rolled back (files only) counts as broken, so the database
	 * goes back too.
	 *
	 * @param array<string,array<int,object>> $results
	 */
	public static function after_auto_updates( $results ): void {
		$id = self::watched();
		if ( ! $id ) {
			return;
		}
		$core = array();
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( (array) ( $results[ $type ] ?? array() ) as $update ) {
				$result = $update->result ?? null;
				if ( is_wp_error( $result ) && array_intersect( $result->get_error_codes(), self::CORE_ROLLBACK_CODES ) ) {
					$core[] = (string) ( $update->name ?? $update->item->slug ?? '' );
				}
			}
		}
		self::check( $id, $core );
	}

	/** The snapshot of the batch in progress, if Safe Updates is watching it. */
	private static function watched(): int {
		if ( ! self::enabled() ) {
			return 0;
		}
		$lock = get_transient( SnapshotService::AUTO_LOCK );
		$id   = is_array( $lock ) ? (int) ( $lock['snapshot'] ?? 0 ) : 0;
		if ( ! $id ) {
			return 0;
		}
		$snapshot = ( new SnapshotService() )->get( $id );
		$state    = is_wp_error( $snapshot ) ? array() : (array) ( $snapshot['meta']['safe_update'] ?? array() );
		return isset( $state['baseline'] ) && 'undone' !== ( $state['status'] ?? '' ) ? $id : 0;
	}

	/**
	 * Probes the updated site and undoes the batch when it broke.
	 *
	 * @param string[] $core_rollbacks Items core's auto-updater already rolled back.
	 * @return array<string,mixed> The outcome recorded on the snapshot.
	 */
	public static function check( int $snapshot_id, array $core_rollbacks = array() ): array {
		$service  = new SnapshotService();
		$snapshot = $service->get( $snapshot_id );
		if ( is_wp_error( $snapshot ) ) {
			return array( 'status' => 'unverified', 'message' => $snapshot->get_error_message() );
		}
		self::$checked[ $snapshot_id ] = true;
		$state    = (array) ( $snapshot['meta']['safe_update'] ?? array() );
		$baseline = (array) ( $state['baseline'] ?? array() );
		$items    = self::items( $snapshot );

		if ( true !== ( $baseline['front']['ok'] ?? null ) && ! $core_rollbacks ) {
			return self::record( $snapshot_id, $state, array( 'status' => 'unverified', 'message' => 'Safe Updates could not check this site before the update (' . ( $baseline['front']['reason'] ?? 'no baseline' ) . '); nothing was undone.' ) );
		}

		/**
		 * Seconds to wait before probing, so PHP-FPM workers see the new code:
		 * one OPcache revalidation interval plus a second (at least 2 s, what
		 * core's auto-updater waits). Exactly revalidate_freq can still hit the
		 * old cached file.
		 *
		 * @param int $seconds
		 */
		$opcache = filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN ) || 'cli' === PHP_SAPI;
		$delay   = (int) apply_filters( 'wp_chrono_safe_updates_delay', max( 2, $opcache ? (int) ini_get( 'opcache.revalidate_freq' ) + 1 : 2 ) );
		if ( $delay > 0 ) {
			sleep( min( 10, $delay ) );
		}
		$after  = self::probe();
		$broken = $core_rollbacks ? 'WordPress rolled back ' . implode( ', ', $core_rollbacks ) . ' after a fatal error' : self::regression( $baseline, $after );

		// A single failed request is not enough: proxies, cold caches and
		// workers busy with the update all produce one-off 5xx. The failure
		// must repeat on every confirmation probe before anything is undone.
		/**
		 * Extra probes that must fail too before an update is undone.
		 *
		 * @param int $retries Default 2 (three failing probes in total).
		 */
		$retries = $core_rollbacks ? 0 : max( 0, min( 5, (int) apply_filters( 'wp_chrono_safe_updates_retries', 2 ) ) );
		for ( $try = 0; null !== $broken && $try < $retries; $try++ ) {
			/**
			 * Seconds between confirmation probes.
			 *
			 * @param int $seconds
			 */
			$pause = (int) apply_filters( 'wp_chrono_safe_updates_retry_delay', 3 );
			if ( $pause > 0 ) {
				sleep( min( 15, $pause ) );
			}
			$after  = self::probe();
			$broken = self::regression( $baseline, $after ); // Recovered or no answer: nothing is undone.
		}

		if ( null === $broken ) {
			$verified = true === $after['front']['ok'];
			return self::record(
				$snapshot_id,
				$state,
				array(
					'status'  => $verified ? 'passed' : 'unverified',
					'after'   => $after,
					'message' => $verified ? 'The site still works after the update.' : 'Safe Updates could not check the site after the update (' . $after['front']['reason'] . '); nothing was undone.',
				)
			);
		}

		$culprit = self::culprit( $after, $items );
		$what    = $culprit ? sprintf( '%s %s', $culprit['name'] ?: $culprit['slug'], $culprit['new_version'] ?? '' ) : self::summary( $items );
		$error   = $after['front']['error'] ?? $after['admin']['error'] ?? null;

		// Never throw away what visitors created since the snapshot (orders,
		// comments, sign-ups…): then only the code is put back.
		$fresh = self::new_records( $snapshot_id );
		/**
		 * Undo mode Safe Updates uses: 'full' (files + database) or 'files'.
		 *
		 * @param string            $mode        'files' when new records were found, else 'full'.
		 * @param array<string,int> $fresh       New records since the snapshot, by label.
		 * @param int               $snapshot_id
		 */
		$mode = 'files' === apply_filters( 'wp_chrono_safe_updates_undo_mode', $fresh ? 'files' : 'full', $fresh, $snapshot_id ) ? 'files' : 'full';

		$undo = $service->undo_update( $snapshot_id, $mode, false, false, true );
		delete_transient( SnapshotService::AUTO_LOCK ); // The restored options may hold a stale lock.
		$ok      = ! is_wp_error( $undo );
		$recheck = $ok ? self::probe() : null;

		$outcome = array(
			'status'   => $ok ? 'undone' : 'undo_failed',
			'reason'   => $broken,
			'error'    => $error,
			'culprit'  => $culprit,
			'items'    => $items,
			'after'    => $after,
			'recheck'  => $recheck,
			'undo'     => $ok ? array( 'tables' => (int) ( $undo['tables_restored'] ?? 0 ), 'files' => (int) ( $undo['files_restored'] ?? 0 ) ) : $undo->get_error_message(),
			'mode'     => $mode,
			'fresh'    => $fresh,
			'snapshot' => $snapshot_id,
		);
		$kept = array();
		foreach ( $fresh as $label => $count ) {
			$kept[] = sprintf( '%d %s', $count, $label );
		}
		$outcome['message'] = $ok
			? ( 'full' === $mode
				? sprintf( 'WP Chrono Safe Updates: the update to %s broke the site (%s), so it was undone automatically — files and database are back to the state from just before the update.', $what, self::describe( $broken, $error ) )
				: sprintf( 'WP Chrono Safe Updates: the update to %s broke the site (%s), so its files were put back automatically. The database was NOT rolled back because %s arrived after the update started and a rollback would delete them. If the site still misbehaves (the update may also have changed the database), use "Undo update" on snapshot #%d; that removes those new records.', $what, self::describe( $broken, $error ), implode( ', ', $kept ), $snapshot_id ) )
			: sprintf( 'WP Chrono Safe Updates: the update to %s broke the site (%s) and the automatic undo FAILED: %s. Use "Undo update" on snapshot #%d or the Emergency Recovery Script.', $what, self::describe( $broken, $error ), $undo->get_error_message(), $snapshot_id );

		self::record( $snapshot_id, $state, $outcome );
		self::remember( $outcome );
		self::email( $outcome );
		return $outcome;
	}

	/**
	 * Records created on the live site after the snapshot, in the tables where
	 * visitors' data lives. Rows above the snapshot's highest ID count; edits
	 * are not detected (the window between snapshot and check is short).
	 *
	 * @return array<string,int> Label => count, non-zero entries only.
	 */
	public static function new_records( int $snapshot_id ): array {
		global $wpdb;
		$watch = array(
			'posts'     => array( 'new post(s), page(s) or order(s)', "post_type NOT IN ('revision','nav_menu_item','customize_changeset','oembed_cache','wp_global_styles','user_request','scheduled-action') AND post_status NOT IN ('auto-draft','inherit','trash')" ),
			'comments'  => array( 'new comment(s) or order note(s)', "comment_type <> 'action_log'" ),
			'users'     => array( 'new user account(s)', '' ),
			'wc_orders' => array( 'new WooCommerce order(s)', '' ),
		);
		/**
		 * Tables whose new rows block an automatic database rollback. Keys are
		 * table names without the prefix; values are [label, extra SQL condition].
		 *
		 * @param array<string,array{0:string,1:string}> $watch
		 */
		$watch  = (array) apply_filters( 'wp_chrono_safe_updates_watch_tables', $watch );
		$tables = ( new SnapshotService() )->tables( $snapshot_id );
		$found  = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $watch as $suffix => $spec ) {
			$live = ( 'users' === $suffix ? $wpdb->base_prefix : $wpdb->prefix ) . $suffix;
			if ( ! isset( $tables[ $live ] ) || ! is_array( $spec ) ) {
				continue;
			}
			$pk = $wpdb->get_col( $wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_KEY = %s AND EXTRA LIKE %s', $wpdb->dbname, $live, 'PRI', '%auto_increment%' ) );
			if ( 1 !== count( $pk ) ) {
				continue;
			}
			$max   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(%i),0) FROM %i', (string) $pk[0], (string) $tables[ $live ]['snapshot_table'] ) );
			$extra = '' !== (string) ( $spec[1] ?? '' ) ? ' AND (' . $spec[1] . ')' : ''; // Fixed SQL from code (filter), never user input.
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i > %d' . $extra, $live, (string) $pk[0], $max ) );
			if ( $count > 0 ) {
				$found[ (string) $spec[0] ] = ( $found[ (string) $spec[0] ] ?? 0 ) + $count;
			}
		}
		// phpcs:enable
		return $found;
	}

	/**
	 * Why the site counts as broken, or null.
	 *
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 */
	private static function regression( array $before, array $after ): ?string {
		foreach ( array( 'front' => 'the home page', 'admin' => 'wp-admin' ) as $kind => $label ) {
			if ( true === ( $before[ $kind ]['ok'] ?? null ) && false === ( $after[ $kind ]['ok'] ?? null ) ) {
				return $label . ': ' . $after[ $kind ]['reason'];
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $outcome
	 * @return array<string,mixed>
	 */
	private static function record( int $snapshot_id, array $state, array $outcome ): array {
		$outcome['checked_at'] = gmdate( 'c' );
		( new SnapshotService() )->update_meta( $snapshot_id, array( 'safe_update' => array_merge( $state, $outcome ) ) );
		return $outcome;
	}

	/**
	 * What the batch updated (from the rollback manifest).
	 *
	 * @param array<string,mixed> $snapshot
	 * @return array<int,array<string,mixed>>
	 */
	private static function items( array $snapshot ): array {
		$items = array();
		$core  = false;
		foreach ( SnapshotService::rollback_files( $snapshot ) as $entry ) {
			if ( 'core' === ( $entry['type'] ?? '' ) ) {
				if ( ! $core ) { // wp-admin, wp-includes and the root files are one update.
					$items[] = array( 'type' => 'core', 'slug' => 'wordpress', 'file' => 'wordpress', 'name' => 'WordPress', 'old_version' => $entry['old_version'] ?? '', 'new_version' => $entry['new_version'] ?? null );
					$core    = true;
				}
				continue;
			}
			$items[] = array_intersect_key( (array) $entry, array_flip( array( 'type', 'slug', 'file', 'name', 'old_version', 'new_version' ) ) );
		}
		return $items;
	}

	/**
	 * The updated plugin / theme the fatal error points into, if any.
	 *
	 * @param array<string,mixed>            $after
	 * @param array<int,array<string,mixed>> $items
	 * @return array<string,mixed>|null
	 */
	private static function culprit( array $after, array $items ): ?array {
		$file = wp_normalize_path( (string) ( $after['front']['error']['file'] ?? $after['admin']['error']['file'] ?? '' ) );
		if ( '' === $file ) {
			return count( $items ) === 1 ? $items[0] : null;
		}
		foreach ( $items as $item ) {
			if ( 'core' === $item['type'] ) {
				if ( preg_match( '#^(wp-admin/|wp-includes/|[a-z-]+\.php$)#', ltrim( $file, '/' ) ) ) {
					return $item;
				}
				continue;
			}
			$needle = ( 'theme' === $item['type'] ? 'themes/' : 'plugins/' ) . $item['slug'];
			$ends_with = strlen( $needle ) <= strlen( $file ) && 0 === substr_compare( $file, $needle, -strlen( $needle ) );
			if ( false !== strpos( $file, $needle . '/' ) || $ends_with ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 */
	private static function summary( array $items ): string {
		$names = array();
		foreach ( $items as $item ) {
			$names[] = trim( ( $item['name'] ?: $item['slug'] ) . ' ' . ( $item['new_version'] ?? '' ) );
		}
		return $names ? implode( ', ', $names ) : 'the updated plugins / themes';
	}

	/**
	 * @param array<string,mixed>|null $error
	 */
	private static function describe( string $broken, ?array $error ): string {
		if ( $error ) {
			return sprintf( '%s: %s in %s:%d', $broken, $error['message'], $error['file'], $error['line'] );
		}
		return $broken;
	}

	// ---------------------------------------------------------------------
	// Reporting and the auto-update block
	// ---------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $outcome
	 */
	private static function remember( array $outcome ): void {
		$state   = self::state();
		$blocked = $state['blocked'];
		if ( 'undone' === $outcome['status'] ) {
			foreach ( $outcome['culprit'] ? array( $outcome['culprit'] ) : $outcome['items'] as $item ) {
				if ( ! empty( $item['new_version'] ) ) {
					$blocked[ $item['type'] . ':' . $item['file'] ] = (string) $item['new_version'];
				}
			}
		}
		$state['blocked'] = $blocked;
		$state['notice']  = array(
			'status'   => $outcome['status'],
			'message'  => $outcome['message'],
			'snapshot' => $outcome['snapshot'],
			'at'       => gmdate( 'c' ),
		);
		update_option( self::STATE, $state, false );
	}

	/**
	 * @return array{notice:?array<string,mixed>,blocked:array<string,string>}
	 */
	public static function state(): array {
		$state = get_option( self::STATE, array() );
		$state = is_array( $state ) ? $state : array();
		return array(
			'notice'  => isset( $state['notice'] ) && is_array( $state['notice'] ) ? $state['notice'] : null,
			'blocked' => isset( $state['blocked'] ) && is_array( $state['blocked'] ) ? $state['blocked'] : array(),
		);
	}

	/**
	 * `auto_update_plugin` / `auto_update_theme` / `auto_update_core`: never
	 * auto-install a version Safe Updates had to undo. Newer versions, and
	 * manual updates, still go.
	 *
	 * @param bool|null $update
	 * @param object    $item
	 * @return bool|null
	 */
	public static function allow_auto_update( $update, $item ) {
		if ( 'auto_update_core' === current_filter() ) {
			$key     = 'core:wordpress';
			$version = (string) ( $item->current ?? '' );
		} else {
			$key     = isset( $item->plugin ) ? 'plugin:' . $item->plugin : ( isset( $item->theme ) ? 'theme:' . $item->theme : '' );
			$version = (string) ( $item->new_version ?? '' );
		}
		$bad = self::state()['blocked'][ $key ] ?? null;
		return null !== $bad && $version === $bad ? false : $update;
	}

	/**
	 * Updater screen / AJAX update / WP-CLI.
	 *
	 * @param \WP_Upgrader|mixed $upgrader
	 */
	private static function tell_updater( $upgrader, string $message ): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::warning( $message );
			return;
		}
		if ( is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) && method_exists( $upgrader->skin, 'error' ) ) {
			$upgrader->skin->error( new WP_Error( 'wp_chrono_safe_update', $message ) );
		}
	}

	/**
	 * @param array<string,mixed> $outcome
	 */
	private static function email( array $outcome ): void {
		/**
		 * Whether Safe Updates emails the site admin after an automatic undo.
		 *
		 * @param bool                $send
		 * @param array<string,mixed> $outcome
		 */
		if ( ! apply_filters( 'wp_chrono_safe_updates_email', true, $outcome ) ) {
			return;
		}
		$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		wp_mail(
			(string) get_option( 'admin_email' ),
			sprintf( '[%s] %s', $site, 'undone' === $outcome['status'] ? 'A broken update was undone automatically' : 'A broken update could not be undone' ),
			$outcome['message'] . "\n\n" . home_url( '/' ) . "\n\nWP Chrono keeps the snapshot (#" . $outcome['snapshot'] . ') under WP Chrono → Snapshots.' . ( 'undone' === $outcome['status'] ? ' This version will not be installed automatically again.' : '' )
		);
	}

	public static function notice(): void {
		$notice = self::state()['notice'];
		if ( ! $notice || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$dismiss = wp_nonce_url( add_query_arg( 'wp_chrono_safe_dismiss', '1' ), 'wp_chrono_safe_dismiss' );
		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p><p><a href="%3$s">%4$s</a> · <a href="%5$s">%6$s</a></p></div>',
			'undone' === $notice['status'] ? 'warning' : 'error',
			esc_html( (string) $notice['message'] ),
			esc_url( admin_url( 'admin.php?page=wp-chrono#/snapshots' ) ),
			esc_html__( 'View snapshots', 'wp-chrono' ),
			esc_url( $dismiss ),
			esc_html__( 'Dismiss', 'wp-chrono' )
		);
	}

	public static function maybe_dismiss(): void {
		if ( ! isset( $_GET['wp_chrono_safe_dismiss'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'wp_chrono_safe_dismiss' );
		$state           = self::state();
		$state['notice'] = null;
		update_option( self::STATE, $state, false );
		wp_safe_redirect( remove_query_arg( array( 'wp_chrono_safe_dismiss', '_wpnonce' ) ) );
		exit;
	}
}
