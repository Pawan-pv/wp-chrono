<?php
/**
 * Plugin updates from the WP Chrono API (Cloudflare Worker), through the
 * `Update URI` header (WordPress 5.8+):
 *
 *   GET {api}/v1/update/wp-chrono?version=1.0.0
 *   200 {"version","package","sha256","signature","requires","requires_php","tested","changelog",…}
 *
 * Every package is verified before WordPress unpacks it: its SHA-256 must
 * match, and `signature` must be a valid Ed25519 signature of
 * "WPCU1.wp-chrono.{version}.{sha256}" by an offline release key in
 * RELEASE_KEYS. The API server cannot sign releases, so a compromised server
 * or bucket cannot push code to sites.
 *
 * Only the installed version is sent (no site URL, no license key). Metadata
 * is cached for 12 hours, failures for 1 hour. API base: the WP_CHRONO_API
 * constant, else the `Update URI` header's origin.
 *
 * @package WPChrono
 */

namespace WPChrono\Services;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Updater {

	const SLUG = 'wp-chrono';

	const CACHE = 'wp_chrono_update_info';

	/** Release-signing public keys (base64), newest first. Not the license key. */
	const RELEASE_KEYS = array(
		'2026-10' => 'HH03nclVZiog2/P5kpmz/j2jqCeB7YWLW1ibZqaFDbE=',
	);

	public static function register(): void {
		$host = wp_parse_url( self::update_uri(), PHP_URL_HOST );
		if ( $host ) {
			add_filter( 'update_plugins_' . $host, array( self::class, 'check' ), 10, 3 );
		}
		add_filter( 'plugins_api', array( self::class, 'details' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( self::class, 'download' ), 10, 3 );
		// "Check again" on Dashboard → Updates also refreshes our metadata.
		add_action( 'load-update-core.php', array( self::class, 'maybe_flush' ) );
	}

	/** The `Update URI` header of the main plugin file. */
	public static function update_uri(): string {
		static $uri = null;
		if ( null === $uri ) {
			$uri = (string) ( get_file_data( WP_CHRONO_FILE, array( 'uri' => 'Update URI' ) )['uri'] ?? '' );
		}
		return $uri;
	}

	/** API base URL, without a trailing slash. */
	public static function api(): string {
		if ( defined( 'WP_CHRONO_API' ) && '' !== (string) WP_CHRONO_API ) {
			return untrailingslashit( (string) WP_CHRONO_API );
		}
		$parts = wp_parse_url( self::update_uri() );
		return empty( $parts['host'] ) ? '' : ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	public static function maybe_flush(): void {
		if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache flush, same as core.
			delete_site_transient( self::CACHE );
		}
	}

	/**
	 * Release metadata, from cache or the API.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function info( bool $refresh = false ): ?array {
		$cached = $refresh ? false : get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
		$info = self::fetch();
		set_site_transient( self::CACHE, $info ?? array( 'version' => '' ), null === $info ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );
		return $info;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function fetch(): ?array {
		$api = self::api();
		if ( '' === $api ) {
			return null;
		}
		$response = wp_remote_get(
			add_query_arg( 'version', rawurlencode( WP_CHRONO_VERSION ), $api . '/v1/update/' . self::SLUG ),
			array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ) )
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || self::SLUG !== ( $data['slug'] ?? '' )
			|| ! preg_match( '/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', (string) ( $data['version'] ?? '' ) )
			|| ! preg_match( '/^[0-9a-f]{64}$/', (string) ( $data['sha256'] ?? '' ) )
			|| '' === (string) ( $data['signature'] ?? '' )
			|| 0 !== strpos( (string) ( $data['package'] ?? '' ), $api . '/' ) ) {
			return null;
		}
		return $data;
	}

	/**
	 * `update_plugins_{host}`: our entry in the core update check.
	 *
	 * @param array|false $update
	 * @param array       $plugin_data
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( WP_CHRONO_BASENAME !== $plugin_file ) {
			return $update;
		}
		$info = self::info();
		if ( null === $info ) {
			return $update;
		}
		return array(
			'slug'         => self::SLUG,
			'version'      => (string) $info['version'],
			'url'          => (string) ( $info['homepage'] ?? '' ),
			'package'      => (string) $info['package'],
			'tested'       => (string) ( $info['tested'] ?? '' ),
			'requires'     => (string) ( $info['requires'] ?? '' ),
			'requires_php' => (string) ( $info['requires_php'] ?? '' ),
		);
	}

	/**
	 * `plugins_api`: the "View details" modal.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$info = self::info();
		if ( null === $info ) {
			return $result;
		}
		return (object) array(
			'name'          => 'WP Chrono',
			'slug'          => self::SLUG,
			'version'       => (string) $info['version'],
			'author'        => 'WP Chrono',
			'homepage'      => (string) ( $info['homepage'] ?? '' ),
			'requires'      => (string) ( $info['requires'] ?? '' ),
			'tested'        => (string) ( $info['tested'] ?? '' ),
			'requires_php'  => (string) ( $info['requires_php'] ?? '' ),
			'last_updated'  => (string) ( $info['released'] ?? '' ),
			'download_link' => (string) $info['package'],
			'sections'      => array(
				'description' => wp_kses_post( (string) ( $info['description'] ?? '' ) ),
				'changelog'   => wp_kses_post( (string) ( $info['changelog'] ?? '' ) ),
			),
		);
	}

	/**
	 * `upgrader_pre_download`: downloads our package and refuses it unless the
	 * hash and the release signature check out.
	 *
	 * @param false|string|WP_Error $reply
	 * @param string                $package
	 * @return false|string|WP_Error
	 */
	public static function download( $reply, $package, $upgrader ) {
		$api = self::api();
		if ( false !== $reply || '' === $api || 0 !== strpos( (string) $package, $api . '/v1/download/' . self::SLUG . '/' ) ) {
			return $reply;
		}
		$info = self::info();
		if ( null === $info || $info['package'] !== $package ) {
			$info = self::info( true ); // The cache may predate this package.
		}
		if ( null === $info || $info['package'] !== $package ) {
			return new WP_Error( 'wp_chrono_update_unknown', 'This WP Chrono package is not the current release; update refused.' );
		}

		if ( isset( $upgrader->skin ) && is_object( $upgrader->skin ) && method_exists( $upgrader->skin, 'feedback' ) ) {
			$upgrader->skin->feedback( 'downloading_package', $package );
		}
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$error = self::verify( $file, (string) $info['version'], (string) $info['sha256'], (string) $info['signature'] );
		if ( $error ) {
			wp_delete_file( $file );
			return $error;
		}
		return $file;
	}

	/**
	 * @return WP_Error|null Null when the file is the signed release.
	 */
	public static function verify( string $file, string $version, string $sha256, string $signature ): ?WP_Error {
		$actual = hash_file( 'sha256', $file );
		if ( ! is_string( $actual ) || ! hash_equals( $sha256, $actual ) ) {
			return new WP_Error( 'wp_chrono_update_hash', 'The downloaded WP Chrono package is corrupt (SHA-256 mismatch); update refused.' );
		}
		$sig = base64_decode( strtr( $signature, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $signature ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- signature encoding.
		$msg = 'WPCU1.' . self::SLUG . '.' . $version . '.' . $sha256;
		if ( false !== $sig && 64 === strlen( $sig ) ) {
			foreach ( self::release_keys() as $b64 ) {
				$pub = base64_decode( (string) $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- decoding the embedded public key.
				try {
					if ( false !== $pub && 32 === strlen( $pub ) && sodium_crypto_sign_verify_detached( $sig, $msg, $pub ) ) {
						return null;
					}
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}
		return new WP_Error( 'wp_chrono_update_signature', 'The WP Chrono package is not signed by the WP Chrono release key; update refused.' );
	}

	/**
	 * @return string[]
	 */
	public static function release_keys(): array {
		/**
		 * Trusted release-signing public keys. Used by the test suite to add a test key.
		 *
		 * @param string[] $keys
		 */
		return array_values( array_filter( (array) apply_filters( 'wp_chrono_release_public_keys', array_values( self::RELEASE_KEYS ) ), 'is_string' ) );
	}
}
