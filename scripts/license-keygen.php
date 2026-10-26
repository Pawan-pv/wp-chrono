<?php
/**
 * Vendor tool (never shipped): generates the Ed25519 license signing keypair.
 *
 *   php scripts/license-keygen.php [secret-file]
 *
 * Writes the SECRET key (base64) to secret-file (default
 * ~/.wp-chrono-keys/license-ed25519.secret, mode 0600) and prints the PUBLIC
 * key to embed in LicenseService::PUBLIC_KEYS. Refuses to overwrite an
 * existing secret: losing it means every issued key stops verifying once you
 * rotate, so back it up somewhere safe and offline.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

$home = getenv( 'HOME' ) ?: getenv( 'USERPROFILE' );
$file = $argv[1] ?? ( getenv( 'WPC_LICENSE_SECRET_FILE' ) ?: $home . '/.wp-chrono-keys/license-ed25519.secret' );
if ( file_exists( $file ) ) {
	fwrite( STDERR, "Refusing to overwrite existing secret key: {$file}\n" );
	exit( 1 );
}
if ( ! is_dir( dirname( $file ) ) && ! mkdir( dirname( $file ), 0700, true ) ) {
	fwrite( STDERR, "Cannot create " . dirname( $file ) . "\n" );
	exit( 1 );
}
$pair   = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey( $pair );
$public = sodium_crypto_sign_publickey( $pair );
file_put_contents( $file, base64_encode( $secret ) . "\n" );
@chmod( $file, 0600 );

echo "Secret key written to: {$file}\n";
echo "Public key (embed in LicenseService::PUBLIC_KEYS):\n" . base64_encode( $public ) . "\n";
