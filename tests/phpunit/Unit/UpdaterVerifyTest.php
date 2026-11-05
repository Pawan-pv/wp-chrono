<?php
/**
 * Self-update packages are installed only when both the SHA-256 and the
 * offline release signature (Ed25519 over "WPCU1.wp-chrono.<version>.<sha256>") match.
 *
 * @package WPChrono
 */

namespace WPChrono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPChrono\Services\Updater;

final class UpdaterVerifyTest extends TestCase {

	/** @var string */
	private $secret;
	/** @var string */
	private $file;
	/** @var string */
	private $sha;

	protected function setUp(): void {
		$pair         = sodium_crypto_sign_keypair();
		$this->secret = sodium_crypto_sign_secretkey( $pair );
		$public       = base64_encode( sodium_crypto_sign_publickey( $pair ) );
		remove_all_filters( 'wp_chrono_release_public_keys' );
		add_filter(
			'wp_chrono_release_public_keys',
			static function () use ( $public ) {
				return array( $public );
			}
		);
		$this->file = (string) tempnam( sys_get_temp_dir(), 'wpc-zip' );
		file_put_contents( $this->file, 'PK fake release ' . random_bytes( 16 ) );
		$this->sha = (string) hash_file( 'sha256', $this->file );
	}

	protected function tearDown(): void {
		remove_all_filters( 'wp_chrono_release_public_keys' );
		@unlink( $this->file );
	}

	private function sign( string $version, ?string $sha = null, string $slug = 'wp-chrono', ?string $secret = null ): string {
		$sig = sodium_crypto_sign_detached( "WPCU1.{$slug}.{$version}." . ( $sha ?? $this->sha ), $secret ?? $this->secret );
		return rtrim( strtr( base64_encode( $sig ), '+/', '-_' ), '=' );
	}

	public function test_a_signed_release_verifies(): void {
		$this->assertNull( Updater::verify( $this->file, '1.2.3', $this->sha, $this->sign( '1.2.3' ) ) );
	}

	public function test_standard_base64_signatures_are_accepted_too(): void {
		$sig = base64_encode( sodium_crypto_sign_detached( "WPCU1.wp-chrono.1.2.3.{$this->sha}", $this->secret ) );
		$this->assertNull( Updater::verify( $this->file, '1.2.3', $this->sha, $sig ) );
	}

	public function test_a_corrupt_download_is_refused(): void {
		file_put_contents( $this->file, 'tampered', FILE_APPEND );
		$this->assertErrorCode( 'wp_chrono_update_hash', Updater::verify( $this->file, '1.2.3', $this->sha, $this->sign( '1.2.3' ) ) );
	}

	public function test_a_missing_file_is_refused(): void {
		$this->assertErrorCode( 'wp_chrono_update_hash', @Updater::verify( $this->file . '.missing', '1.2.3', $this->sha, $this->sign( '1.2.3' ) ) );
	}

	public function test_a_signature_for_another_version_is_refused(): void {
		// The API cannot relabel a signed old (vulnerable) build as a new version.
		$this->assertErrorCode( 'wp_chrono_update_signature', Updater::verify( $this->file, '9.9.9', $this->sha, $this->sign( '1.2.3' ) ) );
	}

	public function test_a_signature_for_another_plugin_is_refused(): void {
		$this->assertErrorCode( 'wp_chrono_update_signature', Updater::verify( $this->file, '1.2.3', $this->sha, $this->sign( '1.2.3', null, 'other-plugin' ) ) );
	}

	public function test_a_signature_by_another_key_is_refused(): void {
		$other = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		$this->assertErrorCode( 'wp_chrono_update_signature', Updater::verify( $this->file, '1.2.3', $this->sha, $this->sign( '1.2.3', null, 'wp-chrono', $other ) ) );
	}

	/**
	 * @dataProvider broken_signatures
	 */
	public function test_broken_signatures_are_refused( string $signature ): void {
		$this->assertErrorCode( 'wp_chrono_update_signature', Updater::verify( $this->file, '1.2.3', $this->sha, $signature ) );
	}

	/** @return array<string,array{string}> */
	public function broken_signatures(): array {
		return array(
			'empty'        => array( '' ),
			'not base64'   => array( '***' ),
			'63 bytes'     => array( base64_encode( str_repeat( "\0", 63 ) ) ),
			'zero 64 bytes' => array( base64_encode( str_repeat( "\0", 64 ) ) ),
		);
	}

	public function test_no_trusted_key_means_nothing_verifies(): void {
		remove_all_filters( 'wp_chrono_release_public_keys' );
		add_filter(
			'wp_chrono_release_public_keys',
			static function () {
				return array( 'garbage', base64_encode( 'short' ) );
			}
		);
		$this->assertErrorCode( 'wp_chrono_update_signature', Updater::verify( $this->file, '1.2.3', $this->sha, $this->sign( '1.2.3' ) ) );
	}

	public function test_embedded_release_keys_are_valid_and_distinct_from_license_keys(): void {
		$this->assertNotEmpty( Updater::RELEASE_KEYS );
		foreach ( Updater::RELEASE_KEYS as $b64 ) {
			$raw = base64_decode( $b64, true );
			$this->assertIsString( $raw );
			$this->assertSame( SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen( $raw ) );
			// A leaked license-signing key must not be able to sign code.
			$this->assertNotContains( $b64, \WPChrono\Services\LicenseService::PUBLIC_KEYS );
		}
	}

	/** @param mixed $result */
	private function assertErrorCode( string $code, $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}
}
