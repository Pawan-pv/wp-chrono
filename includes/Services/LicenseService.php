<?php
/**
 * Licensing and feature tiers.
 *
 * Core promise: restoring is never paywalled. Free forever — resets,
 * snapshots, auto-snapshots, every restore mode (incl. Full Undo), local
 * .sql.gz import, cloud *pull*, and the Emergency Recovery Script. Pro adds
 * Blueprints and Cloud offload (push / configuration).
 *
 * Keys are verified offline with Ed25519, so lifetime-deal codes (AppSumo),
 * agencies and air-gapped sites need no license server:
 *
 *   WPC1.<base64url(payload JSON)>.<base64url(signature)>
 *   payload: {"lid":"…","plan":"pro|agency","sites":1,"source":"appsumo|paddle|lemonsqueezy|direct",
 *             "issued":"2026-10-26","expires":null|"YYYY-MM-DD"}
 *   signature = Ed25519( "WPC1." . <base64url payload> ) with the vendor key
 *
 * Key sources, first wins: the WP_CHRONO_LICENSE_KEY constant, then the key
 * saved via the UI / `wp chrono license activate`.
 *
 * License server (seats, revocation): `{Updater::api()}/v1/license`, unless the
 * WP_CHRONO_LICENSE_SERVER constant names another, or '' (fully offline). Contract:
 *
 *   POST {server}  JSON {"action":"activate|deactivate|check","key":"WPC1…","site":"https://…","version":"1.0.0"}
 *   200            JSON {"lid":"…","site":"https://…","status":"active|site_limit|revoked|expired|inactive","ts":1761460000,
 *                        "sig":"<base64url Ed25519( "WPCR1.{lid}.{site}.{status}.{ts}" )>"}
 *
 * Responses must be signed with the vendor key. Checks run at most daily, only in
 * wp-admin / REST / CLI. An unreachable server never disables a valid key; a
 * signed `revoked` or `site_limit` does, until a later check says `active`.
 *
 * @package WPChrono
 */

namespace WPChrono\Services;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class LicenseService {

	const OPTION = 'wp_chrono_license';

	const CHECK_TRANSIENT = 'wp_chrono_license_check';

	/** Vendor Ed25519 public keys (base64), newest first. Several allow key rotation. */
	const PUBLIC_KEYS = array(
		'2026-10' => 'ZL0gZXhFVPzVlh5R24FIy4zQ2ZIapZnOOp7AjmFeR2c=',
	);

	/** Features that need a license. Everything else is free. */
	const PRO_FEATURES = array( 'blueprints', 'cloud' );

	const PLANS = array( 'pro', 'agency' );

	/** Signed server verdicts that switch Pro off, with the reason shown to the user. */
	const BLOCKING = array(
		'revoked'    => 'This license was revoked by the license server.',
		'site_limit' => 'This license key is already active on its maximum number of sites. Deactivate it on another site (Settings → License), then check again.',
	);

	// ---------------------------------------------------------------------
	// Verification
	// ---------------------------------------------------------------------

	/**
	 * Verifies a key's signature and fields.
	 *
	 * @return array<string,mixed>|WP_Error Payload.
	 */
	public static function parse( string $key ) {
		$key   = trim( $key );
		$parts = explode( '.', $key );
		if ( 3 !== count( $parts ) || 'WPC1' !== $parts[0] ) {
			return new WP_Error( 'wp_chrono_license_format', 'That is not a WP Chrono license key (expected WPC1.…).', array( 'status' => 400 ) );
		}
		$payload = self::b64u_decode( $parts[1] );
		$sig     = self::b64u_decode( $parts[2] );
		if ( false === $payload || false === $sig || 64 !== strlen( $sig ) ) {
			return new WP_Error( 'wp_chrono_license_format', 'The license key is damaged (copy it again in full).', array( 'status' => 400 ) );
		}
		if ( ! self::verify( $sig, 'WPC1.' . $parts[1] ) ) {
			return new WP_Error( 'wp_chrono_license_signature', 'The license key signature is not valid.', array( 'status' => 400 ) );
		}
		$data = json_decode( $payload, true );
		if ( ! is_array( $data ) || empty( $data['lid'] ) || ! in_array( $data['plan'] ?? '', self::PLANS, true ) ) {
			return new WP_Error( 'wp_chrono_license_format', 'The license key payload is invalid.', array( 'status' => 400 ) );
		}
		if ( ! empty( $data['expires'] ) && strtotime( (string) $data['expires'] . ' 23:59:59 UTC' ) < time() ) {
			return new WP_Error( 'wp_chrono_license_expired', sprintf( 'This license expired on %s.', $data['expires'] ), array( 'status' => 400 ) );
		}
		return array(
			'lid'     => sanitize_text_field( (string) $data['lid'] ),
			'plan'    => (string) $data['plan'],
			'sites'   => max( 1, (int) ( $data['sites'] ?? 1 ) ),
			'source'  => sanitize_key( (string) ( $data['source'] ?? 'direct' ) ),
			'issued'  => sanitize_text_field( (string) ( $data['issued'] ?? '' ) ),
			'expires' => empty( $data['expires'] ) ? null : sanitize_text_field( (string) $data['expires'] ),
		);
	}

	/**
	 * Detached Ed25519 check against every trusted vendor key.
	 */
	private static function verify( string $sig, string $message ): bool {
		foreach ( self::public_keys() as $b64 ) {
			$pub = base64_decode( (string) $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- decoding the embedded public key.
			if ( false === $pub || 32 !== strlen( $pub ) ) {
				continue;
			}
			try {
				if ( sodium_crypto_sign_verify_detached( $sig, $message, $pub ) ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		return false;
	}

	/**
	 * @return string[] Trusted public keys (base64).
	 */
	public static function public_keys(): array {
		/**
		 * Trusted vendor public keys. Used by the test suite to add a test key;
		 * a white-label reseller could add its own.
		 *
		 * @param string[] $keys
		 */
		$keys = (array) apply_filters( 'wp_chrono_license_public_keys', array_values( self::PUBLIC_KEYS ) );
		return array_values( array_filter( $keys, static function ( $k ) {
			return is_string( $k ) && 0 !== strpos( $k, '__WPC_' );
		} ) );
	}

	// ---------------------------------------------------------------------
	// State
	// ---------------------------------------------------------------------

	/**
	 * @return array{key:string,from:?string}
	 */
	public static function stored_key(): array {
		if ( defined( 'WP_CHRONO_LICENSE_KEY' ) && '' !== (string) WP_CHRONO_LICENSE_KEY ) {
			return array( 'key' => (string) WP_CHRONO_LICENSE_KEY, 'from' => 'constant' );
		}
		$saved = get_option( self::OPTION, array() );
		$key   = is_array( $saved ) ? (string) ( $saved['key'] ?? '' ) : '';
		return array( 'key' => $key, 'from' => '' === $key ? null : 'option' );
	}

	/**
	 * Full license state for the UI / CLI.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		self::maybe_check();
		$stored  = self::stored_key();
		$saved   = get_option( self::OPTION, array() );
		$saved   = is_array( $saved ) ? $saved : array();
		$payload = '' === $stored['key'] ? null : self::parse( $stored['key'] );
		$error   = is_wp_error( $payload ) ? $payload->get_error_message() : null;
		$verdict = (string) ( $saved['remote']['status'] ?? '' );
		$revoked = isset( self::BLOCKING[ $verdict ] ) && is_array( $payload ) && ( $saved['remote']['lid'] ?? '' ) === $payload['lid'];
		if ( $revoked ) {
			$error = self::BLOCKING[ $verdict ];
		}
		$active = is_array( $payload ) && ! $revoked;

		$features = array();
		foreach ( self::PRO_FEATURES as $feature ) {
			$features[ $feature ] = $active;
		}
		return array(
			'active'   => $active,
			'plan'     => $active ? $payload['plan'] : 'free',
			'from'     => $stored['from'],
			'license'  => is_array( $payload ) ? $payload : null,
			'masked'   => '' === $stored['key'] ? null : substr( $stored['key'], 0, 9 ) . '…' . substr( $stored['key'], -6 ),
			'error'    => $error,
			'features' => $features,
			'free'     => array( 'resets', 'snapshots', 'auto_snapshots', 'restore', 'full_undo', 'import', 'cloud_pull', 'recovery_script' ),
			'server'   => self::server(),
			'remote'   => $saved['remote'] ?? null,
		);
	}

	public static function can( string $feature ): bool {
		if ( ! in_array( $feature, self::PRO_FEATURES, true ) ) {
			return true; // Free forever.
		}
		return (bool) ( self::status()['features'][ $feature ] ?? false );
	}

	/**
	 * Error for a gated action, or null when allowed.
	 */
	public static function require( string $feature ): ?WP_Error {
		if ( self::can( $feature ) ) {
			return null;
		}
		$labels = array( 'blueprints' => 'Blueprints', 'cloud' => 'Cloud offload' );
		return new WP_Error(
			'wp_chrono_pro_required',
			sprintf( '%s is a WP Chrono Pro feature. Enter a license key under Settings → License. (Restoring, importing and the recovery script are always free.)', $labels[ $feature ] ?? $feature ),
			array( 'status' => 402, 'feature' => $feature )
		);
	}

	/**
	 * Verifies and saves a key (and activates it on the license server, if one
	 * is configured).
	 *
	 * @return array<string,mixed>|WP_Error Status.
	 */
	public static function activate( string $key, ?string $server = null ) {
		$payload = self::parse( $key );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$saved = array( 'key' => trim( $key ), 'activated_at' => gmdate( 'c' ), 'server' => self::clean_server( $server ?? (string) ( get_option( self::OPTION )['server'] ?? '' ) ) );
		update_option( self::OPTION, $saved, false );
		delete_transient( self::CHECK_TRANSIENT );

		if ( '' !== self::server() ) {
			$remote = self::remote( 'activate', trim( $key ), $payload );
			if ( is_wp_error( $remote ) && 'wp_chrono_license_server_down' !== $remote->get_error_code() ) {
				update_option( self::OPTION, array_diff_key( $saved, array( 'key' => 1 ) ), false );
				return $remote;
			}
			if ( ! is_wp_error( $remote ) && 'active' !== $remote['status'] ) {
				update_option( self::OPTION, array_diff_key( $saved, array( 'key' => 1 ) ), false );
				$why = 'site_limit' === $remote['status'] ? ' ' . self::BLOCKING['site_limit'] : '';
				return new WP_Error( 'wp_chrono_license_revoked', 'The license server rejected this key (status: ' . $remote['status'] . ').' . $why, array( 'status' => 400 ) );
			}
			// The activation call counts as today's check.
			set_transient( self::CHECK_TRANSIENT, time(), DAY_IN_SECONDS );
		}
		return self::status();
	}

	/**
	 * @return array<string,mixed> Status.
	 */
	public static function deactivate(): array {
		$stored = self::stored_key();
		if ( 'option' === $stored['from'] && '' !== self::server() ) {
			$payload = self::parse( $stored['key'] );
			if ( is_array( $payload ) ) {
				self::remote( 'deactivate', $stored['key'], $payload );
			}
		}
		$saved = get_option( self::OPTION, array() );
		update_option( self::OPTION, array( 'server' => is_array( $saved ) ? (string) ( $saved['server'] ?? '' ) : '' ), false );
		delete_transient( self::CHECK_TRANSIENT );
		return self::status();
	}

	// ---------------------------------------------------------------------
	// Optional license server
	// ---------------------------------------------------------------------

	/**
	 * Server URL, first wins: the constant ('' = offline only), a saved URL,
	 * the WP Chrono API.
	 */
	public static function server(): string {
		if ( defined( 'WP_CHRONO_LICENSE_SERVER' ) ) {
			return self::clean_server( (string) WP_CHRONO_LICENSE_SERVER );
		}
		$saved = get_option( self::OPTION, array() );
		$url   = is_array( $saved ) ? self::clean_server( (string) ( $saved['server'] ?? '' ) ) : '';
		if ( '' !== $url ) {
			return $url;
		}
		$api = Updater::api();
		return '' === $api ? '' : self::clean_server( $api . '/v1/license' );
	}

	private static function clean_server( string $url ): string {
		$url = esc_url_raw( trim( $url ), array( 'https', 'http' ) );
		$parts = '' === $url ? array() : wp_parse_url( $url );
		if ( '' !== $url && 'http' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && ! self::is_local_server( (string) ( $parts['host'] ?? '' ) ) ) {
			return '';
		}
		return (string) $url;
	}

	private static function is_local_server( string $host ): bool {
		$host = strtolower( trim( $host, '[]' ) );
		return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	/**
	 * Daily check of the active key against the server (admin / REST / CLI only).
	 */
	public static function maybe_check( bool $force = false ): void {
		if ( '' === self::server() || ( ! $force && ( get_transient( self::CHECK_TRANSIENT ) || ! ( is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) ) ) {
			return;
		}
		set_transient( self::CHECK_TRANSIENT, time(), DAY_IN_SECONDS );
		$stored  = self::stored_key();
		$payload = '' === $stored['key'] ? null : self::parse( $stored['key'] );
		if ( is_array( $payload ) ) {
			self::remote( 'check', $stored['key'], $payload );
		}
	}

	/**
	 * Calls the server and records its signed verdict.
	 *
	 * @param array<string,mixed> $payload
	 * @return array{status:string,lid:string,ts:int}|WP_Error
	 */
	private static function remote( string $action, string $key, array $payload ) {
		$response = wp_remote_post(
			self::server(),
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( array( 'action' => $action, 'key' => $key, 'site' => home_url(), 'version' => WP_CHRONO_VERSION ) ),
			)
		);
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$saved['remote'] = array_merge( (array) ( $saved['remote'] ?? array() ), array( 'reachable' => false, 'checked_at' => gmdate( 'c' ) ) );
			update_option( self::OPTION, $saved, false );
			return new WP_Error( 'wp_chrono_license_server_down', 'The license server could not be reached; the offline key stays valid.' );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$sig  = is_array( $body ) ? self::b64u_decode( (string) ( $body['sig'] ?? '' ) ) : false;
		if ( ! is_array( $body ) || false === $sig || 64 !== strlen( $sig ) || ( $body['lid'] ?? '' ) !== $payload['lid'] || ( $body['site'] ?? '' ) !== home_url()
			|| ! self::verify( $sig, sprintf( 'WPCR1.%s.%s.%s.%d', $body['lid'], $body['site'], $body['status'] ?? '', (int) ( $body['ts'] ?? 0 ) ) )
			|| abs( time() - (int) $body['ts'] ) > DAY_IN_SECONDS ) {
			return new WP_Error( 'wp_chrono_license_server_invalid', 'The license server sent an unsigned or invalid answer; it was ignored.' );
		}
		$saved['remote'] = array( 'reachable' => true, 'status' => (string) $body['status'], 'lid' => (string) $body['lid'], 'checked_at' => gmdate( 'c' ) );
		update_option( self::OPTION, $saved, false );
		return array( 'status' => (string) $body['status'], 'lid' => (string) $body['lid'], 'ts' => (int) $body['ts'] );
	}

	/**
	 * @return string|false
	 */
	private static function b64u_decode( string $value ) {
		return base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- license key encoding.
	}
}
