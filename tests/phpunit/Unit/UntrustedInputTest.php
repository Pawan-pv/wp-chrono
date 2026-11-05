<?php
/**
 * Data that comes from outside this site — cloud manifests, snapshot rows,
 * import headers, settings input — is reduced to safe shapes.
 *
 * @package WPChrono
 */

namespace WPChrono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPChrono\Services\DiffService;
use WPChrono\Services\Settings;
use WPChrono\Services\Snapshot\FileArchive;
use WPChrono\Services\Snapshot\LocalImport;

final class WpcUnitGadget {
	/** @var bool */
	public static $woke = false;

	public function __wakeup() {
		self::$woke = true;
	}
}

final class UntrustedInputTest extends TestCase {

	/** @var string[] */
	private $temp = array();

	protected function tearDown(): void {
		foreach ( $this->temp as $file ) {
			@unlink( $file );
		}
		$GLOBALS['wpc_test_options'] = array();
	}

	// ------------------------------------------------------------------
	// FileArchive::localize_entry (cloud pulls)
	// ------------------------------------------------------------------

	public function test_a_plugin_folder_entry_is_rebuilt_for_this_site(): void {
		$entry = FileArchive::localize_entry(
			array(
				'type'         => 'plugin',
				'slug'         => 'akismet',
				'file'         => 'akismet/akismet.php',
				'target'       => '/somewhere/else/entirely',
				'installed_to' => '/var/www/other-site/wp-content/plugins/akismet',
				'zip'          => 'x.zip',
			)
		);
		$this->assertIsArray( $entry );
		$this->assertSame( wp_normalize_path( WP_PLUGIN_DIR . '/akismet' ), $entry['target'], 'the source path is never used' );
		$this->assertArrayNotHasKey( 'installed_to', $entry, 'installed_to deletes a folder on restore' );
		$this->assertFalse( $entry['single'] );
		$this->assertSame( 'x.zip', $entry['zip'] );
	}

	public function test_a_single_file_plugin_entry(): void {
		$entry = FileArchive::localize_entry( array( 'type' => 'plugin', 'slug' => 'hello.php', 'file' => 'hello.php' ) );
		$this->assertTrue( $entry['single'] );
		$this->assertSame( wp_normalize_path( WP_PLUGIN_DIR . '/hello.php' ), $entry['target'] );
	}

	public function test_a_theme_entry(): void {
		$entry = FileArchive::localize_entry( array( 'type' => 'theme', 'slug' => 'twentytwentyfive', 'file' => 'twentytwentyfive' ) );
		$this->assertSame( wp_normalize_path( WP_CONTENT_DIR . '/themes/twentytwentyfive' ), $entry['target'] );
	}

	/**
	 * @dataProvider hostile_entries
	 * @param array<string,mixed> $entry
	 */
	public function test_hostile_entries_are_refused( array $entry ): void {
		$this->assertNull( FileArchive::localize_entry( $entry ) );
	}

	/** @return array<string,array{array<string,mixed>}> */
	public function hostile_entries(): array {
		return array(
			'slug traversal'           => array( array( 'type' => 'plugin', 'slug' => '..', 'file' => '../wp-config.php' ) ),
			'slug with a slash'        => array( array( 'type' => 'plugin', 'slug' => 'a/../../x', 'file' => 'a/../../x/x.php' ) ),
			'hidden slug'              => array( array( 'type' => 'plugin', 'slug' => '.ssh', 'file' => '.ssh/x.php' ) ),
			'empty slug'               => array( array( 'type' => 'plugin', 'slug' => '', 'file' => 'x.php' ) ),
			'file outside its folder'  => array( array( 'type' => 'plugin', 'slug' => 'akismet', 'file' => 'other/akismet.php' ) ),
			'nested file path'         => array( array( 'type' => 'plugin', 'slug' => 'akismet', 'file' => 'akismet/../../wp-config.php' ) ),
			'plugin file not php'      => array( array( 'type' => 'plugin', 'slug' => 'akismet', 'file' => 'akismet/readme.txt' ) ),
			'single file not php'      => array( array( 'type' => 'plugin', 'slug' => 'x.phtml', 'file' => 'x.phtml' ) ),
			'theme file mismatch'      => array( array( 'type' => 'theme', 'slug' => 'twentytwentyfive', 'file' => 'other' ) ),
			'unknown core item'        => array( array( 'type' => 'core', 'slug' => 'wp-content', 'file' => 'wp-content/index.php' ) ),
			'unknown type'             => array( array( 'type' => 'mu-plugin', 'slug' => 'x', 'file' => 'x/x.php' ) ),
			'null bytes'               => array( array( 'type' => 'plugin', 'slug' => "akismet\0", 'file' => "akismet\0/akismet.php" ) ),
		);
	}

	public function test_core_entries_match_this_sites_core_folders(): void {
		$entry = FileArchive::localize_entry( array( 'type' => 'core', 'slug' => 'wp-includes', 'file' => 'wp-includes/version.php', 'target' => '/tmp/evil' ) );
		$this->assertSame( untrailingslashit( wp_normalize_path( ABSPATH ) ) . '/wp-includes', $entry['target'] );
	}

	// ------------------------------------------------------------------
	// DiffService: no object instantiation from stored data
	// ------------------------------------------------------------------

	public function test_serialized_objects_are_never_instantiated(): void {
		$method = new \ReflectionMethod( DiffService::class, 'plain_unserialize' );
		$method->setAccessible( true );
		WpcUnitGadget::$woke = false;

		$value = $method->invoke( null, serialize( array( 'x' => new WpcUnitGadget() ) ) );
		$this->assertFalse( WpcUnitGadget::$woke, '__wakeup must never run' );
		$this->assertInstanceOf( \__PHP_Incomplete_Class::class, $value['x'] );

		$this->assertSame( array( 'a' => 1 ), $method->invoke( null, serialize( array( 'a' => 1 ) ) ) );
		$this->assertSame( 'plain text', $method->invoke( null, 'plain text' ) );
	}

	// ------------------------------------------------------------------
	// LocalImport::read_header
	// ------------------------------------------------------------------

	private function gz( string $content ): string {
		$file         = (string) tempnam( sys_get_temp_dir(), 'wpc-gz' );
		$this->temp[] = $file;
		file_put_contents( $file, gzencode( $content ) );
		return $file;
	}

	public function test_an_export_header_is_parsed(): void {
		$file = $this->gz( "-- WP Chrono export of snapshot #8 (Before (risky) update)\n-- Created 2026-10-30T16:00:53Z, exported 2026-10-30T16:00:54+00:00\n-- Site: http://localhost:8088\nSET NAMES utf8mb4;\n" );
		$this->assertSame(
			array( 'id' => 8, 'label' => 'Before (risky) update', 'created' => '2026-10-30T16:00:53Z', 'site' => 'http://localhost:8088' ),
			LocalImport::read_header( $file )
		);
	}

	public function test_a_dump_from_another_tool_is_refused(): void {
		$result = LocalImport::read_header( $this->gz( "-- MySQL dump 10.13\nCREATE TABLE x (a int);\n" ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_a_file_that_is_not_gzip_is_refused(): void {
		$file         = (string) tempnam( sys_get_temp_dir(), 'wpc-gz' );
		$this->temp[] = $file;
		file_put_contents( $file, "-- WP Chrono export of snapshot #1 (x)\n" );
		// gzopen() reads plain files transparently; the header is what counts.
		$this->assertIsArray( LocalImport::read_header( $file ) );
		$this->assertInstanceOf( \WP_Error::class, @LocalImport::read_header( $file . '.missing' ) );
	}

	// ------------------------------------------------------------------
	// Settings
	// ------------------------------------------------------------------

	public function test_safe_updates_urls_are_limited_to_this_site(): void {
		$this->assertSame(
			array( 'https://example.test/shop/', 'https://example.test/checkout/' ),
			Settings::clean_urls( "https://example.test/shop/\r\nhttps://evil.test/\njavascript:alert(1)\nhttps://example.test/shop/\n\n  https://example.test/checkout/  \nhttps://EXAMPLE.test.evil.test/" )
		);
	}

	public function test_safe_updates_urls_are_capped(): void {
		$urls = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$urls[] = "https://example.test/p{$i}/";
		}
		$this->assertCount( Settings::MAX_URLS, Settings::clean_urls( $urls ) );
	}

	public function test_stored_settings_are_clamped(): void {
		$GLOBALS['wpc_test_options'][ Settings::OPTION ] = array(
			'auto_retention'  => 9999,
			'schedule'        => 'hourly',
			'schedule_hour'   => 27,
			'schedule_keep'   => -3,
			'storage_warn_mb' => 0,
			'safe_updates'    => '0',
		);
		$s = Settings::get();
		$this->assertSame( 50, $s['auto_retention'] );
		$this->assertSame( 'off', $s['schedule'] );
		$this->assertSame( 23, $s['schedule_hour'] );
		$this->assertSame( 1, $s['schedule_keep'] );
		$this->assertSame( 1, $s['storage_warn_mb'] );
		$this->assertFalse( $s['safe_updates'] );
		$this->assertTrue( $s['auto_snapshot'], 'missing keys fall back to defaults' );
	}

	public function test_corrupt_stored_settings_fall_back_to_defaults(): void {
		$GLOBALS['wpc_test_options'][ Settings::OPTION ] = 'garbage';
		$this->assertSame( Settings::DEFAULTS['schedule'], Settings::get()['schedule'] );
		$this->assertSame( Settings::DEFAULTS['auto_retention'], Settings::get()['auto_retention'] );
	}
}
