<?php
/**
 * Safe Updates: notices when a plugin / theme update breaks the site and
 * undoes it automatically — files AND database — from the snapshot WP
 * Chrono takes before every update batch.
 *
 * 1. Before the batch (SnapshotService::before_update) the targets() are
 *    probed by loopback with WordPress's fatal-error "scrape" (the mechanism
 *    of core's auto-update rollback): home page, wp-admin, WooCommerce cart /
 *    checkout / My Account (whose markup must still be there) and the
 *    admin's extra URLs. The result is the baseline, stored on the snapshot.
 * 2. After the batch (`upgrader_process_complete`, `_core_updated_successfully`,
 *    `automatic_updates_complete`) they are probed again.
 * 3. A page that worked before and now fails on every confirmation probe
 *    (fatal, 5xx, gone, markup missing) — or a plugin core rolled back —
 *    means the batch is undone; the admin is told and that version is no
 *    longer auto-updated. If visitors created records since the snapshot,
 *    only the files are put back.
 *
 * Never acts on uncertainty: no baseline, no answer (loopback blocked,
 * timeout) or HTTP 503 means "unverified" and nothing is undone; a blocked
 * loopback raises an admin notice. State lives in the snapshot meta (WP
 * Chrono's own tables survive the database restore).
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

	/** Query argument that marks our own probe requests (value: one-time token). */
	const PROBE_ARG = 'wpc_probe';

	/** Most URLs probed per check (home + admin + shop pages + custom). */
	const MAX_TARGETS = 15;

	/**
	 * Markup each WooCommerce page must still contain (any one of them):
	 * block and classic (shortcode) variants. Never body classes — WooCommerce
	 * adds those even when the page content failed to render.
	 */
	const WC_PAGES = array(
		'cart'      => array( 'the WooCommerce cart', array( 'wp-block-woocommerce-cart', 'woocommerce-cart-form', 'cart-empty', 'wc-empty-cart-message' ) ),
		'checkout'  => array( 'the WooCommerce checkout', array( 'wp-block-woocommerce-checkout', 'name="checkout"' ) ),
		'myaccount' => array( 'WooCommerce My Account', array( 'woocommerce-form-login', 'woocommerce-MyAccount-' ) ),
	);

	/** @var array<int,bool> Snapshots already checked in this request. */
	private static $checked = array();

	public static function register(): void {
		add_action( 'upgrader_process_complete', array( self::class, 'after_batch' ), 20, 2 );
		add_action( '_core_updated_successfully', array( self::class, 'after_core_update' ), 5 );
		add_action( 'automatic_updates_complete', array( self::class, 'after_auto_updates' ) );
		add_action( 'activated_plugin', array( self::class, 'after_reactivation' ) );
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
	 * What a check loads: key => {label, url, expect (markup, any of), cookies}.
	 * `front` is always first; `admin` only while an admin updates
	 * interactively (its cookies are borrowed, as core's plugin editor does).
	 *
	 * @return array<string,array{label:string,url:string,expect:string[],cookies:bool}>
	 */
	public static function targets(): array {
		$interactive = is_user_logged_in() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI );
		$targets     = array(
			'front' => array( 'label' => 'the home page', 'url' => home_url( '/' ), 'expect' => array(), 'cookies' => false ),
		);
		if ( $interactive ) {
			$targets['admin'] = array( 'label' => 'wp-admin', 'url' => admin_url( '/' ), 'expect' => array(), 'cookies' => true );
		}
		if ( function_exists( 'wc_get_page_id' ) && function_exists( 'wc_get_page_permalink' ) ) {
			foreach ( self::WC_PAGES as $page => $spec ) {
				$id = (int) wc_get_page_id( $page );
				if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
					$targets[ 'wc_' . $page ] = array( 'label' => $spec[0], 'url' => (string) wc_get_page_permalink( $page ), 'expect' => $spec[1], 'cookies' => false );
				}
			}
		}
		foreach ( Settings::get()['safe_updates_urls'] as $i => $url ) {
			$targets[ 'url_' . ( $i + 1 ) ] = array( 'label' => $url, 'url' => $url, 'expect' => array(), 'cookies' => false );
		}
		/**
		 * Pages Safe Updates loads before and after every update.
		 *
		 * @param array<string,array{label:string,url:string,expect:string[],cookies:bool}> $targets
		 *        `expect`: markup the page must still contain (any one of the strings).
		 *        Only URLs on this site's own host are kept.
		 */
		$targets = (array) apply_filters( 'wp_chrono_safe_updates_probe_targets', $targets );
		$host    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$clean   = array();
		foreach ( $targets as $key => $target ) {
			$url = is_array( $target ) ? esc_url_raw( (string) ( $target['url'] ?? '' ), array( 'http', 'https' ) ) : '';
			if ( '' === $url || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host ) {
				continue; // Loopback only: never probe other servers.
			}
			$clean[ sanitize_key( (string) $key ) ] = array(
				'label'   => (string) ( $target['label'] ?? $url ),
				'url'     => $url,
				'expect'  => array_values( array_filter( array_map( 'strval', (array) ( $target['expect'] ?? array() ) ) ) ),
				'cookies' => ! empty( $target['cookies'] ) && $interactive,
			);
		}
		if ( ! isset( $clean['front'] ) ) {
			$clean = array( 'front' => array( 'label' => 'the home page', 'url' => home_url( '/' ), 'expect' => array(), 'cookies' => false ) ) + $clean;
		}
		return array_slice( $clean, 0, self::MAX_TARGETS, true );
	}

	/**
	 * Loads every target (or only $only) in fresh PHP processes and reports
	 * fatal errors, server errors and missing markup.
	 *
	 * @param string[]|null $only Target keys to (re-)probe; null = all.
	 * @return array{front:?array<string,mixed>,admin:?array<string,mixed>,pages:array<string,array<string,mixed>>,at:string}
	 */
	public static function probe( ?array $only = null ): array {
		$cookies = wp_unslash( $_COOKIE ); // Only sent for the admin target, as core's plugin-editor loopback.
		$out     = array( 'front' => null, 'admin' => null, 'pages' => array(), 'at' => gmdate( 'c' ) );
		foreach ( self::targets() as $key => $target ) {
			if ( null !== $only && ! in_array( $key, $only, true ) ) {
				continue;
			}
			$result = self::probe_url( $key, $target, $target['cookies'] ? (array) $cookies : array() );
			if ( 'front' === $key || 'admin' === $key ) {
				$out[ $key ] = $result; // Top level, as stored by earlier versions.
			} else {
				$out['pages'][ $key ] = $result + array( 'label' => $target['label'], 'url' => $target['url'] );
			}
		}
		return $out;
	}

	/**
	 * One target's result from a probe() run, or null.
	 *
	 * @param array<string,mixed> $probe
	 * @return array<string,mixed>|null
	 */
	private static function result( array $probe, string $key ): ?array {
		$result = ( 'front' === $key || 'admin' === $key ) ? ( $probe[ $key ] ?? null ) : ( $probe['pages'][ $key ] ?? null );
		return is_array( $result ) ? $result : null;
	}

	/**
	 * @param array{label:string,url:string,expect:string[],cookies:bool} $target
	 * @param array<string,string>                                          $cookies
	 * @return array{ok:?bool,status:int,error:?array<string,mixed>,reason:string,ms:int}
	 */
	private static function probe_url( string $key, array $target, array $cookies ): array {
		$result = self::probe_once( $key, $target, $cookies );
		if ( 503 === $result['status'] && null !== self::maintenance_timestamp() ) {
			// The web server's OPcache can still hold an older `.maintenance`
			// (another timestamp, e.g. from the previous update's or undo's
			// maintenance window), so our bypass key was refused. Wait until
			// OPcache re-reads the file, then try once more.
			sleep( min( 10, max( 0, (int) ini_get( 'opcache.revalidate_freq' ) ) + 1 ) );
			$result = self::probe_once( $key, $target, $cookies );
		}
		return $result;
	}

	/**
	 * @param array{label:string,url:string,expect:string[],cookies:bool} $target
	 * @param array<string,string>                                          $cookies
	 * @return array{ok:?bool,status:int,error:?array<string,mixed>,reason:string,ms:int}
	 */
	private static function probe_once( string $kind, array $target, array $cookies ): array {
		$url = $target['url'];
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

		// A one-time token marks the request as our probe, so the probed page
		// can switch off what would write store data or hide the page
		// (WooCommerce's empty-cart redirect, cart cookies): see probe_mode().
		$token = strtolower( wp_generate_password( 24, false ) );
		set_transient( 'wp_chrono_probe_' . $token, 1, 120 );

		/**
		 * URL the Safe Updates probe loads (before the scrape arguments are added).
		 *
		 * @param string $url  The target's URL.
		 * @param string $kind Target key: 'front', 'admin', 'wc_checkout', 'url_1'…
		 */
		$url     = (string) apply_filters( 'wp_chrono_safe_updates_probe_url', $url, $kind );
		// The one-time token doubles as a cache buster (unique query string);
		// these headers ask any cache on the way not to answer from storage.
		$headers = array(
			'Cache-Control' => 'no-cache, no-store, max-age=0',
			'Pragma'        => 'no-cache',
		);
		if ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions, WordPress.Security.ValidatedSanitizedInput -- loopback Basic auth, as core does.
		}
		$started  = microtime( true );
		$response = wp_remote_get(
			add_query_arg( array( 'wp_scrape_key' => $key, 'wp_scrape_nonce' => $nonce, self::PROBE_ARG => $token ), $url ),
			array(
				/**
				 * Seconds one probe request may take. A slow page is not a broken
				 * one: a timeout means "no answer" and never triggers an undo.
				 *
				 * @param int    $seconds
				 * @param string $kind    Target key.
				 */
				'timeout'     => max( 5, (int) apply_filters( 'wp_chrono_safe_updates_timeout', 30, $kind ) ),
				'redirection' => 0, // The scrape result is printed even on a redirect; following one could leave the host.
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'cookies'     => $cookies,
				'headers'     => $headers,
			)
		);
		delete_transient( 'scrape_key_' . $key );
		delete_transient( 'wp_chrono_probe_' . $token );
		$ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => null, 'status' => 0, 'error' => null, 'reason' => 'No answer: ' . $response->get_error_message(), 'ms' => $ms, 'answer' => 'none' );
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
			return array( 'ok' => false, 'status' => $status, 'error' => $error, 'reason' => 'PHP fatal error', 'ms' => $ms );
		}
		if ( 503 === $status ) {
			// Maintenance mode / overload (often a plugin migrating its tables
			// right after an update): not proof of a broken update.
			return array( 'ok' => null, 'status' => $status, 'error' => null, 'reason' => 'HTTP 503 (maintenance or overload)', 'ms' => $ms );
		}
		if ( $status >= 500 ) {
			return array( 'ok' => false, 'status' => $status, 'error' => null, 'reason' => "HTTP $status", 'ms' => $ms );
		}
		if ( true !== $result ) {
			// No scrape result: a cache, firewall or security layer answered
			// in WordPress's place, or the nonce check failed. Don't guess.
			return array( 'ok' => null, 'status' => $status, 'error' => null, 'reason' => "HTTP $status without a WordPress scrape result (a cache, firewall or security plugin answered)", 'ms' => $ms, 'answer' => 'foreign' );
		}
		$ok2xx = $status >= 200 && $status < 300;
		if ( $ok2xx && false === strpos( $body, self::rendered_marker( $token ) ) ) {
			// WordPress started, but the page itself was not rendered for this
			// request: a page cache served a stored copy (e.g. a cache plugin
			// answering on `init`). A stale healthy page proves nothing.
			return array( 'ok' => null, 'status' => $status, 'error' => null, 'reason' => "HTTP $status served from a cache (the page was not generated for this check)", 'ms' => $ms, 'answer' => 'foreign' );
		}
		if ( 'admin' !== $kind && in_array( $status, array( 404, 410 ), true ) ) {
			return array( 'ok' => false, 'status' => $status, 'error' => null, 'reason' => "HTTP $status (page gone)", 'ms' => $ms, 'answer' => 'wordpress' );
		}
		if ( $target['expect'] && $ok2xx ) {
			$present = array_values(
				array_filter(
					$target['expect'],
					static function ( $marker ) use ( $body ) {
						return false !== strpos( $body, $marker );
					}
				)
			);
			if ( ! $present ) {
				// Pages deliberately not showing their content right now (store
				// switched to "coming soon", password form): not checkable, and
				// never "broken" — also when the switch happened mid-update.
				foreach ( array( 'woocommerce-coming-soon' => 'WooCommerce "coming soon" mode', 'post-password-form' => 'password-protected page' ) as $sign => $why ) {
					if ( false !== strpos( $body, $sign ) ) {
						return array( 'ok' => null, 'status' => $status, 'error' => null, 'reason' => "HTTP $status: {$why}, not checked", 'ms' => $ms, 'answer' => 'unavailable', 'markers' => array() );
					}
				}
				// As a baseline: coming-soon, password-protected or customised
				// page — skipped. After an update: compared with the baseline.
				return array( 'ok' => null, 'status' => $status, 'error' => null, 'reason' => "HTTP $status but none of the expected content is on the page (" . implode( ', ', $target['expect'] ) . ')', 'ms' => $ms, 'answer' => 'wordpress', 'markers' => array() );
			}
			return array( 'ok' => true, 'status' => $status, 'error' => null, 'reason' => "HTTP $status, page content present", 'ms' => $ms, 'answer' => 'wordpress', 'markers' => $present );
		}
		if ( $target['expect'] ) {
			// A redirect / 4xx instead of the shop page: can't judge its content.
			return array( 'ok' => null, 'status' => $status, 'error' => null, 'reason' => "HTTP $status instead of the page", 'ms' => $ms, 'answer' => 'wordpress' );
		}
		return array( 'ok' => true, 'status' => $status, 'error' => null, 'reason' => "HTTP $status", 'ms' => $ms, 'answer' => 'wordpress' );
	}

	/**
	 * Printed by probe_mode() into pages WordPress really rendered (footer
	 * hooks), never into a cached copy served before rendering.
	 */
	private static function rendered_marker( string $token ): string {
		return '<!-- wp-chrono-probe-rendered:' . $token . ' -->';
	}

	/**
	 * Inside a probed request (front end, our one-time token present): keep
	 * the visit from touching store data or hiding the page under test.
	 * WooCommerce sends an empty-cart checkout back to the cart, so without
	 * this the checkout form would never be loaded; cart cookies and the
	 * persistent cart are switched off so no session or cart is ever stored.
	 * Probes carry no cookies (except wp-admin), so they are anonymous
	 * visitors with an empty cart and cannot create orders.
	 */
	public static function probe_mode(): void {
		$token = isset( $_GET[ self::PROBE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::PROBE_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- one-time token checked below.
		if ( '' === $token || ! get_transient( 'wp_chrono_probe_' . $token ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // Page caches must neither serve nor store this response.
		}
		$marker = static function () use ( $token ) {
			echo "\n" . self::rendered_marker( $token ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed string + sanitize_key()'d token.
		};
		foreach ( array( 'wp_footer', 'admin_footer', 'login_footer' ) as $hook ) {
			add_action( $hook, $marker, PHP_INT_MAX );
		}
		add_filter( 'woocommerce_checkout_redirect_empty_cart', '__return_false', 999 );
		add_filter( 'woocommerce_set_cart_cookies', '__return_false', 999 );
		add_filter( 'woocommerce_persistent_cart_enabled', '__return_false', 999 );
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
		// wp-admin/update.php's single-plugin update deactivates the plugin,
		// updates it and reactivates it in a SECOND request (the "activate"
		// iframe). Checking now would test the site without the new code:
		// check right after that reactivation instead.
		$plugin = (string) ( $hook_extra['plugin'] ?? '' );
		if ( 'plugin' === ( $hook_extra['type'] ?? '' ) && '' !== $plugin && $upgrader instanceof \Plugin_Upgrader && ! empty( $upgrader->skin->plugin_active ) && function_exists( 'is_plugin_active' ) && ! is_plugin_active( $plugin ) ) {
			$state            = self::state();
			$state['pending'] = array( 'snapshot' => $id, 'plugin' => $plugin, 'until' => time() + 15 * MINUTE_IN_SECONDS );
			update_option( self::STATE, $state, false );
			return;
		}
		$outcome = self::check( $id );
		if ( isset( $outcome['message'] ) && 'passed' !== $outcome['status'] && 'unverified' !== $outcome['status'] ) {
			self::tell_updater( $upgrader, (string) $outcome['message'] );
		}
	}

	/**
	 * `activated_plugin`: the deferred check of an update that wp-admin ran with
	 * the plugin deactivated (see after_batch()). If the plugin is never
	 * reactivated, its new code never runs and there is nothing to undo.
	 *
	 * @param string $plugin Plugin basename.
	 */
	public static function after_reactivation( $plugin ): void {
		$state   = self::state();
		$pending = $state['pending'];
		if ( ! $pending || (string) $plugin !== $pending['plugin'] ) {
			return;
		}
		$state['pending'] = null;
		update_option( self::STATE, $state, false );
		$id = (int) $pending['snapshot'];
		if ( time() > (int) $pending['until'] || ! self::enabled() || isset( self::$checked[ $id ] ) ) {
			return;
		}
		$outcome = self::check( $id );
		if ( in_array( $outcome['status'], array( 'undone', 'undo_failed' ), true ) && function_exists( 'show_message' ) ) {
			show_message( '<strong>' . esc_html( (string) $outcome['message'] ) . '</strong>' );
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
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions.Discouraged -- probes, retries and an undo must not be cut off half-way.
		}
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
			$answer = (string) ( $baseline['front']['answer'] ?? ( false === ( $baseline['front']['loopback'] ?? null ) ? 'none' : '' ) );
			if ( in_array( $answer, array( 'none', 'foreign' ), true ) ) {
				self::loopback_blocked( (string) ( $baseline['front']['reason'] ?? '' ), $answer );
			}
			return self::record( $snapshot_id, $state, array( 'status' => 'unverified', 'skipped' => self::skipped( $baseline ), 'message' => 'Safe Updates could not check this site before the update (' . ( $baseline['front']['reason'] ?? 'no baseline' ) . '); nothing was undone.' ) );
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
		$after   = self::probe();
		$failing = $core_rollbacks ? array() : self::regressions( $baseline, $after );

		// A single failed request is not enough: proxies, cold caches and
		// workers busy with the update all produce one-off 5xx. A page only
		// counts as broken when it fails on every confirmation probe too;
		// only the failing pages are loaded again.
		/**
		 * Extra probes that must fail too before an update is undone.
		 *
		 * @param int $retries Default 2 (three failing probes in total).
		 */
		$retries = $core_rollbacks ? 0 : max( 0, min( 5, (int) apply_filters( 'wp_chrono_safe_updates_retries', 2 ) ) );
		for ( $try = 0; $failing && $try < $retries; $try++ ) {
			/**
			 * Seconds between confirmation probes.
			 *
			 * @param int $seconds
			 */
			$pause = (int) apply_filters( 'wp_chrono_safe_updates_retry_delay', 3 );
			if ( $pause > 0 ) {
				sleep( min( 15, $pause ) );
			}
			$again = self::probe( array_keys( $failing ) );
			foreach ( array_keys( $failing ) as $key ) {
				$result = self::result( $again, $key );
				if ( 'front' === $key || 'admin' === $key ) {
					$after[ $key ] = $result;
				} elseif ( $result ) {
					$after['pages'][ $key ] = $result;
				}
			}
			// Still failing on this try too; recovered or no answer drops out.
			$failing = array_intersect_key( self::regressions( $baseline, $after ), $failing );
		}

		$broken  = $core_rollbacks ? 'WordPress rolled back ' . implode( ', ', $core_rollbacks ) . ' after a fatal error' : self::describe_failures( $failing );
		$skipped = self::skipped( $baseline );
		if ( null === $broken ) {
			$verified = true === ( $after['front']['ok'] ?? null );
			if ( $verified ) {
				self::loopback_blocked( null, '' ); // Loopback works (again).
			}
			return self::record(
				$snapshot_id,
				$state,
				array(
					'status'  => $verified ? 'passed' : 'unverified',
					'after'   => $after,
					'skipped' => $skipped,
					'message' => ( $verified ? sprintf( 'The site still works after the update (%d page%s checked).', self::count_checked( $after ), 1 === self::count_checked( $after ) ? '' : 's' ) : 'Safe Updates could not check the site after the update (' . ( $after['front']['reason'] ?? 'no answer' ) . '); nothing was undone.' ) . self::describe_skipped( $skipped ),
				)
			);
		}

		$error = null;
		foreach ( array_keys( $failing ) as $key ) {
			$error = $error ?? ( self::result( $after, $key )['error'] ?? null );
		}
		$culprit = self::culprit( $error, $items );
		$what    = $culprit ? sprintf( '%s %s', $culprit['name'] ?: $culprit['slug'], $culprit['new_version'] ?? '' ) : self::summary( $items );

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
			'failing'  => array_keys( $failing ),
			'skipped'  => $skipped,
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
			'posts'     => array( 'new post(s) or page(s)', "post_type NOT IN ('revision','nav_menu_item','customize_changeset','oembed_cache','wp_global_styles','user_request','scheduled-action','shop_order','shop_order_placehold','shop_order_refund') AND post_status NOT IN ('auto-draft','inherit','trash')" ),
			// WooCommerce orders: one posts row per order either way (the order
			// itself, or HPOS's ID placeholder) plus wc_orders with HPOS. The
			// same label counts once (the larger number), never twice.
			'posts#orders' => array( 'new WooCommerce order(s)', "post_type IN ('shop_order','shop_order_placehold') AND post_status <> 'auto-draft'" ),
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
		foreach ( $watch as $key => $spec ) {
			$suffix = (string) strtok( (string) $key, '#' ); // "posts#orders": another view of the posts table.
			$live   = ( 'users' === $suffix ? $wpdb->base_prefix : $wpdb->prefix ) . $suffix;
			if ( ! isset( $tables[ $live ] ) || ! is_array( $spec ) ) {
				continue;
			}
			// A single integer primary key: auto-increment, or assigned in
			// increasing order elsewhere (HPOS takes order IDs from the posts table).
			$pk = $wpdb->get_col( $wpdb->prepare( "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_KEY = %s AND DATA_TYPE IN ('tinyint','smallint','mediumint','int','bigint')", $wpdb->dbname, $live, 'PRI' ) );
			if ( 1 !== count( $pk ) ) {
				continue;
			}
			$max   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(%i),0) FROM %i', (string) $pk[0], (string) $tables[ $live ]['snapshot_table'] ) );
			$extra = '' !== (string) ( $spec[1] ?? '' ) ? ' AND (' . $spec[1] . ')' : ''; // Fixed SQL from code (filter), never user input.
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i > %d' . $extra, $live, (string) $pk[0], $max ) );
			if ( $count > 0 ) {
				$found[ (string) $spec[0] ] = max( $found[ (string) $spec[0] ] ?? 0, $count );
			}
		}
		// phpcs:enable
		return $found;
	}

	/**
	 * Pages that worked before the update and are broken now.
	 *
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @return array<string,string> Target key => "label: reason".
	 */
	private static function regressions( array $before, array $after ): array {
		$labels = array( 'front' => 'the home page', 'admin' => 'wp-admin' );
		$keys   = array_merge( array_keys( $labels ), array_keys( (array) ( $after['pages'] ?? array() ) ) );
		$out    = array();
		foreach ( $keys as $key ) {
			$was = self::result( $before, $key );
			$now = self::result( $after, $key );
			if ( ! $was || ! $now || true !== ( $was['ok'] ?? null ) ) {
				continue; // Only pages that worked in the baseline are judged.
			}
			$label = $labels[ $key ] ?? (string) ( $now['label'] ?? $key );
			if ( false === ( $now['ok'] ?? null ) ) {
				$out[ $key ] = $label . ': ' . $now['reason'];
				continue;
			}
			// Content markers: only those present in the baseline must still be
			// there (a cart that showed "empty cart" before isn't expected to
			// show a cart form now).
			$status  = (int) ( $now['status'] ?? 0 );
			$missing = array_values( array_diff( (array) ( $was['markers'] ?? array() ), (array) ( $now['markers'] ?? array() ) ) );
			if ( $missing && 'wordpress' === ( $now['answer'] ?? '' ) && $status >= 200 && $status < 300 ) {
				$out[ $key ] = sprintf( '%s: HTTP %d but the page content is missing (%s)', $label, $status, implode( ', ', $missing ) );
			}
		}
		return $out;
	}

	/**
	 * Pages the baseline could not confirm as working (maintenance, coming
	 * soon, password-protected, served from a cache…): never judged.
	 *
	 * @param array<string,mixed> $baseline
	 * @return array<string,string> Target key => "label: reason".
	 */
	private static function skipped( array $baseline ): array {
		$labels = array( 'front' => 'the home page', 'admin' => 'wp-admin' );
		$out    = array();
		foreach ( array_merge( array( 'front', 'admin' ), array_keys( (array) ( $baseline['pages'] ?? array() ) ) ) as $key ) {
			$was = self::result( $baseline, $key );
			if ( $was && true !== ( $was['ok'] ?? null ) ) {
				$out[ $key ] = ( $labels[ $key ] ?? (string) ( $was['label'] ?? $key ) ) . ': ' . (string) ( $was['reason'] ?? '' );
			}
		}
		return $out;
	}

	/**
	 * @param array<string,string> $skipped
	 */
	private static function describe_skipped( array $skipped ): string {
		return $skipped ? sprintf( ' Not checked (did not work before the update either): %s.', implode( '; ', $skipped ) ) : '';
	}

	/**
	 * @param array<string,string> $failing
	 */
	private static function describe_failures( array $failing ): ?string {
		if ( ! $failing ) {
			return null;
		}
		$first = (string) reset( $failing );
		return count( $failing ) > 1 ? sprintf( '%s; and %d more page(s)', $first, count( $failing ) - 1 ) : $first;
	}

	/**
	 * @param array<string,mixed> $probe
	 */
	private static function count_checked( array $probe ): int {
		$n = 0;
		foreach ( array_merge( array( $probe['front'] ?? null, $probe['admin'] ?? null ), array_values( (array) ( $probe['pages'] ?? array() ) ) ) as $r ) {
			$n += ( is_array( $r ) && true === ( $r['ok'] ?? null ) ) ? 1 : 0;
		}
		return $n;
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
	 * @param array<string,mixed>|null       $error
	 * @param array<int,array<string,mixed>> $items
	 * @return array<string,mixed>|null
	 */
	private static function culprit( ?array $error, array $items ): ?array {
		$file = wp_normalize_path( (string) ( $error['file'] ?? '' ) );
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
	 * Remembers (or clears, with null) that the site could not load itself,
	 * so the admin learns that broken updates are not undone automatically.
	 */
	private static function loopback_blocked( ?string $reason, string $kind ): void {
		$state = self::state();
		if ( null === $reason ? null === $state['loopback'] : ( $state['loopback']['reason'] ?? null ) === $reason ) {
			return;
		}
		$state['loopback'] = null === $reason ? null : array( 'reason' => $reason, 'kind' => 'foreign' === $kind ? 'foreign' : 'none', 'at' => gmdate( 'c' ) );
		update_option( self::STATE, $state, false );
	}

	/**
	 * @return array{notice:?array<string,mixed>,blocked:array<string,string>,loopback:?array<string,string>,pending:?array<string,mixed>}
	 */
	public static function state(): array {
		$state = get_option( self::STATE, array() );
		$state = is_array( $state ) ? $state : array();
		return array(
			'notice'   => isset( $state['notice'] ) && is_array( $state['notice'] ) ? $state['notice'] : null,
			'blocked'  => isset( $state['blocked'] ) && is_array( $state['blocked'] ) ? $state['blocked'] : array(),
			'loopback' => isset( $state['loopback'] ) && is_array( $state['loopback'] ) ? $state['loopback'] : null,
			'pending'  => isset( $state['pending']['snapshot'], $state['pending']['plugin'] ) ? $state['pending'] : null,
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
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = self::state();
		if ( $state['loopback'] && self::enabled() ) {
			printf(
				'<div class="notice notice-warning" data-wp-chrono-notice="loopback"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p><p><a href="%4$s">%5$s</a> · <a href="%6$s">%7$s</a></p></div>',
				esc_html__( 'WP Chrono Safe Updates cannot check this site, so broken updates are not undone automatically.', 'wp-chrono' ),
				esc_html(
					sprintf(
						'foreign' === ( $state['loopback']['kind'] ?? '' )
							/* translators: %s: what answered */
							? __( 'When WordPress loaded its own home page, something else answered instead of WordPress (a page cache, CDN, firewall or security plugin): %s. Exclude requests carrying "wp_scrape_key" from caching and firewall rules.', 'wp-chrono' )
							/* translators: %s: error message */
							: __( 'Your server blocked WordPress from loading its own pages (a "loopback request"): %s.', 'wp-chrono' ),
						$state['loopback']['reason']
					)
				),
				esc_html__( 'Updates are still snapshotted and you can undo them by hand. To turn automatic undo back on, ask your host to allow loopback requests to this site (security plugins, firewalls and HTTP authentication often block them). Tools → Site Health shows the same problem. This notice disappears after the next update that could be checked.', 'wp-chrono' ),
				esc_url( admin_url( 'site-health.php' ) ),
				esc_html__( 'Open Site Health', 'wp-chrono' ),
				esc_url( wp_nonce_url( add_query_arg( 'wp_chrono_safe_dismiss', 'loopback' ), 'wp_chrono_safe_dismiss' ) ),
				esc_html__( 'Dismiss', 'wp-chrono' )
			);
		}
		$notice = $state['notice'];
		if ( ! $notice ) {
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
		$state = self::state();
		$which = 'loopback' === $_GET['wp_chrono_safe_dismiss'] ? 'loopback' : 'notice'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared to a literal.
		$state[ $which ] = null;
		update_option( self::STATE, $state, false );
		wp_safe_redirect( remove_query_arg( array( 'wp_chrono_safe_dismiss', '_wpnonce' ) ) );
		exit;
	}
}
