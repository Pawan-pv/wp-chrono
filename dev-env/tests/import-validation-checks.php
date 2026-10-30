<?php
/**
 * Importer statement validation: real Exporter output is accepted; CREATE…SELECT,
 * INSERT…SELECT, functions, FEDERATED/CONNECT/MERGE engines, DATA DIRECTORY and
 * smuggled ALTER clauses are refused. Large INSERTs stay linear.
 *
 *   wp eval-file /tests/import-validation-checks.php
 */
use WPChrono\Services\Snapshot\Importer as I;
$ok = 0; $bad = 0;
$t = function ( $label, $cond ) use ( &$ok, &$bad ) { echo ( $cond ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; $cond ? $ok++ : $bad++; };
$create = "CREATE TABLE `wp_posts` (\n  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `post_title` text NOT NULL DEFAULT '',\n  PRIMARY KEY (`ID`)\n) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='it''s AS SELECT x'";
$t( 'real CREATE accepted', null === I::refuse_create( $create ) );
$t( 'CREATE … SELECT refused', null !== I::refuse_create( "CREATE TABLE `wp_x` (`a` text) SELECT user_pass AS a FROM wp_users" ) );
$t( 'CREATE … AS SELECT refused', null !== I::refuse_create( "CREATE TABLE `wp_x` (`a` text) AS SELECT 1" ) );
$t( 'FEDERATED refused', null !== I::refuse_create( "CREATE TABLE `wp_x` (`a` int) ENGINE=FEDERATED CONNECTION='mysql://x'" ) );
$t( 'DATA DIRECTORY refused', null !== I::refuse_create( "CREATE TABLE `wp_x` (`a` int) ENGINE=InnoDB DATA DIRECTORY='/tmp'" ) );
$t( 'MERGE UNION refused', null !== I::refuse_create( "CREATE TABLE `wp_x` (`a` int) ENGINE=MERGE UNION=(wp_users)" ) );
$ins = "INSERT INTO `wp_posts` (`ID`, `post_title`) VALUES\n('1','Hello \' SELECT world'),\n('2',NULL)";
$t( 'real INSERT accepted', I::is_plain_insert( $ins ) );
$t( 'INSERT … SELECT refused', ! I::is_plain_insert( "INSERT INTO `wp_posts` (`ID`) SELECT ID FROM wp_users" ) );
$t( 'INSERT with function refused', ! I::is_plain_insert( "INSERT INTO `wp_posts` (`ID`) VALUES (LOAD_FILE('/etc/passwd'))" ) );
$t( 'INSERT ON DUPLICATE refused', ! I::is_plain_insert( "INSERT INTO `wp_posts` (`ID`) VALUES ('1') ON DUPLICATE KEY UPDATE ID=2" ) );
$t( 'INSERT sub-select in values refused', ! I::is_plain_insert( "INSERT INTO `wp_posts` (`ID`) VALUES ((SELECT 1))" ) );
$t( 'unterminated quote refused', ! I::is_plain_insert( "INSERT INTO `wp_posts` (`ID`) VALUES ('1)" ) );
$t( 'real FK accepted', I::is_safe_fk( 'CONSTRAINT `fk_1` FOREIGN KEY (`order_id`) REFERENCES `wp_wc_orders` (`id`) ON DELETE CASCADE' ) );
$t( 'FK + extra clause refused', ! I::is_safe_fk( 'CONSTRAINT `fk_1` FOREIGN KEY (`a`) REFERENCES `wp_x` (`b`), DROP COLUMN `c`' ) );
$t( 'FK + ENGINE refused', ! I::is_safe_fk( 'CONSTRAINT `fk_1` FOREIGN KEY (`a`) REFERENCES `wp_x` (`b`) ENGINE=CONNECT' ) );
$big = "INSERT INTO `wp_t` (`a`) VALUES " . implode( ',', array_fill( 0, 20000, "('" . str_repeat( "x\\'y", 200 ) . "')" ) );
$s = microtime( true ); $r = I::is_plain_insert( $big );
$t( sprintf( '16 MB INSERT accepted in %.0f ms', 1000 * ( microtime( true ) - $s ) ), $r );
echo $bad ? "\n$bad check(s) FAILED ($ok passed)\n" : "\nAll checks passed ($ok passed)\n";
