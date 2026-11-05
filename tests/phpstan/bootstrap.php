<?php
/**
 * Constants PHPStan cannot discover: the plugin's own (defined in
 * wp-chrono.php at runtime) and WordPress runtime constants missing from
 * the stubs.
 *
 * @package WPChrono
 */

define( 'WP_CHRONO_VERSION', '0.0.0' );
define( 'WP_CHRONO_FILE', __DIR__ . '/../../wp-chrono.php' );
define( 'WP_CHRONO_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'WP_CHRONO_URL', 'https://example.test/wp-content/plugins/wp-chrono/' );
define( 'WP_CHRONO_BASENAME', 'wp-chrono/wp-chrono.php' );

define( 'WPINC', 'wp-includes' );
define( 'LOGGED_IN_COOKIE', 'wordpress_logged_in_' );
define( 'PCLZIP_OPT_PATH', 77001 );
define( 'PCLZIP_OPT_REMOVE_PATH', 77003 );
