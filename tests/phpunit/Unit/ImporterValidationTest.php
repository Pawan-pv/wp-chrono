<?php
/**
 * Statement validation for imported dumps (uploaded .sql.gz, cloud pulls).
 * A dump is untrusted: anything beyond the exact shapes Exporter writes must
 * be refused, because the CREATE TABLE and INSERT statements run as written.
 *
 * @package WPChrono
 */

namespace WPChrono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPChrono\Services\Snapshot\Importer;

final class ImporterValidationTest extends TestCase {

	private const TABLE = "CREATE TABLE `wp_t` (\n  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `v` longtext DEFAULT NULL,\n  PRIMARY KEY (`id`)\n)";

	/**
	 * Statements of a real WP Chrono export, split the way Importer::tick() does.
	 *
	 * @return string[]
	 */
	private static function real_export(): array {
		$statements = array();
		$current    = '';
		$fh         = fopen( dirname( __DIR__ ) . '/fixtures/real-export.sql', 'rb' );
		while ( false !== ( $line = fgets( $fh ) ) ) {
			$current .= $line;
			if ( ';' === substr( rtrim( $line ), -1 ) ) {
				$statements[] = rtrim( trim( $current ), ';' );
				$current      = '';
			}
		}
		fclose( $fh );
		return $statements;
	}

	// ------------------------------------------------------------------
	// mask_literals
	// ------------------------------------------------------------------

	/**
	 * @dataProvider masking_cases
	 */
	public function test_mask_literals( string $sql, ?string $expected ): void {
		$this->assertSame( $expected, Importer::mask_literals( $sql ) );
	}

	/** @return array<string,array{string,?string}> */
	public function masking_cases(): array {
		return array(
			'single quoted'                  => array( "INSERT INTO `t` VALUES ('a')", 'INSERT INTO I VALUES (S)' ),
			'backslash-escaped quote'        => array( "('it\\'s')", '(S)' ),
			'doubled quote'                  => array( "('it''s')", '(S)' ),
			'escaped backslash before quote' => array( "('a\\\\'),('b')", '(S),(S)' ),
			'double quoted'                  => array( '("x ) SELECT")', '(S)' ),
			'identifier with doubled tick'   => array( 'CREATE TABLE `a``b` (', 'CREATE TABLE I (' ),
			'keywords inside a literal'      => array( "COMMENT='ENGINE=FEDERATED'", 'COMMENT=S' ),
			'unterminated literal'           => array( "('abc", null ),
			'unterminated identifier'        => array( '`abc', null ),
			'block comment hides no quote'   => array( "x /* it's */ y", 'x # y' ),
			'executable comment'             => array( "x /*!50100 ENGINE=FEDERATED */ y", 'x # y' ),
			'line comment (dash dash space)' => array( "x -- it's\ny", "x #\ny" ),
			'line comment (hash)'            => array( "x # it's\ny", "x #\ny" ),
			'line comment at the very end'   => array( "x --", 'x #' ),
			'quote inside literal is no comment' => array( "('/*'),('#'),('-- ')", '(S),(S),(S)' ),
			'unterminated block comment'     => array( 'x /* y', null ),
			'double dash without space is no comment' => array( 'a--1', 'a--1' ),
		);
	}

	// ------------------------------------------------------------------
	// refuse_create
	// ------------------------------------------------------------------

	public function test_every_create_table_of_a_real_export_is_accepted(): void {
		$creates = array_filter(
			self::real_export(),
			static function ( $s ) {
				return 0 === strpos( $s, 'CREATE TABLE' );
			}
		);
		$this->assertGreaterThanOrEqual( 10, count( $creates ) );
		foreach ( $creates as $sql ) {
			$this->assertNull( Importer::refuse_create( $sql ), substr( $sql, 0, 60 ) );
		}
	}

	/**
	 * @dataProvider accepted_creates
	 */
	public function test_plain_table_definitions_are_accepted( string $sql ): void {
		$this->assertNull( Importer::refuse_create( $sql ) );
	}

	/** @return array<string,array{string}> */
	public function accepted_creates(): array {
		return array(
			'InnoDB'                           => array( self::TABLE . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' ),
			'MyISAM'                           => array( self::TABLE . ' ENGINE=MyISAM' ),
			'Aria (MariaDB)'                   => array( self::TABLE . ' ENGINE=Aria PAGE_CHECKSUM=1' ),
			'lower-case engine'                => array( self::TABLE . ' ENGINE=innodb' ),
			'no engine at all'                 => array( self::TABLE ),
			'keywords inside the comment'      => array( self::TABLE . " ENGINE=InnoDB COMMENT='SELECT … UNION ENGINE=FEDERATED; DATA DIRECTORY'" ),
			'escaped quote in the comment'     => array( self::TABLE . " ENGINE=InnoDB COMMENT='it\\'s ok'" ),
			'mariadb-5.3 temporal comment'     => array( "CREATE TABLE `wp_t` (\n  `d` datetime /* mariadb-5.3 */ DEFAULT NULL\n) ENGINE=InnoDB" ),
			'foreign key (WooCommerce)'        => array( "CREATE TABLE `wp_wc_download_log` (\n  `download_log_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `permission_id` bigint(20) unsigned NOT NULL,\n  PRIMARY KEY (`download_log_id`),\n  KEY `permission_id` (`permission_id`),\n  CONSTRAINT `fk_wp_wc_download_log_permission_id` FOREIGN KEY (`permission_id`) REFERENCES `wp_woocommerce_downloadable_product_permissions` (`permission_id`) ON DELETE CASCADE\n) ENGINE=InnoDB" ),
			'generated column'                 => array( "CREATE TABLE `wp_t` (\n  `a` int(11) DEFAULT NULL,\n  `b` int(11) GENERATED ALWAYS AS (`a` * 2) VIRTUAL\n) ENGINE=InnoDB" ),
		);
	}

	/**
	 * @dataProvider refused_creates
	 */
	public function test_dangerous_table_definitions_are_refused( string $sql, string $reason_fragment ): void {
		$reason = Importer::refuse_create( $sql );
		$this->assertNotNull( $reason, 'accepted: ' . $sql );
		$this->assertStringContainsStringIgnoringCase( $reason_fragment, (string) $reason );
	}

	/** @return array<string,array{string,string}> */
	public function refused_creates(): array {
		return array(
			'FEDERATED (reaches another server)' => array( self::TABLE . " ENGINE=FEDERATED CONNECTION='mysql://u@evil/db/t'", 'not allowed' ),
			'FEDERATED, comment syntax'          => array( self::TABLE . " ENGINE=FEDERATED COMMENT='mysql://u@evil/db/t'", 'FEDERATED' ),
			'CONNECT (reads server files)'       => array( self::TABLE . " ENGINE=CONNECT TABLE_TYPE=CSV FILE_NAME='/etc/passwd'", 'CONNECT' ),
			'engine without ='                   => array( self::TABLE . " ENGINE FEDERATED COMMENT='mysql://u@evil/db/t'", 'FEDERATED' ),
			'engine with spaces and no ='        => array( self::TABLE . " ENGINE   CONNECT FILE_NAME='/etc/passwd'", 'CONNECT' ),
			'second engine overrides the first'  => array( self::TABLE . " ENGINE=InnoDB ENGINE=FEDERATED COMMENT='mysql://u@evil/db/t'", 'FEDERATED' ),
			'engine hidden after a comment'      => array( self::TABLE . " ENGINE/**/=FEDERATED COMMENT='mysql://u@evil/db/t'", 'comment' ),
			'quote inside comment desyncs masking' => array( self::TABLE . " ENGINE=InnoDB /* ' */ ENGINE=FEDERATED /* ' */", 'comment' ),
			'executable comment'                 => array( self::TABLE . ' /*!50100 ENGINE=FEDERATED */', 'comment' ),
			'line comment'                       => array( self::TABLE . " ENGINE=InnoDB -- x\n", 'comment' ),
			'hash comment'                       => array( self::TABLE . " ENGINE=InnoDB # x\n", 'comment' ),
			'CREATE … SELECT'                    => array( self::TABLE . ' SELECT * FROM wp_users', 'SELECT' ),
			'CREATE … AS SELECT'                 => array( self::TABLE . ' AS SELECT 1', 'not allowed' ),
			'sub-query in the column list'       => array( "CREATE TABLE `wp_t` (\n  `a` int DEFAULT (SELECT 1)\n)", 'sub-queries' ),
			'MERGE … UNION'                      => array( self::TABLE . ' ENGINE=MRG_MyISAM UNION=(`wp_users`)', 'UNION' ),
			'DATA DIRECTORY'                     => array( self::TABLE . " ENGINE=MyISAM DATA DIRECTORY='/var/www/html'", 'DIRECTORY' ),
			'INDEX DIRECTORY'                    => array( self::TABLE . " ENGINE=MyISAM INDEX DIRECTORY='/tmp'", 'DIRECTORY' ),
			'CONNECTION'                         => array( self::TABLE . " CONNECTION='mysql://u@evil/db/t'", 'CONNECTION' ),
			'TABLESPACE'                         => array( self::TABLE . ' TABLESPACE innodb_system', 'TABLESPACE' ),
			'CREATE … LIKE'                      => array( 'CREATE TABLE `wp_t` LIKE `wp_users`', 'malformed' ),
			'not a CREATE TABLE'                 => array( 'CREATE USER `x`', 'malformed' ),
			'unterminated literal'               => array( self::TABLE . " COMMENT='x", 'malformed' ),
			'unbalanced parentheses'             => array( "CREATE TABLE `wp_t` (\n  `a` int", 'unbalanced' ),
		);
	}

	// ------------------------------------------------------------------
	// is_plain_insert
	// ------------------------------------------------------------------

	public function test_every_insert_of_a_real_export_is_accepted(): void {
		$inserts = array_filter(
			self::real_export(),
			static function ( $s ) {
				return 0 === strpos( $s, 'INSERT INTO' );
			}
		);
		$this->assertNotEmpty( $inserts );
		foreach ( $inserts as $sql ) {
			$this->assertTrue( Importer::is_plain_insert( $sql ), substr( $sql, 0, 60 ) );
		}
	}

	/**
	 * @dataProvider insert_cases
	 */
	public function test_is_plain_insert( string $sql, bool $expected ): void {
		$this->assertSame( $expected, Importer::is_plain_insert( $sql ) );
	}

	/** @return array<string,array{string,bool}> */
	public function insert_cases(): array {
		$head = 'INSERT INTO `wp_t` (`id`,`v`) VALUES';
		return array(
			'one row'                   => array( "$head ('1','a')", true ),
			'several rows, newlines'    => array( "$head\n('1','a'),\n('2',NULL),\n('3','x\\'y')", true ),
			'serialized PHP value'      => array( "$head ('1','a:1:{s:1:\\\"k\\\";s:1:\\\"v\\\";}')", true ),
			'SQL text inside a value'   => array( "$head ('1','\\'),(LOAD_FILE(\\'/etc/passwd\\'))-- ')", true ),
			'comment text inside value' => array( "$head ('1','/* x */ # y -- z')", true ),
			'INSERT … SELECT'           => array( 'INSERT INTO `wp_t` (`id`,`v`) SELECT ID, user_pass FROM wp_users', false ),
			'function call'             => array( "$head ('1',LOAD_FILE('/etc/passwd'))", false ),
			'sub-query'                 => array( "$head ('1',(SELECT user_pass FROM wp_users LIMIT 1))", false ),
			'unquoted number'           => array( "$head (1,'a')", false ),
			'variable'                  => array( "$head ('1',@x)", false ),
			'ON DUPLICATE KEY UPDATE'   => array( "$head ('1','a') ON DUPLICATE KEY UPDATE v = 'b'", false ),
			'trailing comma in row'     => array( "$head ('1','a',)", false ),
			'empty value'               => array( "$head ('1',,'a')", false ),
			'empty row'                 => array( "$head ()", false ),
			'empty row between rows'    => array( "$head ('1','a'),(),('2','b')", false ),
			'no rows'                   => array( $head, false ),
			'comment between values'    => array( "$head ('1' /* x */,'a')", false ),
			'quote in comment desync'   => array( "$head ('1','a') /* ' */ ,(LOAD_FILE('x')) /* ' */", false ),
			'unterminated literal'      => array( "$head ('1','a)", false ),
			'columns not identifiers'   => array( "INSERT INTO `wp_t` (id) VALUES ('1')", false ),
			'INSERT IGNORE'             => array( "INSERT IGNORE INTO `wp_t` (`id`) VALUES ('1')", false ),
		);
	}

	// ------------------------------------------------------------------
	// is_safe_fk
	// ------------------------------------------------------------------

	/**
	 * @dataProvider fk_cases
	 * @param mixed $fk
	 */
	public function test_is_safe_fk( $fk, bool $expected ): void {
		$this->assertSame( $expected, Importer::is_safe_fk( $fk ) );
	}

	/** @return array<string,array{mixed,bool}> */
	public function fk_cases(): array {
		$fk = 'CONSTRAINT `fk_a` FOREIGN KEY (`permission_id`) REFERENCES `wp_perm` (`permission_id`)';
		return array(
			'plain'                       => array( $fk, true ),
			'ON DELETE CASCADE'           => array( "$fk ON DELETE CASCADE", true ),
			'ON DELETE + ON UPDATE'       => array( "$fk ON DELETE SET NULL ON UPDATE NO ACTION", true ),
			'composite key'               => array( 'CONSTRAINT `fk` FOREIGN KEY (`a`, `b`) REFERENCES `wp_x` (`a`, `b`)', true ),
			'surrounding whitespace'      => array( "  $fk  ", true ),
			'not a string'                => array( array( $fk ), false ),
			'null'                        => array( null, false ),
			'trailing statement'          => array( "$fk, DROP TABLE `wp_users`", false ),
			'unknown action'              => array( "$fk ON DELETE DROP", false ),
			'comment'                     => array( "$fk /* x */", false ),
			'unquoted identifiers'        => array( 'CONSTRAINT fk FOREIGN KEY (a) REFERENCES wp_x (a)', false ),
			'unterminated identifier'     => array( 'CONSTRAINT `fk FOREIGN KEY', false ),
		);
	}
}
