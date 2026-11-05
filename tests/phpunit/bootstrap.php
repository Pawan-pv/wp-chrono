<?php
/**
 * PHPUnit bootstrap for WP Chrono's unit tests.
 *
 * Unit tests run without WordPress or a database: the few core functions the
 * tested code calls are provided by stubs/wordpress.php, and plugin classes
 * load through the same PSR-4 mapping the plugin uses. Integration and E2E
 * coverage lives in dev-env/tests (real WordPress + MariaDB + Chrome).
 *
 *   php phpunit.phar -c tests/phpunit/phpunit.xml.dist
 *   composer test
 *
 * @package WPChrono
 */

error_reporting( E_ALL );

define( 'ABSPATH', sys_get_temp_dir() . '/wpc-unit-wp/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'WP_CHRONO_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'WP_CHRONO_VERSION', '0.0.0-test' );

require __DIR__ . '/stubs/wordpress.php';

spl_autoload_register(
	static function ( $class ) {
		$prefixes = array(
			'WPChrono\\Tests\\' => __DIR__ . '/',
			'WPChrono\\'        => WP_CHRONO_DIR . 'includes/',
		);
		foreach ( $prefixes as $prefix => $dir ) {
			if ( 0 === strpos( $class, $prefix ) ) {
				$file = $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
				return;
			}
		}
	}
);
