<?php
/**
 * Safe Updates' judgement: which probe results count as "the update broke
 * this page", and which updated item a fatal error points to. A wrong call
 * either leaves a broken site up or undoes a good update.
 *
 * @package WPChrono
 */

namespace WPChrono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPChrono\Services\SafeUpdates;

final class SafeUpdatesDecisionTest extends TestCase {

	/** @return mixed */
	private static function call( string $method, ...$args ) {
		$m = new \ReflectionMethod( SafeUpdates::class, $method );
		$m->setAccessible( true );
		return $m->invoke( null, ...$args );
	}

	private static function ok( array $extra = array() ): array {
		return array_merge( array( 'ok' => true, 'status' => 200, 'reason' => 'HTTP 200', 'answer' => 'wordpress' ), $extra );
	}

	private static function broken( string $reason = 'PHP fatal error' ): array {
		return array( 'ok' => false, 'status' => 500, 'reason' => $reason );
	}

	private static function unknown( string $reason = 'HTTP 503 (maintenance or overload)' ): array {
		return array( 'ok' => null, 'status' => 503, 'reason' => $reason );
	}

	// ------------------------------------------------------------------
	// regressions()
	// ------------------------------------------------------------------

	public function test_nothing_changed_means_no_regression(): void {
		$probe = array( 'front' => self::ok(), 'admin' => self::ok() );
		$this->assertSame( array(), self::call( 'regressions', $probe, $probe ) );
	}

	public function test_a_page_that_worked_and_now_fails_is_a_regression(): void {
		$out = self::call( 'regressions', array( 'front' => self::ok(), 'admin' => self::ok() ), array( 'front' => self::broken(), 'admin' => self::ok() ) );
		$this->assertSame( array( 'front' => 'the home page: PHP fatal error' ), $out );
	}

	public function test_pages_that_were_already_broken_are_never_judged(): void {
		$out = self::call( 'regressions', array( 'front' => self::broken(), 'admin' => self::unknown() ), array( 'front' => self::broken(), 'admin' => self::broken() ) );
		$this->assertSame( array(), $out );
	}

	public function test_an_unknown_answer_after_the_update_is_not_a_regression(): void {
		// 503 / no answer / cached page: unverified, never a reason to undo.
		$out = self::call( 'regressions', array( 'front' => self::ok(), 'admin' => self::ok() ), array( 'front' => self::unknown(), 'admin' => array( 'ok' => null, 'status' => 0, 'reason' => 'No answer' ) ) );
		$this->assertSame( array(), $out );
	}

	public function test_missing_results_are_not_regressions(): void {
		$this->assertSame( array(), self::call( 'regressions', array( 'front' => self::ok() ), array() ) );
		$this->assertSame( array(), self::call( 'regressions', array(), array( 'front' => self::broken() ) ) );
	}

	public function test_custom_pages_use_their_label(): void {
		$before = array( 'front' => self::ok(), 'pages' => array( 'shop' => self::ok( array( 'label' => 'Shop' ) ) ) );
		$after  = array( 'front' => self::ok(), 'pages' => array( 'shop' => self::broken( 'HTTP 404 (page gone)' ) + array( 'label' => 'Shop' ) ) );
		$this->assertSame( array( 'shop' => 'Shop: HTTP 404 (page gone)' ), self::call( 'regressions', $before, $after ) );
	}

	public function test_content_markers_that_disappeared_on_a_2xx_page_are_a_regression(): void {
		$before = array( 'pages' => array( 'checkout' => self::ok( array( 'label' => 'Checkout', 'markers' => array( 'woocommerce-checkout', 'place_order' ) ) ) ) );
		$after  = array( 'pages' => array( 'checkout' => self::ok( array( 'label' => 'Checkout', 'markers' => array( 'woocommerce-checkout' ) ) ) ) );
		$this->assertSame(
			array( 'checkout' => 'Checkout: HTTP 200 but the page content is missing (place_order)' ),
			self::call( 'regressions', $before, $after )
		);
	}

	public function test_markers_absent_from_the_baseline_are_not_required(): void {
		$before = array( 'pages' => array( 'cart' => self::ok( array( 'markers' => array( 'cart-empty' ) ) ) ) );
		$after  = array( 'pages' => array( 'cart' => self::ok( array( 'markers' => array( 'cart-empty', 'woocommerce-cart-form' ) ) ) ) );
		$this->assertSame( array(), self::call( 'regressions', $before, $after ) );
	}

	public function test_missing_markers_on_a_foreign_or_non_2xx_answer_are_not_judged(): void {
		$before = array( 'pages' => array( 'cart' => self::ok( array( 'markers' => array( 'woocommerce-cart-form' ) ) ) ) );
		foreach ( array( array( 'answer' => 'foreign' ), array( 'status' => 302 ) ) as $change ) {
			$after = array( 'pages' => array( 'cart' => self::ok( array_merge( array( 'markers' => array() ), $change ) ) ) );
			$this->assertSame( array(), self::call( 'regressions', $before, $after ), wp_json_encode( $change ) );
		}
	}

	public function test_skipped_lists_pages_the_baseline_could_not_confirm(): void {
		$baseline = array( 'front' => self::unknown( 'WooCommerce "coming soon" mode' ), 'admin' => self::ok(), 'pages' => array( 'shop' => self::unknown( 'cached' ) + array( 'label' => 'Shop' ) ) );
		$this->assertSame(
			array( 'front' => 'the home page: WooCommerce "coming soon" mode', 'shop' => 'Shop: cached' ),
			self::call( 'skipped', $baseline )
		);
	}

	public function test_count_checked_counts_only_confirmed_pages(): void {
		$this->assertSame( 2, self::call( 'count_checked', array( 'front' => self::ok(), 'admin' => self::unknown(), 'pages' => array( 'a' => self::ok(), 'b' => self::broken() ) ) ) );
		$this->assertSame( 0, self::call( 'count_checked', array() ) );
	}

	public function test_describe_failures(): void {
		$this->assertNull( self::call( 'describe_failures', array() ) );
		$this->assertSame( 'a: x', self::call( 'describe_failures', array( 'front' => 'a: x' ) ) );
		$this->assertSame( 'a: x; and 2 more page(s)', self::call( 'describe_failures', array( 'front' => 'a: x', 'admin' => 'b', 'shop' => 'c' ) ) );
	}

	// ------------------------------------------------------------------
	// culprit(): the scrape result's file is relative to ABSPATH
	// ------------------------------------------------------------------

	/** @return array<int,array<string,mixed>> */
	private static function items(): array {
		return array(
			array( 'type' => 'plugin', 'slug' => 'woocommerce', 'file' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce', 'new_version' => '9.9.7' ),
			array( 'type' => 'plugin', 'slug' => 'woo', 'file' => 'woo/woo.php', 'name' => 'Woo', 'new_version' => '2.0' ),
			array( 'type' => 'plugin', 'slug' => 'hello.php', 'file' => 'hello.php', 'name' => 'Hello', 'new_version' => '1.7' ),
			array( 'type' => 'theme', 'slug' => 'astra', 'file' => 'astra', 'name' => 'Astra', 'new_version' => '4.0' ),
			array( 'type' => 'core', 'slug' => 'wordpress', 'file' => 'wordpress', 'name' => 'WordPress', 'new_version' => '7.1.3' ),
		);
	}

	/**
	 * @dataProvider culprits
	 */
	public function test_culprit( string $file, ?string $expected_slug ): void {
		$culprit = self::call( 'culprit', array( 'file' => $file, 'message' => 'x', 'line' => 1 ), self::items() );
		$this->assertSame( $expected_slug, $culprit['slug'] ?? null );
	}

	/** @return array<string,array{string,?string}> */
	public function culprits(): array {
		return array(
			'plugin folder'                  => array( 'wp-content/plugins/woocommerce/includes/class-wc.php', 'woocommerce' ),
			'prefix of another slug'         => array( 'wp-content/plugins/woo/woo.php', 'woo' ),
			'single-file plugin'             => array( 'wp-content/plugins/hello.php', 'hello.php' ),
			'theme'                          => array( 'wp-content/themes/astra/functions.php', 'astra' ),
			'core (wp-includes)'             => array( 'wp-includes/functions.php', 'wordpress' ),
			'core (root file)'               => array( 'wp-settings.php', 'wordpress' ),
			'Windows separators'             => array( 'wp-content\\plugins\\woocommerce\\woocommerce.php', 'woocommerce' ),
			'a plugin that was not updated'  => array( 'wp-content/plugins/akismet/akismet.php', null ),
			'a theme named like a plugin'    => array( 'wp-content/themes/woocommerce/functions.php', null ),
		);
	}

	public function test_without_a_file_only_a_single_item_is_blamed(): void {
		$this->assertNull( self::call( 'culprit', null, self::items() ) );
		$one = array( self::items()[0] );
		$this->assertSame( $one[0], self::call( 'culprit', null, $one ) );
	}

	public function test_only_fatal_error_types_count(): void {
		foreach ( array( E_ERROR, E_PARSE, E_COMPILE_ERROR ) as $type ) {
			$this->assertContains( $type, SafeUpdates::FATAL_TYPES );
		}
		foreach ( array( E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_WARNING ) as $type ) {
			$this->assertNotContains( $type, SafeUpdates::FATAL_TYPES );
		}
	}
}
