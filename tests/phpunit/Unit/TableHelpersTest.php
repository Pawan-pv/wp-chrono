<?php
/**
 * Table naming, ownership and keyset paging — the helpers every snapshot,
 * reset and restore relies on to touch only this site's tables.
 *
 * @package WPChrono
 */

namespace WPChrono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPChrono\Core\Schema;
use WPChrono\Services\Database;
use WPChrono\Services\Snapshot\TableCopier;

final class TableHelpersTest extends TestCase {

	/** @var \WPC_Test_WPDB */
	private $saved;

	protected function setUp(): void {
		$this->saved = clone $GLOBALS['wpdb'];
		// foreign_prefixes() caches per database + prefix: a fresh name per test.
		$GLOBALS['wpdb']->dbname = 'db_' . bin2hex( random_bytes( 4 ) );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved;
		unset( $GLOBALS['wpc_test_multisite'] );
	}

	// ------------------------------------------------------------------
	// Database
	// ------------------------------------------------------------------

	public function test_identifiers_are_quoted_and_backticks_doubled(): void {
		$this->assertSame( '`wp_posts`', Database::quote_identifier( 'wp_posts' ) );
		$this->assertSame( '`a``; DROP TABLE x; --`', Database::quote_identifier( 'a`; DROP TABLE x; --' ) );
	}

	public function test_id_lists_contain_integers_only(): void {
		$this->assertSame( '1,2,1,0,7', Database::id_list( array( 1, '2', '1 OR 1=1', ') OR (1', 7.9 ) ) );
		$this->assertSame( '', Database::id_list( array() ) );
	}

	public function test_single_site_owns_every_prefixed_table(): void {
		$this->assertTrue( Database::belongs_to_site( 'wp_posts' ) );
		$this->assertTrue( Database::belongs_to_site( 'wp_woocommerce_sessions' ) );
		$this->assertFalse( Database::belongs_to_site( 'other_posts' ) );
		$this->assertFalse( Database::belongs_to_site( 'wpposts' ) );
	}

	public function test_another_install_sharing_the_database_is_never_ours(): void {
		$GLOBALS['wpdb']->tables_in_db = array(
			'wp_options', 'wp_posts', 'wp_postmeta', 'wp_term_taxonomy',
			'wp_shop_options', 'wp_shop_posts', 'wp_shop_postmeta', 'wp_shop_term_taxonomy', 'wp_shop_users',
			// A plugin table that merely ends in "options" is not an install.
			'wp_myplugin_options',
		);
		$this->assertSame( array( 'wp_shop_' ), Database::foreign_prefixes() );
		$this->assertFalse( Database::belongs_to_site( 'wp_shop_posts' ) );
		$this->assertFalse( Database::belongs_to_site( 'wp_shop_users' ) );
		$this->assertTrue( Database::belongs_to_site( 'wp_posts' ) );
		$this->assertTrue( Database::belongs_to_site( 'wp_myplugin_options' ) );
	}

	public function test_multisite_main_site_never_owns_subsite_tables(): void {
		$GLOBALS['wpc_test_multisite'] = true;
		$this->assertTrue( Database::is_other_subsite_table( 'wp_2_posts' ) );
		$this->assertTrue( Database::is_other_subsite_table( 'wp_22_options' ) );
		$this->assertFalse( Database::is_other_subsite_table( 'wp_posts' ) );
		$this->assertFalse( Database::is_other_subsite_table( 'wp_2fa_codes' ) );
		$this->assertFalse( Database::belongs_to_site( 'wp_2_posts' ) );
		$this->assertTrue( Database::belongs_to_site( 'wp_posts' ) );
	}

	public function test_multisite_subsite_owns_only_its_own_tables(): void {
		$GLOBALS['wpc_test_multisite'] = true;
		$GLOBALS['wpdb']->prefix       = 'wp_2_';
		$this->assertTrue( Database::belongs_to_site( 'wp_2_posts' ) );
		$this->assertFalse( Database::belongs_to_site( 'wp_22_posts' ) );
		$this->assertFalse( Database::belongs_to_site( 'wp_posts' ) );
		$this->assertSame( array(), Database::network_tables(), 'only the main site sees network tables' );
	}

	// ------------------------------------------------------------------
	// Schema
	// ------------------------------------------------------------------

	public function test_snapshot_table_names_fit_mysql_and_stay_unique(): void {
		$a = str_repeat( 'wp_a_very_long_plugin_table_name_', 3 ) . 'one';
		$b = str_repeat( 'wp_a_very_long_plugin_table_name_', 3 ) . 'two';
		foreach ( array( '12', 'stg_abcdef', 'old_abcdef' ) as $tag ) {
			$na = Schema::snapshot_table_name( $tag, $a );
			$nb = Schema::snapshot_table_name( $tag, $b );
			$this->assertLessThanOrEqual( 64, strlen( $na ) );
			$this->assertNotSame( $na, $nb, 'truncated names must not collide' );
			$this->assertTrue( Schema::is_snapshot_table( $na ) );
		}
		$this->assertSame( '_wpc_7_' . substr( md5( 'wp_posts' ), 0, 8 ) . '_wp_posts', Schema::snapshot_table_name( '7', 'wp_posts' ) );
	}

	public function test_snapshot_tables_are_recognised_including_legacy_ones(): void {
		$this->assertTrue( Schema::is_snapshot_table( '_wpchrono_snap_3_wp_posts' ) );
		$this->assertFalse( Schema::is_snapshot_table( 'wp_posts' ) );
		$this->assertFalse( Schema::is_snapshot_table( 'wp__wpc_x' ) );
	}

	public function test_internal_tables_use_the_site_prefix(): void {
		$this->assertSame( array( 'wp_wpchrono_snapshots', 'wp_wpchrono_snapshot_tables' ), Schema::internal_tables() );
	}

	// ------------------------------------------------------------------
	// TableCopier keyset paging
	// ------------------------------------------------------------------

	public function test_order_clause(): void {
		$this->assertSame( ' ORDER BY `ID`', TableCopier::order_clause( array( 'ID' ) ) );
		$this->assertSame( ' ORDER BY `a`,`b`', TableCopier::order_clause( array( 'a', 'b' ) ) );
		$this->assertSame( '', TableCopier::order_clause( array() ) );
	}

	public function test_first_page_has_no_where_clause(): void {
		$this->assertSame( '', TableCopier::after_clause( array( 'ID' ), '' ) );
	}

	public function test_single_key_cursor(): void {
		$cursor = TableCopier::encode_key( array( 'ID' ), array( 'ID' => 42, 'x' => 'y' ) );
		$this->assertSame( '["42"]', $cursor );
		$this->assertSame( " WHERE (`ID` > '42')", TableCopier::after_clause( array( 'ID' ), $cursor ) );
	}

	public function test_composite_key_cursor_is_lexicographic(): void {
		$cursor = TableCopier::encode_key( array( 'a', 'b', 'c' ), array( 'a' => 1, 'b' => 'x', 'c' => 3 ) );
		$this->assertSame(
			" WHERE (`a` > '1') OR (`a` = '1' AND `b` > 'x') OR (`a` = '1' AND `b` = 'x' AND `c` > '3')",
			TableCopier::after_clause( array( 'a', 'b', 'c' ), $cursor )
		);
	}

	public function test_pre_v3_bare_integer_cursor_still_works(): void {
		$this->assertSame( " WHERE (`ID` > '1500')", TableCopier::after_clause( array( 'ID' ), '1500' ) );
	}

	public function test_a_cursor_that_does_not_match_the_key_restarts_from_the_beginning(): void {
		$this->assertSame( '', TableCopier::after_clause( array( 'a', 'b' ), '["1"]' ) );
	}

	public function test_key_values_are_escaped(): void {
		$cursor = TableCopier::encode_key( array( 'k' ), array( 'k' => "x' OR '1'='1" ) );
		$this->assertSame( " WHERE (`k` > 'x\\' OR \\'1\\'=\\'1')", TableCopier::after_clause( array( 'k' ), $cursor ) );
	}

	public function test_foreign_keys_are_extracted_from_show_create_table(): void {
		$sql = "CREATE TABLE `wp_wc_download_log` (\n  `id` bigint(20) NOT NULL,\n  `permission_id` bigint(20) NOT NULL,\n  PRIMARY KEY (`id`),\n"
			. "  CONSTRAINT `fk_a` FOREIGN KEY (`permission_id`) REFERENCES `wp_perm` (`permission_id`) ON DELETE CASCADE,\n"
			. "  CONSTRAINT `fk_b` FOREIGN KEY (`id`) REFERENCES `wp_x` (`id`)\n) ENGINE=InnoDB";
		$this->assertSame(
			array(
				'CONSTRAINT `fk_a` FOREIGN KEY (`permission_id`) REFERENCES `wp_perm` (`permission_id`) ON DELETE CASCADE',
				'CONSTRAINT `fk_b` FOREIGN KEY (`id`) REFERENCES `wp_x` (`id`)',
			),
			TableCopier::foreign_keys( $sql )
		);
		$this->assertSame( array(), TableCopier::foreign_keys( "CREATE TABLE `t` (\n  `id` int\n)" ) );
	}
}
