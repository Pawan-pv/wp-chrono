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
 *    for updates from wp-admin / WP-CLI, `automatic_updates_complete` for
 *    background auto-updates (after core's own per-plugin check).
 * 3. A page that worked before and now has a PHP fatal error or a 5xx — or a
 *    plugin core already had to roll back — means the batch is undone with
 *    Full Undo, the admin is told (updater screen / WP-CLI, admin notice,
 *    email) and that exact version is no longer auto-updated.
 *
 * Never acts on uncertainty: if the baseline already failed or a probe gets
 * no answer (loopback blocked, timeout), the result is "unverified" and
 * nothing is undone. State lives in the snapshot meta (WP Chrono's own
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

	public static function register(): void {
		add_action( 'upgrader_process_complete', array( self::class, 'after_batch' ), 20, 2 );
		add_action( 'automatic_updates_complete', array( self::class, 'after_auto_updates' ) );
		add_filter( 'auto_update_plugin', array( self::class, 'allow_auto_update' ), 20, 2 );
		add_filter( 'auto_update_theme', array( self::class, 'allow_auto_update' ), 20, 2 );
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
		if ( 'update' !== ( $hook_extra['action'] ?? '' ) || ! in_array( $hook_extra['type'] ?? '', array( 'plugin', 'theme' ), true ) || did_action( 'pre_auto_update' ) ) {
			return;
		}
		$id = self::watched();
		if ( ! $id ) {
			return;
		}
		$outcome = self::check( $id );
		if ( isset( $outcome['message'] ) && 'passed' !== $outcome['status'] && 'unverified' !== $outcome['status'] ) {
			self::tell_updater( $upgrader, (string) $outcome['message'] );
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
		$state    = (array) ( $snapshot['meta']['safe_update'] ?? array() );
		$baseline = (array) ( $state['baseline'] ?? array() );
		$items    = self::items( $snapshot );

		if ( true !== ( $baseline['front']['ok'] ?? null ) && ! $core_rollbacks ) {
			return self::record( $snapshot_id, $state, array( 'status' => 'unverified', 'message' => 'Safe Updates could not check this site before the update (' . ( $baseline['front']['reason'] ?? 'no baseline' ) . '); nothing was undone.' ) );
		}

		/**
		 * Seconds to wait before probing, so PHP-FPM workers see the new code
		 * (core's auto-updater waits 2 s for the same reason).
		 *
		 * @param int $seconds
		 */
		$delay = (int) apply_filters( 'wp_chrono_safe_updates_delay', 2 );
		if ( $delay > 0 ) {
			sleep( min( 10, $delay ) );
		}
		$after  = self::probe();
		$broken = $core_rollbacks ? 'WordPress rolled back ' . implode( ', ', $core_rollbacks ) . ' after a fatal error' : self::regression( $baseline, $after );

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

		$undo = $service->undo_update( $snapshot_id, 'full' );
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
			'snapshot' => $snapshot_id,
		);
		$outcome['message'] = $ok
			? sprintf( 'WP Chrono Safe Updates: the update to %s broke the site (%s), so it was undone automatically — files and database are back to the state from just before the update.', $what, self::describe( $broken, $error ) )
			: sprintf( 'WP Chrono Safe Updates: the update to %s broke the site (%s) and the automatic undo FAILED: %s. Use "Undo update" on snapshot #%d or the Emergency Recovery Script.', $what, self::describe( $broken, $error ), $undo->get_error_message(), $snapshot_id );

		self::record( $snapshot_id, $state, $outcome );
		self::remember( $outcome );
		self::email( $outcome );
		return $outcome;
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
		foreach ( SnapshotService::rollback_files( $snapshot ) as $entry ) {
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
			$needle = ( 'theme' === $item['type'] ? 'themes/' : 'plugins/' ) . $item['slug'];
			if ( false !== strpos( $file, $needle . '/' ) || str_ends_with( $file, $needle ) ) {
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
					$blocked[ ( 'theme' === $item['type'] ? 'theme:' : 'plugin:' ) . $item['file'] ] = (string) $item['new_version'];
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
	 * `auto_update_plugin` / `auto_update_theme`: never auto-install a version
	 * Safe Updates had to undo. Newer versions, and manual updates, still go.
	 *
	 * @param bool|null $update
	 * @param object    $item
	 * @return bool|null
	 */
	public static function allow_auto_update( $update, $item ) {
		$key = isset( $item->plugin ) ? 'plugin:' . $item->plugin : ( isset( $item->theme ) ? 'theme:' . $item->theme : '' );
		$bad = self::state()['blocked'][ $key ] ?? null;
		return null !== $bad && (string) ( $item->new_version ?? '' ) === $bad ? false : $update;
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
