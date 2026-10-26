<?php
/**
 * Bring-your-own-cloud storage: a zero-dependency S3-compatible client for
 * Cloudflare R2, AWS S3, Backblaze B2 (and MinIO, Wasabi, …).
 *
 * - Requests are signed with AWS Signature Version 4 (sign_v4()), written
 *   from the spec; no SDK.
 * - Large files stream through PHP's cURL extension (upload from / download
 *   to disk), so memory stays flat for multi-GB snapshots. Without cURL,
 *   small transfers fall back to wp_remote_request().
 * - The secret key is encrypted at rest with libsodium's secretbox
 *   (XSalsa20-Poly1305), or AES-256-GCM via OpenSSL where sodium is missing.
 *   The key is derived from the site's auth salt and never leaves the server;
 *   the REST API only ever reports whether a secret is stored.
 *
 * Settings live in the autoload=off option `wp_chrono_cloud`.
 *
 * @package WPChrono
 */

namespace WPChrono\Services;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CloudStorageService {

	const OPTION = 'wp_chrono_cloud';

	const PROVIDERS = array(
		'r2'     => array( 'label' => 'Cloudflare R2', 'endpoint' => 'https://<account-id>.r2.cloudflarestorage.com', 'region' => 'auto', 'path_style' => true ),
		's3'     => array( 'label' => 'Amazon S3', 'endpoint' => 'https://s3.<region>.amazonaws.com', 'region' => 'us-east-1', 'path_style' => false ), // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- endpoint template for the user's own backup bucket; no assets are loaded from it.
		'b2'     => array( 'label' => 'Backblaze B2', 'endpoint' => 'https://s3.<region>.backblazeb2.com', 'region' => 'us-west-004', 'path_style' => true ),
		'custom' => array( 'label' => 'S3-compatible', 'endpoint' => '', 'region' => 'us-east-1', 'path_style' => true ),
	);

	const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/** @var array<string,mixed> */
	private $config;

	/**
	 * @param array<string,mixed>|null $config Raw settings incl. decrypted `secret_key`; null = saved settings.
	 */
	public function __construct( ?array $config = null ) {
		if ( null === $config ) {
			$config               = self::stored();
			$config['secret_key'] = self::decrypt( (string) ( $config['secret'] ?? '' ) );
		}
		$this->config = $config;
	}

	// ---------------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	private static function stored(): array {
		$saved = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'provider'   => 'r2',
				'endpoint'   => '',
				'region'     => 'auto',
				'bucket'     => '',
				'access_key' => '',
				'secret'     => '',
				'path_style' => true,
				'prefix'     => 'wp-chrono/',
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	/**
	 * Settings safe to send to the browser: the secret is never included.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$s = self::stored();
		return array(
			'provider'   => $s['provider'],
			'endpoint'   => $s['endpoint'],
			'region'     => $s['region'],
			'bucket'     => $s['bucket'],
			'access_key' => $s['access_key'],
			'has_secret' => '' !== $s['secret'] && '' !== self::decrypt( (string) $s['secret'] ),
			'path_style' => (bool) $s['path_style'],
			'prefix'     => $s['prefix'],
			'configured' => self::configured(),
			'encryption' => function_exists( 'sodium_crypto_secretbox' ) ? 'libsodium secretbox (XSalsa20-Poly1305)' : 'AES-256-GCM (OpenSSL)',
			'providers'  => self::PROVIDERS,
		);
	}

	public static function configured(): bool {
		$s = self::stored();
		return '' !== $s['endpoint'] && '' !== $s['bucket'] && '' !== $s['access_key'] && '' !== $s['secret'];
	}

	/**
	 * Validates and saves settings. An empty `secret_key` keeps the stored one.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error Public settings.
	 */
	public static function save( array $input ) {
		$current = self::stored();
		$next    = self::normalize( $input, $current );
		if ( is_wp_error( $next ) ) {
			return $next;
		}
		$secret = (string) ( $input['secret_key'] ?? '' );
		if ( '' !== $secret ) {
			$next['secret'] = self::encrypt( $secret );
			if ( '' === $next['secret'] ) {
				return new WP_Error( 'wp_chrono_cloud_crypto', 'Neither libsodium nor OpenSSL is available to encrypt the secret key.', array( 'status' => 500 ) );
			}
		}
		update_option( self::OPTION, $next, false );
		return self::settings();
	}

	/**
	 * Merged, validated config (secret untouched).
	 *
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $current
	 * @return array<string,mixed>|WP_Error
	 */
	private static function normalize( array $input, array $current ) {
		$next = $current;
		foreach ( array( 'provider', 'endpoint', 'region', 'bucket', 'access_key', 'prefix' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$next[ $key ] = trim( sanitize_text_field( (string) $input[ $key ] ) );
			}
		}
		if ( isset( $input['path_style'] ) ) {
			$next['path_style'] = (bool) $input['path_style'];
		}
		if ( ! isset( self::PROVIDERS[ $next['provider'] ] ) ) {
			$next['provider'] = 'custom';
		}
		$next['endpoint'] = untrailingslashit( $next['endpoint'] );
		if ( '' !== $next['endpoint'] && ( ! preg_match( '#^https?://[^/\s<>]+$#i', $next['endpoint'] ) || false === wp_parse_url( $next['endpoint'] ) ) ) {
			return new WP_Error( 'wp_chrono_cloud_endpoint', 'The endpoint must be a URL like https://<account-id>.r2.cloudflarestorage.com (no path).', array( 'status' => 400 ) );
		}
		if ( '' !== $next['bucket'] && ! preg_match( '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $next['bucket'] ) ) {
			return new WP_Error( 'wp_chrono_cloud_bucket', 'Bucket names are 3–63 characters: lowercase letters, numbers, dots and hyphens.', array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^[a-z0-9-]*$/', $next['region'] ) ) {
			return new WP_Error( 'wp_chrono_cloud_region', 'Invalid region.', array( 'status' => 400 ) );
		}
		$next['region'] = '' === $next['region'] ? 'auto' : $next['region'];
		$prefix         = trim( str_replace( '\\', '/', $next['prefix'] ), '/' );
		$next['prefix'] = '' === $prefix ? '' : $prefix . '/';
		return $next;
	}

	// ---------------------------------------------------------------------
	// Secret encryption at rest
	// ---------------------------------------------------------------------

	private static function crypto_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|wp-chrono-cloud|v1', true );
	}

	public static function encrypt( string $plain ): string {
		$key = self::crypto_key();
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 'sb1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return false === $cipher ? '' : 'gcm1:' . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		return '';
	}

	/**
	 * @return string Plaintext, or '' when missing / undecryptable (e.g. salts changed).
	 */
	public static function decrypt( string $stored ): string {
		$key = self::crypto_key();
		if ( 0 === strpos( $stored, 'sb1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
			return false === $plain ? '' : $plain;
		}
		if ( 0 === strpos( $stored, 'gcm1:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $stored, 5 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return '';
			}
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $plain ? '' : $plain;
		}
		return '';
	}

	// ---------------------------------------------------------------------
	// AWS Signature Version 4
	// ---------------------------------------------------------------------

	/**
	 * Signs a request (AWS SigV4, header-based). Every header passed in is
	 * signed; `host` and `x-amz-date` are added when missing.
	 *
	 * @param array<string,string> $headers      Header name => value.
	 * @param string               $payload_hash Hex SHA-256 of the body, or UNSIGNED-PAYLOAD.
	 * @return array<string,string> Headers to send, including Authorization.
	 */
	public static function sign_v4( string $method, string $url, array $headers, string $payload_hash, string $access_key, string $secret_key, string $region, string $service = 's3', ?int $time = null ): array {
		$time  = $time ?? time();
		$parts = wp_parse_url( $url );
		$host  = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$date  = gmdate( 'Ymd', $time );
		$stamp = gmdate( 'Ymd\THis\Z', $time );

		$canonical = array();
		foreach ( $headers as $name => $value ) {
			$canonical[ strtolower( trim( $name ) ) ] = trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );
		}
		$canonical += array( 'host' => $host, 'x-amz-date' => $stamp );
		ksort( $canonical, SORT_STRING );

		$signed_headers = implode( ';', array_keys( $canonical ) );
		$header_lines   = '';
		foreach ( $canonical as $name => $value ) {
			$header_lines .= "{$name}:{$value}\n";
		}

		$request = implode(
			"\n",
			array(
				strtoupper( $method ),
				self::canonical_uri( (string) ( $parts['path'] ?? '/' ) ),
				self::canonical_query( (string) ( $parts['query'] ?? '' ) ),
				$header_lines,
				$signed_headers,
				$payload_hash,
			)
		);

		$scope   = "{$date}/{$region}/{$service}/aws4_request";
		$to_sign = "AWS4-HMAC-SHA256\n{$stamp}\n{$scope}\n" . hash( 'sha256', $request );
		$k_date  = hash_hmac( 'sha256', $date, 'AWS4' . $secret_key, true );
		$k_reg   = hash_hmac( 'sha256', $region, $k_date, true );
		$k_svc   = hash_hmac( 'sha256', $service, $k_reg, true );
		$k_sign  = hash_hmac( 'sha256', 'aws4_request', $k_svc, true );
		$sig     = hash_hmac( 'sha256', $to_sign, $k_sign );

		$out = array();
		foreach ( $headers as $name => $value ) {
			$out[ $name ] = (string) $value;
		}
		$out['x-amz-date']    = $canonical['x-amz-date'];
		$out['Authorization'] = "AWS4-HMAC-SHA256 Credential={$access_key}/{$scope}, SignedHeaders={$signed_headers}, Signature={$sig}";
		return $out;
	}

	/** Each path segment URI-encoded once (S3 rules: unreserved chars and `/` kept). */
	private static function canonical_uri( string $path ): string {
		if ( '' === $path ) {
			return '/';
		}
		return implode( '/', array_map( static function ( $segment ) {
			return rawurlencode( rawurldecode( $segment ) );
		}, explode( '/', $path ) ) );
	}

	private static function canonical_query( string $query ): string {
		if ( '' === $query ) {
			return '';
		}
		$pairs = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			list( $key, $value ) = array_pad( explode( '=', $pair, 2 ), 2, '' );
			$pairs[]             = array( rawurlencode( rawurldecode( $key ) ), rawurlencode( rawurldecode( $value ) ) );
		}
		usort(
			$pairs,
			static function ( $a, $b ) {
				return strcmp( $a[0], $b[0] ) ?: strcmp( $a[1], $b[1] );
			}
		);
		return implode( '&', array_map( static function ( $p ) {
			return $p[0] . '=' . $p[1];
		}, $pairs ) );
	}

	// ---------------------------------------------------------------------
	// Object operations
	// ---------------------------------------------------------------------

	/**
	 * Lists, writes and deletes a small probe object.
	 *
	 * @return array{ok:bool,ms:int,objects:int,message:string}|WP_Error
	 */
	public function test() {
		$started = microtime( true );
		$list    = $this->list_objects( (string) $this->config['prefix'], 5 );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$probe = $this->config['prefix'] . '.wp-chrono-probe-' . strtolower( wp_generate_password( 8, false ) );
		$put   = $this->put_string( $probe, 'WP Chrono connection test ' . gmdate( 'c' ), 'text/plain' );
		if ( is_wp_error( $put ) ) {
			return $put;
		}
		$get = $this->get_string( $probe );
		$del = $this->delete( $probe );
		if ( is_wp_error( $get ) ) {
			return $get;
		}
		if ( is_wp_error( $del ) ) {
			return $del;
		}
		return array(
			'ok'      => true,
			'ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'objects' => count( $list ),
			'message' => sprintf( 'Connected to %s: listed, wrote, read back and deleted a test object.', $this->config['bucket'] ),
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public function put_file( string $key, string $path, string $content_type = 'application/octet-stream' ) {
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'wp_chrono_cloud_io', "Cannot read {$path}." );
		}
		$r = $this->request( 'PUT', $key, '', array( 'file' => $path, 'type' => $content_type ) );
		return is_wp_error( $r ) ? $r : true;
	}

	/**
	 * @return true|WP_Error
	 */
	public function put_string( string $key, string $body, string $content_type = 'application/json' ) {
		$r = $this->request( 'PUT', $key, '', array( 'body' => $body, 'type' => $content_type ) );
		return is_wp_error( $r ) ? $r : true;
	}

	/**
	 * @return string|WP_Error
	 */
	public function get_string( string $key ) {
		$r = $this->request( 'GET', $key );
		return is_wp_error( $r ) ? $r : $r['body'];
	}

	/**
	 * Streams an object to disk.
	 *
	 * @return true|WP_Error
	 */
	public function get_file( string $key, string $path ) {
		$r = $this->request( 'GET', $key, '', array( 'sink' => $path ) );
		if ( is_wp_error( $r ) ) {
			@unlink( $path ); // phpcs:ignore
			return $r;
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public function delete( string $key ) {
		$r = $this->request( 'DELETE', $key );
		return is_wp_error( $r ) ? $r : true;
	}

	/**
	 * ListObjectsV2 with continuation.
	 *
	 * @return array<int,array{key:string,size:int,modified:string}>|WP_Error
	 */
	public function list_objects( string $prefix, int $max = 1000 ) {
		$objects = array();
		$token   = '';
		do {
			$query = 'list-type=2&max-keys=' . min( 1000, $max ) . '&prefix=' . rawurlencode( $prefix ) . ( '' !== $token ? '&continuation-token=' . rawurlencode( $token ) : '' );
			$r     = $this->request( 'GET', '', $query );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			preg_match_all( '#<Contents>(.*?)</Contents>#s', $r['body'], $m );
			foreach ( $m[1] as $block ) {
				$objects[] = array(
					'key'      => self::xml_value( $block, 'Key' ),
					'size'     => (int) self::xml_value( $block, 'Size' ),
					'modified' => self::xml_value( $block, 'LastModified' ),
				);
			}
			$token = 'true' === self::xml_value( $r['body'], 'IsTruncated' ) ? self::xml_value( $r['body'], 'NextContinuationToken' ) : '';
		} while ( '' !== $token && count( $objects ) < $max );
		return array_slice( $objects, 0, $max );
	}

	private static function xml_value( string $xml, string $tag ): string {
		return preg_match( '#<' . $tag . '>(.*?)</' . $tag . '>#s', $xml, $m ) ? html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) : '';
	}

	public function url( string $key, string $query = '' ): string {
		$endpoint = (string) $this->config['endpoint'];
		$bucket   = (string) $this->config['bucket'];
		$path     = '' === $key ? '' : implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
		if ( ! empty( $this->config['path_style'] ) ) {
			$url = $endpoint . '/' . $bucket . ( '' === $key ? '' : '/' . $path );
		} else {
			$url = preg_replace( '#^(https?://)#i', '$1' . $bucket . '.', $endpoint ) . '/' . $path;
		}
		return $url . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * One signed request.
	 *
	 * @param array{file?:string,body?:string,type?:string,sink?:string} $opts
	 * @return array{status:int,body:string}|WP_Error
	 */
	private function request( string $method, string $key, string $query = '', array $opts = array() ) {
		foreach ( array( 'endpoint', 'bucket', 'access_key', 'secret_key' ) as $required ) {
			if ( '' === (string) ( $this->config[ $required ] ?? '' ) ) {
				return new WP_Error( 'wp_chrono_cloud_config', 'Cloud storage is not fully configured (endpoint, bucket, access key and secret key are required).', array( 'status' => 400 ) );
			}
		}
		$url     = $this->url( $key, $query );
		$hash    = isset( $opts['file'] ) ? (string) hash_file( 'sha256', $opts['file'] ) : ( isset( $opts['body'] ) ? hash( 'sha256', $opts['body'] ) : self::EMPTY_SHA256 );
		$headers = array( 'x-amz-content-sha256' => $hash );
		if ( isset( $opts['type'] ) ) {
			$headers['content-type'] = $opts['type'];
		}
		$headers = self::sign_v4( $method, $url, $headers, $hash, (string) $this->config['access_key'], (string) $this->config['secret_key'], (string) $this->config['region'] );

		$result = function_exists( 'curl_init' ) ? $this->curl( $method, $url, $headers, $opts ) : $this->wp_http( $method, $url, $headers, $opts );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $result['status'] < 200 || $result['status'] >= 300 ) {
			$body = isset( $opts['sink'] ) && is_file( $opts['sink'] ) ? (string) file_get_contents( $opts['sink'], false, null, 0, 4096 ) : $result['body']; // phpcs:ignore WordPress.WP.AlternativeFunctions
			$code = self::xml_value( $body, 'Code' );
			$msg  = rtrim( self::xml_value( $body, 'Message' ), '.' );
			return new WP_Error(
				'wp_chrono_cloud_http',
				sprintf( 'Cloud storage answered HTTP %d%s%s.', $result['status'], '' !== $code ? " {$code}" : '', '' !== $msg ? ": {$msg}" : '' ),
				array( 'status' => 502, 'http_status' => $result['status'], 's3_code' => $code )
			);
		}
		return $result;
	}

	/**
	 * @param array<string,string> $headers
	 * @param array<string,string> $opts
	 * @return array{status:int,body:string}|WP_Error
	 */
	private function curl( string $method, string $url, array $headers, array $opts ) {
		$ch    = curl_init( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$lines = array();
		foreach ( $headers as $name => $value ) {
			$lines[] = "{$name}: {$value}";
		}
		$lines[] = 'Expect:'; // No 100-continue round trip.
		$in      = null;
		$sink    = null;
		// phpcs:disable WordPress.WP.AlternativeFunctions
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_HTTPHEADER     => $lines,
				CURLOPT_CONNECTTIMEOUT => 20,
				CURLOPT_TIMEOUT        => 0,
				CURLOPT_LOW_SPEED_LIMIT => 1,
				CURLOPT_LOW_SPEED_TIME => 120,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_CAINFO         => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_RETURNTRANSFER => true,
			)
		);
		if ( isset( $opts['file'] ) ) {
			$in = fopen( $opts['file'], 'rb' );
			curl_setopt( $ch, CURLOPT_UPLOAD, true );
			curl_setopt( $ch, CURLOPT_INFILE, $in );
			curl_setopt( $ch, CURLOPT_INFILESIZE, (int) filesize( $opts['file'] ) );
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $method );
		} elseif ( isset( $opts['body'] ) ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $opts['body'] );
		}
		if ( isset( $opts['sink'] ) ) {
			$sink = fopen( $opts['sink'], 'wb' );
			if ( ! $sink ) {
				return new WP_Error( 'wp_chrono_cloud_io', "Cannot write {$opts['sink']}." );
			}
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
			curl_setopt( $ch, CURLOPT_FILE, $sink );
		}
		$body   = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$error  = curl_error( $ch );
		curl_close( $ch );
		if ( $in ) {
			fclose( $in );
		}
		if ( $sink ) {
			fclose( $sink );
		}
		// phpcs:enable
		if ( false === $body ) {
			return new WP_Error( 'wp_chrono_cloud_network', 'Could not reach cloud storage: ' . $error, array( 'status' => 502 ) );
		}
		return array( 'status' => $status, 'body' => is_string( $body ) ? $body : '' );
	}

	/**
	 * Fallback without cURL: whole bodies in memory (fine for manifests,
	 * capped for files).
	 *
	 * @param array<string,string> $headers
	 * @param array<string,string> $opts
	 * @return array{status:int,body:string}|WP_Error
	 */
	private function wp_http( string $method, string $url, array $headers, array $opts ) {
		$body = $opts['body'] ?? null;
		if ( isset( $opts['file'] ) ) {
			if ( filesize( $opts['file'] ) > 100 * MB_IN_BYTES ) {
				return new WP_Error( 'wp_chrono_cloud_curl', 'Files over 100 MB need PHP\'s cURL extension.' );
			}
			$body = file_get_contents( $opts['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'body'    => $body,
			'timeout' => 300,
		);
		if ( isset( $opts['sink'] ) ) {
			$args['stream']   = true;
			$args['filename'] = $opts['sink'];
		}
		$r = wp_remote_request( $url, $args );
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'wp_chrono_cloud_network', 'Could not reach cloud storage: ' . $r->get_error_message(), array( 'status' => 502 ) );
		}
		return array( 'status' => (int) wp_remote_retrieve_response_code( $r ), 'body' => (string) wp_remote_retrieve_body( $r ) );
	}

	/**
	 * Folder for this site's backups: `{prefix}{host-and-path}/`.
	 */
	public function site_prefix(): string {
		$home = wp_parse_url( home_url() );
		$slug = sanitize_title( ( $home['host'] ?? 'site' ) . ( isset( $home['port'] ) ? '-' . $home['port'] : '' ) . ( $home['path'] ?? '' ) );
		return $this->config['prefix'] . ( '' === $slug ? 'site' : $slug ) . '/';
	}
}
