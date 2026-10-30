<?php
/**
 * Minimal offline S3 endpoint for tests (PHP built-in server router):
 *
 *   php -S 127.0.0.1:9000 /tests/mock-s3.php
 *
 * Path-style only: /{bucket}/{key}. Supports PUT / GET / HEAD / DELETE object
 * and ListObjectsV2 (GET /{bucket}?list-type=2&prefix=…&max-keys=…&continuation-token=…).
 *
 * Every request must carry a valid AWS Signature V4 for access key
 * MOCKACCESSKEY / secret below, and x-amz-content-sha256 must match the body.
 * The verifier is written independently of CloudStorageService::sign_v4().
 * Objects live in /tmp/mock-s3/data, requests are logged to /tmp/mock-s3/requests.log.
 */

const MOCK_ACCESS = 'MOCKACCESSKEY';
const MOCK_SECRET = 'mock/Secret+Key/0123456789abcdefghij';
const MOCK_ROOT   = '/tmp/mock-s3';

function s3_error( int $status, string $code, string $message ): void {
	http_response_code( $status );
	header( 'Content-Type: application/xml' );
	echo '<?xml version="1.0" encoding="UTF-8"?><Error><Code>' . $code . '</Code><Message>' . htmlspecialchars( $message, ENT_XML1 ) . '</Message></Error>';
	exit;
}

function s3_log( string $line ): void {
	file_put_contents( MOCK_ROOT . '/requests.log', gmdate( 'H:i:s' ) . ' ' . $line . "\n", FILE_APPEND );
}

// --- Request -----------------------------------------------------------------
$method  = $_SERVER['REQUEST_METHOD'];
$uri     = $_SERVER['REQUEST_URI'];
$rawpath = (string) parse_url( $uri, PHP_URL_PATH );
$rawq    = (string) parse_url( $uri, PHP_URL_QUERY );
$headers = array();
foreach ( getallheaders() as $name => $value ) {
	$headers[ strtolower( $name ) ] = $value;
}
$body = in_array( $method, array( 'PUT', 'POST' ), true ) ? (string) file_get_contents( 'php://input' ) : '';

// --- Verify SigV4 ----------------------------------------------------------------
$auth = $headers['authorization'] ?? '';
if ( ! preg_match( '#^AWS4-HMAC-SHA256 Credential=([^/]+)/(\d{8})/([^/]+)/s3/aws4_request, SignedHeaders=([a-z0-9;-]+), Signature=([0-9a-f]{64})$#', $auth, $a ) ) {
	s3_log( "{$method} {$uri} 403 no-auth" );
	s3_error( 403, 'AccessDenied', 'Missing or malformed Authorization header.' );
}
list( , $access, $date, $region, $signed_list, $given ) = $a;
if ( MOCK_ACCESS !== $access ) {
	s3_error( 403, 'InvalidAccessKeyId', 'The AWS Access Key Id you provided does not exist in our records.' );
}
$payload = $headers['x-amz-content-sha256'] ?? '';
if ( 'UNSIGNED-PAYLOAD' !== $payload && hash( 'sha256', $body ) !== $payload ) {
	s3_error( 400, 'XAmzContentSHA256Mismatch', 'The provided x-amz-content-sha256 header does not match what was computed.' );
}

$canon_headers = '';
foreach ( explode( ';', $signed_list ) as $h ) {
	$value          = 'host' === $h ? ( $headers['host'] ?? '' ) : ( $headers[ $h ] ?? '' );
	$canon_headers .= $h . ':' . trim( preg_replace( '/\s+/', ' ', $value ) ) . "\n";
}
$query_pairs = array();
if ( '' !== $rawq ) {
	foreach ( explode( '&', $rawq ) as $p ) {
		$kv            = explode( '=', $p, 2 );
		$query_pairs[] = rawurlencode( rawurldecode( $kv[0] ) ) . '=' . rawurlencode( rawurldecode( $kv[1] ?? '' ) );
	}
	sort( $query_pairs, SORT_STRING );
}
$segments = array();
foreach ( explode( '/', $rawpath ) as $seg ) {
	$segments[] = rawurlencode( rawurldecode( $seg ) );
}
$canonical = $method . "\n" . implode( '/', $segments ) . "\n" . implode( '&', $query_pairs ) . "\n" . $canon_headers . "\n" . $signed_list . "\n" . $payload;
$scope     = "{$date}/{$region}/s3/aws4_request";
$sts       = "AWS4-HMAC-SHA256\n" . ( $headers['x-amz-date'] ?? '' ) . "\n{$scope}\n" . hash( 'sha256', $canonical );
$key       = 'AWS4' . MOCK_SECRET;
foreach ( array( $date, $region, 's3', 'aws4_request' ) as $part ) {
	$key = hash_hmac( 'sha256', $part, $key, true );
}
if ( ! hash_equals( hash_hmac( 'sha256', $sts, $key ), $given ) ) {
	s3_log( "{$method} {$uri} 403 bad-signature" );
	s3_error( 403, 'SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided.' );
}

// --- Route ---------------------------------------------------------------------
$parts  = explode( '/', ltrim( $rawpath, '/' ), 2 );
$bucket = rawurldecode( $parts[0] );
$okey   = rawurldecode( $parts[1] ?? '' );
if ( ! preg_match( '/^[a-z0-9.-]{3,63}$/', $bucket ) || false !== strpos( $okey, '..' ) ) {
	s3_error( 400, 'InvalidRequest', 'Bad bucket or key.' );
}
$dir  = MOCK_ROOT . '/data/' . $bucket;
$file = $dir . '/' . $okey;
s3_log( "{$method} /{$bucket}/{$okey}" . ( '' !== $rawq ? "?{$rawq}" : '' ) . ' ' . strlen( $body ) . 'B' );

if ( '' === $okey && 'GET' === $method ) {
	parse_str( $rawq, $q );
	$prefix = (string) ( $q['prefix'] ?? '' );
	$max    = max( 1, min( 1000, (int) ( $q['max-keys'] ?? 1000 ) ) );
	$after  = (string) ( $q['continuation-token'] ?? '' );
	$keys   = array();
	if ( is_dir( $dir ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$k = substr( $f->getPathname(), strlen( $dir ) + 1 );
			if ( 0 === strpos( $k, $prefix ) && ( '' === $after || strcmp( $k, $after ) > 0 ) ) {
				$keys[ $k ] = $f;
			}
		}
	}
	ksort( $keys, SORT_STRING );
	$page      = array_slice( $keys, 0, $max, true );
	$truncated = count( $keys ) > $max;
	header( 'Content-Type: application/xml' );
	echo '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult><Name>' . $bucket . '</Name><Prefix>' . htmlspecialchars( $prefix, ENT_XML1 ) . '</Prefix><KeyCount>' . count( $page ) . '</KeyCount><MaxKeys>' . $max . '</MaxKeys><IsTruncated>' . ( $truncated ? 'true' : 'false' ) . '</IsTruncated>';
	foreach ( $page as $k => $f ) {
		echo '<Contents><Key>' . htmlspecialchars( $k, ENT_XML1 ) . '</Key><LastModified>' . gmdate( 'Y-m-d\TH:i:s.000\Z', $f->getMTime() ) . '</LastModified><Size>' . $f->getSize() . '</Size><StorageClass>STANDARD</StorageClass></Contents>';
	}
	if ( $truncated ) {
		echo '<NextContinuationToken>' . htmlspecialchars( (string) array_key_last( $page ), ENT_XML1 ) . '</NextContinuationToken>';
	}
	echo '</ListBucketResult>';
	exit;
}

switch ( $method ) {
	case 'PUT':
		@mkdir( dirname( $file ), 0777, true );
		file_put_contents( $file, $body );
		header( 'ETag: "' . md5( $body ) . '"' );
		http_response_code( 200 );
		exit;
	case 'GET':
	case 'HEAD':
		if ( ! is_file( $file ) ) {
			s3_error( 404, 'NoSuchKey', 'The specified key does not exist.' );
		}
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Content-Type: application/octet-stream' );
		if ( 'GET' === $method ) {
			readfile( $file );
		}
		exit;
	case 'DELETE':
		@unlink( $file );
		http_response_code( 204 );
		exit;
}
s3_error( 405, 'MethodNotAllowed', 'Unsupported method.' );
