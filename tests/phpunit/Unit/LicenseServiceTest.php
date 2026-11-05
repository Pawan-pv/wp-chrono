<?php
/**
 * Offline license keys: WPC1.<base64url payload>.<base64url Ed25519 signature>.
 *
 * @package WPChrono
 */

namespace WPChrono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPChrono\Services\LicenseService;

final class LicenseServiceTest extends TestCase {

	/** @var string */
	private $secret;

	protected function setUp(): void {
		$pair         = sodium_crypto_sign_keypair();
		$this->secret = sodium_crypto_sign_secretkey( $pair );
		$public       = base64_encode( sodium_crypto_sign_publickey( $pair ) );
		remove_all_filters( 'wp_chrono_license_public_keys' );
		add_filter(
			'wp_chrono_license_public_keys',
			static function ( $keys ) use ( $public ) {
				return array( $public );
			}
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'wp_chrono_license_public_keys' );
	}

	private static function b64u( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/** @param array<string,mixed> $payload */
	private function key( array $payload, ?string $secret = null ): string {
		$body = self::b64u( (string) json_encode( $payload ) );
		return 'WPC1.' . $body . '.' . self::b64u( sodium_crypto_sign_detached( 'WPC1.' . $body, $secret ?? $this->secret ) );
	}

	/** @return array<string,mixed> */
	private static function payload( array $overrides = array() ): array {
		return array_merge(
			array( 'lid' => 'lic_123', 'plan' => 'pro', 'sites' => 3, 'source' => 'appsumo', 'issued' => '2026-10-26', 'expires' => null ),
			$overrides
		);
	}

	public function test_a_valid_key_returns_its_payload(): void {
		$parsed = LicenseService::parse( $this->key( self::payload() ) );
		$this->assertSame(
			array( 'lid' => 'lic_123', 'plan' => 'pro', 'sites' => 3, 'source' => 'appsumo', 'issued' => '2026-10-26', 'expires' => null, 'term' => null ),
			$parsed
		);
	}

	public function test_store_keys_carry_their_term_and_unknown_terms_are_dropped(): void {
		$this->assertSame( 'yearly', LicenseService::parse( $this->key( self::payload( array( 'source' => 'dodo', 'term' => 'yearly' ) ) ) )['term'] );
		$this->assertSame( 'lifetime', LicenseService::parse( $this->key( self::payload( array( 'term' => 'lifetime' ) ) ) )['term'] );
		$this->assertNull( LicenseService::parse( $this->key( self::payload( array( 'term' => 'forever<script>' ) ) ) )['term'] );
	}

	public function test_surrounding_whitespace_is_ignored(): void {
		$this->assertIsArray( LicenseService::parse( "  \n" . $this->key( self::payload() ) . "\n " ) );
	}

	public function test_agency_plan_is_accepted(): void {
		$this->assertSame( 'agency', LicenseService::parse( $this->key( self::payload( array( 'plan' => 'agency' ) ) ) )['plan'] );
	}

	public function test_a_key_signed_by_another_vendor_key_is_refused(): void {
		$other = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		$this->assertErrorCode( 'wp_chrono_license_signature', LicenseService::parse( $this->key( self::payload(), $other ) ) );
	}

	public function test_an_edited_payload_breaks_the_signature(): void {
		$key   = $this->key( self::payload() );
		$parts = explode( '.', $key );
		// Same signature, payload upgraded to 1000 sites.
		$parts[1] = self::b64u( (string) json_encode( self::payload( array( 'sites' => 1000 ) ) ) );
		$this->assertErrorCode( 'wp_chrono_license_signature', LicenseService::parse( implode( '.', $parts ) ) );
	}

	public function test_a_rotated_key_list_accepts_any_trusted_key(): void {
		$old_pair = sodium_crypto_sign_keypair();
		$old_pub  = base64_encode( sodium_crypto_sign_publickey( $old_pair ) );
		add_filter(
			'wp_chrono_license_public_keys',
			static function ( $keys ) use ( $old_pub ) {
				return array_merge( $keys, array( $old_pub ) );
			}
		);
		$this->assertIsArray( LicenseService::parse( $this->key( self::payload(), sodium_crypto_sign_secretkey( $old_pair ) ) ) );
	}

	public function test_placeholder_and_malformed_public_keys_are_skipped(): void {
		remove_all_filters( 'wp_chrono_license_public_keys' );
		add_filter(
			'wp_chrono_license_public_keys',
			static function () {
				return array( '__WPC_PLACEHOLDER__', 'not base64!', base64_encode( 'short' ), 42 );
			}
		);
		$this->assertSame( array( 'not base64!', base64_encode( 'short' ) ), LicenseService::public_keys() );
		$this->assertErrorCode( 'wp_chrono_license_signature', LicenseService::parse( $this->key( self::payload() ) ) );
	}

	/**
	 * @dataProvider malformed_keys
	 */
	public function test_malformed_keys_are_refused( string $key ): void {
		$this->assertErrorCode( 'wp_chrono_license_format', LicenseService::parse( $key ) );
	}

	/** @return array<string,array{string}> */
	public function malformed_keys(): array {
		return array(
			'empty'               => array( '' ),
			'wrong prefix'        => array( 'WPC2.abc.def' ),
			'two parts'           => array( 'WPC1.abc' ),
			'four parts'          => array( 'WPC1.a.b.c' ),
			'payload not base64'  => array( 'WPC1.!!!.' . self::b64u( str_repeat( 'x', 64 ) ) ),
			'signature too short' => array( 'WPC1.' . self::b64u( '{}' ) . '.' . self::b64u( str_repeat( 'x', 63 ) ) ),
		);
	}

	/**
	 * @dataProvider invalid_payloads
	 * @param array<string,mixed> $payload
	 */
	public function test_signed_but_invalid_payloads_are_refused( array $payload ): void {
		$this->assertErrorCode( 'wp_chrono_license_format', LicenseService::parse( $this->key( $payload ) ) );
	}

	/** @return array<string,array{array<string,mixed>}> */
	public function invalid_payloads(): array {
		return array(
			'no license id' => array( self::payload( array( 'lid' => '' ) ) ),
			'unknown plan'  => array( self::payload( array( 'plan' => 'enterprise' ) ) ),
			'no plan'       => array( array( 'lid' => 'x' ) ),
			'not an object' => array( array() ),
		);
	}

	public function test_an_expired_key_is_refused(): void {
		$yesterday = gmdate( 'Y-m-d', time() - 86400 );
		$this->assertErrorCode( 'wp_chrono_license_expired', LicenseService::parse( $this->key( self::payload( array( 'expires' => $yesterday ) ) ) ) );
	}

	public function test_a_key_is_valid_through_its_last_day(): void {
		$today = gmdate( 'Y-m-d' );
		$this->assertSame( $today, LicenseService::parse( $this->key( self::payload( array( 'expires' => $today ) ) ) )['expires'] );
	}

	public function test_fields_are_normalised(): void {
		$parsed = LicenseService::parse( $this->key( self::payload( array( 'lid' => '<b>lic</b> 9', 'sites' => 0, 'source' => 'Lemon Squeezy!' ) ) ) );
		$this->assertSame( 'lic 9', $parsed['lid'] );
		$this->assertSame( 1, $parsed['sites'], 'at least one site' );
		$this->assertSame( 'lemonsqueezy', $parsed['source'] );
	}

	public function test_only_blueprints_and_cloud_are_pro(): void {
		// Restoring is never paywalled.
		$this->assertSame( array( 'blueprints', 'cloud' ), LicenseService::PRO_FEATURES );
	}

	public function test_embedded_vendor_keys_are_valid_ed25519_public_keys(): void {
		$this->assertNotEmpty( LicenseService::PUBLIC_KEYS );
		foreach ( LicenseService::PUBLIC_KEYS as $b64 ) {
			$raw = base64_decode( $b64, true );
			$this->assertIsString( $raw );
			$this->assertSame( SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen( $raw ) );
		}
	}

	/** @param mixed $result */
	private function assertErrorCode( string $code, $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}
}

