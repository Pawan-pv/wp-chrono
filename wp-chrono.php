<?php
/**
 * Plugin Name:       WP Chrono
 * Description:       Reset, snapshot, recovery and blueprint toolkit for WordPress developers.
 * Version:           1.0.4
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Update URI:        https://wp-chrono-api.wordpress-plugin.workers.dev/wp-chrono
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-chrono
 * Domain Path:       /languages
 *
 * @package WPChrono
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_CHRONO_VERSION', '1.0.4' );
define( 'WP_CHRONO_FILE', __FILE__ );
define( 'WP_CHRONO_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_CHRONO_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_CHRONO_BASENAME', plugin_basename( __FILE__ ) );

// Prefer Composer's autoloader; fall back to a minimal PSR-4 loader so the
// plugin runs from a plain zip without a `composer install` step.
if ( is_readable( WP_CHRONO_DIR . 'vendor/autoload.php' ) ) {
	require_once WP_CHRONO_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class ) {
			$prefix = 'WPChrono\\';
			if ( 0 !== strpos( $class, $prefix ) ) {
				return;
			}
			$file = WP_CHRONO_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook( __FILE__, array( \WPChrono\Core\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WPChrono\Services\Scheduler::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \WPChrono\Core\Plugin::class, 'boot' ) );
