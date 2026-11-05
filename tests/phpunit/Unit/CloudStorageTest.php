<?php
/**
 * S3-compatible storage: AWS SigV4 request signing and encryption of the
 * stored secret key.
 *
 * @package WPChrono
 */

namespace WPChrono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPChrono\Services\CloudStorageService;

final class CloudStorageTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpc_test_salt'] );
	}

	/**
	 * AWS's published example "GET Object" (S3 SigV4 documentation,
	 * "Example: GET Object"): known inputs, known signature.
	 */
	public function test_sigv4_matches_the_aws_reference_example(): void {
		$headers = CloudStorageService::sign_v4(
			'GET',
			'https://examplebucket.s3.amazonaws.com/test.txt',
			array(
				'Range'                => 'bytes=0-9',
				'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
			),
			'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
			'AKIAIOSFODNN7EXAMPLE',
			'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
			'us-east-1',
			's3',
			gmmktime( 0, 0, 0, 5, 24, 2013 )
		);
		$this->assertSame( '20130524T000000Z', $headers['x-amz-date'] );
		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
			$headers['Authorization']
		);
		$this->assertSame( 'bytes=0-9', $headers['Range'], 'caller headers are passed through' );
	}

	private function signature( string $url, array $headers = array() ): string {
		$out = CloudStorageService::sign_v4( 'GET', $url, $headers, 'UNSIGNED-PAYLOAD', 'AK', 'SK', 'auto', 's3', 1700000000 );
		return substr( $out['Authorization'], strrpos( $out['Authorization'], '=' ) + 1 );
	}

	public function test_header_names_are_case_insensitive_and_whitespace_is_folded(): void {
		$this->assertSame(
			$this->signature( 'https://b.example.com/k', array( 'x-amz-meta-a' => 'one two' ) ),
			$this->signature( 'https://b.example.com/k', array( ' X-Amz-Meta-A ' => "  one \t two " ) )
		);
	}

	public function test_query_parameter_order_does_not_matter(): void {
		$this->assertSame(
			$this->signature( 'https://b.example.com/?list-type=2&prefix=wp%2F' ),
			$this->signature( 'https://b.example.com/?prefix=wp%2F&list-type=2' )
		);
	}

	public function test_path_segments_are_encoded_exactly_once(): void {
		// A key with a space signs the same whether or not the caller pre-encoded it.
		$this->assertSame(
			$this->signature( 'https://b.example.com/site/snap%201.sql.gz' ),
			$this->signature( 'https://b.example.com/site/snap 1.sql.gz' )
		);
		$this->assertNotSame(
			$this->signature( 'https://b.example.com/site/a' ),
			$this->signature( 'https://b.example.com/site/b' )
		);
	}

	public function test_the_port_is_part_of_the_signed_host(): void {
		$this->assertNotSame(
			$this->signature( 'http://127.0.0.1:9000/b/k' ),
			$this->signature( 'http://127.0.0.1:9001/b/k' )
		);
	}

	// ------------------------------------------------------------------
	// Secret at rest
	// ------------------------------------------------------------------

	public function test_secret_round_trips(): void {
		foreach ( array( '', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', str_repeat( "\0\xff", 100 ) ) as $secret ) {
			$stored = CloudStorageService::encrypt( $secret );
			$this->assertStringStartsWith( 'sb1:', $stored );
			$this->assertSame( $secret, CloudStorageService::decrypt( $stored ) );
		}
	}

	public function test_ciphertext_is_randomised_and_never_contains_the_secret(): void {
		$a = CloudStorageService::encrypt( 'super-secret-key' );
		$b = CloudStorageService::encrypt( 'super-secret-key' );
		$this->assertNotSame( $a, $b );
		$this->assertStringNotContainsString( 'super-secret-key', $a . base64_decode( substr( $a, 4 ) ) );
	}

	public function test_tampered_or_foreign_ciphertext_decrypts_to_nothing(): void {
		$stored = CloudStorageService::encrypt( 'super-secret-key' );
		$raw    = base64_decode( substr( $stored, 4 ) );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
		$this->assertSame( '', CloudStorageService::decrypt( 'sb1:' . base64_encode( $raw ) ) );
		$this->assertSame( '', CloudStorageService::decrypt( 'sb1:not-base64!' ) );
		$this->assertSame( '', CloudStorageService::decrypt( 'sb1:' . base64_encode( 'short' ) ) );
		$this->assertSame( '', CloudStorageService::decrypt( 'plain-text-secret' ) );
		$this->assertSame( '', CloudStorageService::decrypt( '' ) );
	}

	public function test_changed_salts_make_the_stored_secret_unreadable(): void {
		$stored                   = CloudStorageService::encrypt( 'super-secret-key' );
		$GLOBALS['wpc_test_salt'] = 'rotated salts';
		$this->assertSame( '', CloudStorageService::decrypt( $stored ) );
	}

	public function test_gcm_fallback_format_still_decrypts(): void {
		$key    = hash( 'sha256', wp_salt( 'auth' ) . '|wp-chrono-cloud|v1', true );
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( 'from-a-host-without-sodium', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		$this->assertSame( 'from-a-host-without-sodium', CloudStorageService::decrypt( 'gcm1:' . base64_encode( $iv . $tag . $cipher ) ) );
	}
}
