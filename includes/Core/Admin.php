<?php
/**
 * Admin screen: menu entry, SPA mount point, and asset loading.
 *
 * Assets are enqueued only when the current screen is our own page.
 *
 * @package WPChrono
 */

namespace WPChrono\Core;

use WPChrono\API\OnboardingController;
use WPChrono\API\RestController;
use WPChrono\Services\LicenseService;
use WPChrono\Services\Snapshot\Exporter;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const SLUG = 'wp-chrono';

	/** @var string */
	private $hook_suffix = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . WP_CHRONO_BASENAME, array( $this, 'action_links' ) );
		add_action( 'admin_post_wp_chrono_download', array( Exporter::class, 'handle_download' ) );
	}

	public function add_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'WP Chrono', 'wp-chrono' ),
			__( 'WP Chrono', 'wp-chrono' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-backup',
			80
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-chrono' ) );
		}

		if ( ! is_readable( WP_CHRONO_DIR . 'build/index.js' ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>';
			echo esc_html__( 'WP Chrono assets are missing. Run `npm install && npm run build` inside the plugin directory.', 'wp-chrono' );
			echo '</p></div></div>';
			return;
		}

		echo '<div id="wp-chrono-root"></div>';
		echo '<noscript><div class="wrap"><p>' . esc_html__( 'WP Chrono requires JavaScript.', 'wp-chrono' ) . '</p></div></noscript>';
	}

	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$script = WP_CHRONO_DIR . 'build/index.js';
		$style  = WP_CHRONO_DIR . 'build/index.css';
		if ( ! is_readable( $script ) ) {
			return;
		}

		wp_enqueue_script( 'wp-chrono-app', WP_CHRONO_URL . 'build/index.js', array(), (string) filemtime( $script ), true );
		if ( is_readable( $style ) ) {
			wp_enqueue_style( 'wp-chrono-app', WP_CHRONO_URL . 'build/index.css', array(), (string) filemtime( $style ) );
		}

		$user   = wp_get_current_user();
		$config = array(
			'version'     => WP_CHRONO_VERSION,
			// Always the plain REST form via index.php: it routes with any
			// permalink setting (pretty or plain), so REST calls keep working
			// even if a full reset rewrites permalink_structure before the
			// page reloads. rest_url() would give /wp-json/… on pretty sites,
			// which stops routing once the site is plain. The index.php
			// prefix (not /) is required on nginx: /?rest_route=… is a
			// directory URI that nginx answers 405 for DELETE.
			'restUrl'     => esc_url_raw( trailingslashit( home_url() ) . 'index.php?rest_route=/' . RestController::NAMESPACE . '/' ),
			'restNonce'   => wp_create_nonce( 'wp_rest' ),
			'chronoNonce' => wp_create_nonce( RestController::NONCE_ACTION ),
			'adminUrl'    => esc_url_raw( admin_url() ),
			'siteUrl'     => esc_url_raw( home_url( '/' ) ),
			'siteName'    => get_bloginfo( 'name' ),
			'user'        => array(
				'name'  => $user->display_name,
				'login' => $user->user_login,
			),
			'woocommerce' => class_exists( 'WooCommerce' ),
			'multisite'   => is_multisite(),
			'mainSite'    => is_multisite() && is_main_site(),
			'superAdmin'  => is_super_admin(),
			'welcome'     => ! OnboardingController::flags()['welcome_seen'],
			'license'     => array_intersect_key( LicenseService::status(), array_flip( array( 'active', 'plan', 'features', 'pricing' ) ) ),
		);

		wp_add_inline_script( 'wp-chrono-app', 'window.wpChrono = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * @param string[] $links
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ), esc_html__( 'Open', 'wp-chrono' ) )
		);
		return $links;
	}
}
