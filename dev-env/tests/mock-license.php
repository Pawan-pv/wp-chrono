<?php
/**
 * Offline mock license server (PHP built-in server router):
 *
 *   php -S 127.0.0.1:9100 /tests/mock-license.php
 *
 * Implements the contract documented in LicenseService, signing verdicts with
 * the TEST secret key at /tmp/wpc-test-license.secret. Behaviour by license ID:
 *   lid contains "revoked"      → status revoked (always)
 *   lid contains "revokelater"  → active on activate, revoked on check
 *   lid contains "forged"       → signed with a random key (must be ignored)
 *   anything else               → active
 */

// Same signing as scripts/license-sign.php (which refuses to load outside the CLI).
function wpc_license_server_sign( string $secret, string $lid, string $site, string $status, int $ts ): string {
	return rtrim( strtr( base64_encode( sodium_crypto_sign_detached( sprintf( 'WPCR1.%s.%s.%s.%d', $lid, $site, $status, $ts ), $secret ) ), '+/', '-_' ), '=' );
}

$req     = json_decode( (string) file_get_contents( 'php://input' ), true );
$parts   = explode( '.', (string) ( $req['key'] ?? '' ) );
$payload = json_decode( (string) base64_decode( strtr( $parts[1] ?? '', '-_', '+/' ) ), true );
$lid     = (string) ( $payload['lid'] ?? '' );
$action  = (string) ( $req['action'] ?? '' );
$site    = (string) ( $req['site'] ?? '' );
file_put_contents( '/tmp/wpc-mock-license.log', gmdate( 'H:i:s' ) . " {$action} {$lid}\n", FILE_APPEND );

$status = 'active';
if ( false !== strpos( $lid, 'revokelater' ) ) {
	$status = 'check' === $action ? 'revoked' : 'active';
} elseif ( false !== strpos( $lid, 'revoked' ) ) {
	$status = 'revoked';
}
$secret = base64_decode( trim( (string) file_get_contents( '/tmp/wpc-test-license.secret' ) ) );
if ( false !== strpos( $lid, 'forged' ) ) {
	$secret = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
}
$ts = time();
header( 'Content-Type: application/json' );
echo json_encode( array( 'lid' => $lid, 'site' => $site, 'status' => $status, 'ts' => $ts, 'sig' => wpc_license_server_sign( $secret, $lid, $site, $status, $ts ) ) );
