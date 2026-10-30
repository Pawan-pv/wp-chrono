<?php
/**
 * Offline mock of the WP Chrono API's update routes (PHP built-in server router):
 *
 *   php -S 127.0.0.1:9101 /tests/mock-update.php
 *
 *   GET /v1/update/wp-chrono                 /tmp/wpc-update/latest.json + package URL
 *   GET /v1/download/wp-chrono/<file>.zip    /tmp/wpc-update/<file>.zip, or tampered.zip
 *                                            when /tmp/wpc-update/mode says "tamper"
 *
 * Every request URI is appended to /tmp/wpc-update/requests.log.
 */

$dir  = '/tmp/wpc-update';
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
file_put_contents( "$dir/requests.log", $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND );
$mode = is_readable( "$dir/mode" ) ? trim( (string) file_get_contents( "$dir/mode" ) ) : 'ok';

if ( '/v1/update/wp-chrono' === $path ) {
	if ( 'down' === $mode || ! is_readable( "$dir/latest.json" ) ) {
		http_response_code( 503 );
		exit;
	}
	$meta            = json_decode( (string) file_get_contents( "$dir/latest.json" ), true );
	$meta['package'] = 'http://localhost:9101/v1/download/wp-chrono/' . $meta['file'];
	header( 'Content-Type: application/json' );
	echo json_encode( $meta );
	exit;
}

if ( preg_match( '#^/v1/download/wp-chrono/(wp-chrono-[0-9.]+\.zip)$#', $path, $m ) ) {
	$file = 'tamper' === $mode ? "$dir/tampered.zip" : "$dir/{$m[1]}";
	if ( ! is_readable( $file ) ) {
		http_response_code( 404 );
		exit;
	}
	header( 'Content-Type: application/zip' );
	header( 'Content-Length: ' . filesize( $file ) );
	readfile( $file );
	exit;
}

http_response_code( 404 );
