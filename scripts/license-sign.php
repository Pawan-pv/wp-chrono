<?php
/**
 * Vendor tool (never shipped): mints Ed25519-signed WP Chrono license keys,
 * e.g. a batch of lifetime-deal codes for AppSumo.
 *
 *   php scripts/license-sign.php --plan=pro --sites=1 --source=appsumo --count=500 [--expires=YYYY-MM-DD] > codes.csv
 *
 * The secret key is read from --secret=FILE, $WPC_LICENSE_SECRET_FILE, or
 * ~/.wp-chrono-keys/license-ed25519.secret (see license-keygen.php).
 * Output: CSV with one key per row.
 *
 * Also usable as a library (the test suite mints test keys with it):
 *   require 'license-sign.php'; wpc_license_mint( $secret_raw, $payload );
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

if ( ! function_exists( 'wpc_license_mint' ) ) {
	/**
	 * @param string              $secret  Raw 64-byte Ed25519 secret key.
	 * @param array<string,mixed> $payload {lid, plan, sites, source, issued, expires}.
	 */
	function wpc_license_mint( string $secret, array $payload ): string {
		$b64u = static function ( string $raw ): string {
			return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
		};
		$body = $b64u( (string) json_encode( $payload ) );
		return 'WPC1.' . $body . '.' . $b64u( sodium_crypto_sign_detached( 'WPC1.' . $body, $secret ) );
	}

	/**
	 * Signs a license-server verdict (for a server implementation / tests).
	 */
	function wpc_license_server_sign( string $secret, string $lid, string $site, string $status, int $ts ): string {
		return rtrim( strtr( base64_encode( sodium_crypto_sign_detached( sprintf( 'WPCR1.%s.%s.%s.%d', $lid, $site, $status, $ts ), $secret ) ), '+/', '-_' ), '=' );
	}
}

if ( isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$opts = getopt( '', array( 'plan:', 'sites:', 'source:', 'count:', 'expires:', 'secret:', 'prefix:' ) );
	$home = getenv( 'HOME' ) ?: getenv( 'USERPROFILE' );
	$file = $opts['secret'] ?? ( getenv( 'WPC_LICENSE_SECRET_FILE' ) ?: $home . '/.wp-chrono-keys/license-ed25519.secret' );
	$raw  = is_readable( $file ) ? base64_decode( trim( (string) file_get_contents( $file ) ), true ) : false;
	if ( false === $raw || 64 !== strlen( $raw ) ) {
		fwrite( STDERR, "No valid secret key at {$file} (run license-keygen.php first).\n" );
		exit( 1 );
	}
	$plan = $opts['plan'] ?? 'pro';
	if ( ! in_array( $plan, array( 'pro', 'agency' ), true ) ) {
		fwrite( STDERR, "--plan must be pro or agency.\n" );
		exit( 1 );
	}
	$count = max( 1, (int) ( $opts['count'] ?? 1 ) );
	echo "license_key,lid,plan,sites,source,expires\n";
	for ( $i = 0; $i < $count; $i++ ) {
		$payload = array(
			'lid'     => ( $opts['prefix'] ?? ( $opts['source'] ?? 'direct' ) ) . '-' . bin2hex( random_bytes( 6 ) ),
			'plan'    => $plan,
			'sites'   => max( 1, (int) ( $opts['sites'] ?? ( 'agency' === $plan ? 100 : 1 ) ) ),
			'source'  => $opts['source'] ?? 'direct',
			'issued'  => gmdate( 'Y-m-d' ),
			'expires' => $opts['expires'] ?? null,
		);
		$key = wpc_license_mint( $raw, $payload );
		echo implode( ',', array( $key, $payload['lid'], $payload['plan'], $payload['sites'], $payload['source'], (string) $payload['expires'] ) ) . "\n";
	}
}
