<?php
/**
 * MU plugin for update-checks.sh (copied into the throwaway update test site,
 * never into the main dev site). Settings come from /tmp/wpc-update/env.json:
 *
 *   api          WP_CHRONO_API base (mock server or the local Worker)
 *   release_pub  test release public key, trusted next to the real one
 *   license_pub  test license public key (worker mode: the Worker's dev key)
 *
 * It also answers WordPress.org's update check with "no updates", because
 * core only consults `Update URI` updaters after that request succeeds, and
 * lets download_url() reach the local test hosts.
 */

$wpc_env = json_decode( (string) @file_get_contents( '/tmp/wpc-update/env.json' ), true ) ?: array();

if ( ! empty( $wpc_env['api'] ) && ! defined( 'WP_CHRONO_API' ) ) {
	define( 'WP_CHRONO_API', $wpc_env['api'] );
}

add_filter( 'wp_chrono_release_public_keys', static function ( $keys ) use ( $wpc_env ) {
	return empty( $wpc_env['release_pub'] ) ? $keys : array_merge( $keys, array( $wpc_env['release_pub'] ) );
} );

add_filter( 'wp_chrono_license_public_keys', static function ( $keys ) use ( $wpc_env ) {
	return empty( $wpc_env['license_pub'] ) ? $keys : array_merge( $keys, array( $wpc_env['license_pub'] ) );
} );

add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	if ( false !== strpos( $url, 'api.wordpress.org/plugins/update-check/' ) ) {
		return array(
			'headers'  => array(),
			'body'     => '{"plugins":[],"translations":[],"no_update":[]}',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}
	return $pre;
}, 10, 3 );

// download_url() refuses private hosts and ports other than 80/443/8080; the
// test APIs run on localhost:9101 or host.docker.internal:5173.
add_filter( 'http_request_args', static function ( $args, $url ) use ( $wpc_env ) {
	if ( ! empty( $wpc_env['api'] ) && 0 === strpos( $url, $wpc_env['api'] . '/' ) ) {
		$args['reject_unsafe_urls'] = false;
	}
	return $args;
}, 10, 2 );

// Simulates another domain using the same key (license seat checks).
add_filter( 'home_url', static function ( $url ) {
	$other = getenv( 'WPC_FAKE_HOME' );
	return $other ? $other : $url;
} );
