<?php
/**
 * Phase 6: licensing. Ed25519 offline keys, tier gating ("restore is always
 * free"), optional signed license server (mock on 127.0.0.1:9100).
 *
 * Installs a test mu-plugin that trusts a throwaway TEST public key, and
 * leaves a test Pro license active so later suites can use Pro features.
 *
 *   bash tests/license-checks.sh
 */

use WPChrono\API\RestController;
use WPChrono\Services\LicenseService;
use WPChrono\Services\SnapshotService;

require '/var/www/html/wp-content/plugins/wp-chrono/scripts/license-sign.php';

$GLOBALS['wpc_fails']  = 0;
$GLOBALS['wpc_passes'] = 0;
function wpc_check( string $label, bool $ok, string $extra = '' ): void {
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) . "\n";
	$ok ? $GLOBALS['wpc_passes']++ : $GLOBALS['wpc_fails']++;
}
function wpc_rest( string $method, string $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, '/wp-chrono/v1/' . ltrim( $route, '/' ) );
	$request->set_header( RestController::NONCE_HEADER, wp_create_nonce( RestController::NONCE_ACTION ) );
	foreach ( $params as $k => $v ) {
		$request->set_param( $k, $v );
	}
	return rest_do_request( $request );
}
function wpc_code( $response ): string {
	$data = $response->get_data();
	return is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
}

// ---------------------------------------------------------------------------
echo "== Test keypair (the production secret never leaves the vendor machine) ==\n";
$pair   = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey( $pair );
$public = base64_encode( sodium_crypto_sign_publickey( $pair ) );
file_put_contents( '/tmp/wpc-test-license.secret', base64_encode( $secret ) );
wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( WPMU_PLUGIN_DIR . '/wpc-test-license.php', "<?php\n// WP Chrono test suite only: trust the throwaway TEST license key.\nadd_filter( 'wp_chrono_license_public_keys', function ( \$keys ) { \$keys[] = '{$public}'; return \$keys; } );\n" );
add_filter( 'wp_chrono_license_public_keys', static function ( $keys ) use ( $public ) {
	$keys[] = $public;
	return $keys;
} );
$mint = static function ( array $over = array() ) use ( $secret ) {
	return wpc_license_mint( $secret, array_merge( array( 'lid' => 'test-' . bin2hex( random_bytes( 4 ) ), 'plan' => 'pro', 'sites' => 1, 'source' => 'appsumo', 'issued' => gmdate( 'Y-m-d' ), 'expires' => null ), $over ) );
};
$prod = base64_decode( (string) LicenseService::PUBLIC_KEYS['2026-10'], true );
wpc_check( 'production Ed25519 public key embedded (32 bytes, not a placeholder)', false !== $prod && 32 === strlen( $prod ) );
wpc_check( 'test public key trusted only via the test mu-plugin filter', in_array( $public, LicenseService::public_keys(), true ) && ! in_array( $public, LicenseService::PUBLIC_KEYS, true ) );

// ---------------------------------------------------------------------------
echo "\n== Free tier: restore is always free ==\n";
LicenseService::deactivate();
update_option( LicenseService::OPTION, array() );
$s = LicenseService::status();
wpc_check( 'no key: plan free, Blueprints + Cloud locked', 'free' === $s['plan'] && ! $s['features']['blueprints'] && ! $s['features']['cloud'] );
$r = wpc_rest( 'POST', 'blueprints', array( 'name' => 'x', 'bundle' => false ) );
wpc_check( 'blueprint capture → HTTP 402 wp_chrono_pro_required', 402 === $r->get_status() && 'wp_chrono_pro_required' === wpc_code( $r ) );
$r = wpc_rest( 'POST', 'cloud/test' );
wpc_check( 'cloud test → HTTP 402', 402 === $r->get_status() );
$r = wpc_rest( 'POST', 'cloud/push/1' );
wpc_check( 'cloud push → HTTP 402', 402 === $r->get_status() );
wpc_check( 'listing blueprints stays free', 200 === wpc_rest( 'GET', 'blueprints' )->get_status() );
$service = new SnapshotService();
$snap    = $service->create_and_build( 'License test', 'manual' );
$r       = wpc_rest( 'POST', "snapshots/{$snap['id']}/restore", array( 'confirm' => 'RESTORE' ) );
wpc_check( 'snapshot restore works without a license', 200 === $r->get_status() );
wpc_rest( 'POST', 'snapshots/restore/cancel' );
$r = wpc_rest( 'POST', 'cloud/pull', array( 'key' => 'nope/' ) );
wpc_check( 'cloud pull (a restore) is never paywalled', 402 !== $r->get_status(), 'HTTP ' . $r->get_status() );
$r = wpc_rest( 'POST', 'snapshots/import/chunk' );
wpc_check( 'local .sql.gz import is never paywalled', 402 !== $r->get_status(), 'HTTP ' . $r->get_status() );
wpc_check( 'recovery script generation is never paywalled', 201 === wpc_rest( 'POST', 'recovery' )->get_status() );
wpc_rest( 'DELETE', 'recovery' );
$service->delete( $snap['id'] );

// ---------------------------------------------------------------------------
echo "\n== Offline Ed25519 verification ==\n";
$key = $mint( array( 'lid' => 'appsumo-ltd-001' ) );
$p   = LicenseService::parse( $key );
wpc_check( 'valid key verifies offline', is_array( $p ) && 'pro' === $p['plan'] && 'appsumo' === $p['source'], is_array( $p ) ? $p['lid'] : $p->get_error_message() );
list( $h, $body, $sig ) = explode( '.', $key );
$payload = json_decode( base64_decode( strtr( $body, '-_', '+/' ) ), true );
$payload['plan'] = 'agency';
$forged_body     = rtrim( strtr( base64_encode( json_encode( $payload ) ), '+/', '-_' ), '=' );
$r = LicenseService::parse( "{$h}.{$forged_body}.{$sig}" );
wpc_check( 'tampered payload (pro → agency) rejected', is_wp_error( $r ) && 'wp_chrono_license_signature' === $r->get_error_code() );
$other = wpc_license_mint( sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ), $payload );
$r     = LicenseService::parse( $other );
wpc_check( 'key signed by an unknown key rejected', is_wp_error( $r ) && 'wp_chrono_license_signature' === $r->get_error_code() );
$r = LicenseService::parse( $mint( array( 'expires' => gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ) ) ) );
wpc_check( 'expired key rejected', is_wp_error( $r ) && 'wp_chrono_license_expired' === $r->get_error_code() );
wpc_check( 'future expiry accepted', is_array( LicenseService::parse( $mint( array( 'expires' => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ) ) ) ) ) );
wpc_check( 'garbage rejected with a clear message', is_wp_error( LicenseService::parse( 'ABC-123' ) ) && false !== strpos( LicenseService::parse( 'ABC-123' )->get_error_message(), 'WPC1' ) );

$r = wpc_rest( 'POST', 'license', array( 'key' => $key ) );
wpc_check( 'POST /license activates: Blueprints + Cloud unlocked', 200 === $r->get_status() && $r->get_data()['active'] && $r->get_data()['features']['blueprints'] && $r->get_data()['features']['cloud'] );
wpc_check( 'GET /license masks the key', false === strpos( (string) wp_json_encode( wpc_rest( 'GET', 'license' )->get_data() ), $key ) );
$r = wpc_rest( 'POST', 'blueprints', array( 'name' => 'Licensed', 'bundle' => false ) );
wpc_check( 'blueprint capture now allowed', 201 === $r->get_status() );
( new WPChrono\Services\BlueprintService() )->delete( (string) ( $r->get_data()['id'] ?? '' ) );
wpc_check( 'invalid key via REST → 400, saved key unchanged', 400 === wpc_rest( 'POST', 'license', array( 'key' => "{$h}.{$forged_body}.{$sig}" ) )->get_status() && LicenseService::status()['active'] );

// ---------------------------------------------------------------------------
echo "\n== Optional license server (signed verdicts) ==\n";
$server = 'http://localhost:9100/'; // WP_HTTP_BLOCK_EXTERNAL only exempts "localhost" and the site's own host.
$r = LicenseService::activate( $mint( array( 'lid' => 'srv-ok' ) ), $server );
wpc_check( 'activation confirmed by the server (signed "active")', ! is_wp_error( $r ) && $r['active'] && true === ( $r['remote']['reachable'] ?? null ) && 'active' === ( $r['remote']['status'] ?? '' ) );
$r = LicenseService::activate( $mint( array( 'lid' => 'srv-revoked' ) ), $server );
wpc_check( 'server says revoked → activation refused', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'rejected' ) );
$r = LicenseService::activate( $mint( array( 'lid' => 'srv-forged' ) ), $server );
wpc_check( 'unsigned / forged server answer ignored, not trusted', is_wp_error( $r ) && 'wp_chrono_license_server_invalid' === $r->get_error_code() );
LicenseService::activate( $mint( array( 'lid' => 'srv-revokelater' ) ), $server );
wpc_check( 'key active after activation', LicenseService::status()['active'] );
LicenseService::maybe_check( true );
$s = LicenseService::status();
wpc_check( 'daily check: signed revocation turns Pro off', ! $s['active'] && false !== strpos( (string) $s['error'], 'revoked' ) && 402 === wpc_rest( 'POST', 'cloud/test' )->get_status() );
$r = LicenseService::activate( $mint( array( 'lid' => 'srv-down' ) ), 'http://localhost:9199/' );
wpc_check( 'server unreachable → offline key stays valid', ! is_wp_error( $r ) && $r['active'] && false === ( $r['remote']['reachable'] ?? null ) );
update_option( LicenseService::OPTION, array() );

// ---------------------------------------------------------------------------
echo "\n== Constant beats option ==\n";
LicenseService::activate( $key );
define( 'WP_CHRONO_LICENSE_KEY', $mint( array( 'lid' => 'agency-const', 'plan' => 'agency', 'sites' => 100 ) ) );
$s = LicenseService::status();
wpc_check( 'WP_CHRONO_LICENSE_KEY in wp-config.php wins over the saved key', 'constant' === $s['from'] && 'agency' === $s['plan'] && 'agency-const' === $s['license']['lid'] );
$s = LicenseService::deactivate();
wpc_check( 'deactivate leaves a constant-defined key active (with a warning in the CLI)', $s['active'] && 'constant' === $s['from'] );

// Leave a Pro test license saved for the rest of the suite (the constant only lived in this process).
LicenseService::activate( $mint( array( 'lid' => 'suite-pro' ) ) );
file_put_contents( '/tmp/wpc-test-license.key', $mint( array( 'lid' => 'cli-pro' ) ) );
echo "\n" . ( $GLOBALS['wpc_fails'] ? "{$GLOBALS['wpc_fails']} check(s) FAILED ({$GLOBALS['wpc_passes']} passed)" : "All checks passed ({$GLOBALS['wpc_passes']} passed)" ) . "\n";
