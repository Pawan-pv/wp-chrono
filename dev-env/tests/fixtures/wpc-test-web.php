<?php
/**
 * Test-only must-use plugin (copied into mu-plugins by safe-update-woo-checks.php,
 * removed afterwards). Active only while /tmp/wpc-web-tests.on exists.
 *
 * Gives web requests and child WP-CLI processes what the eval-file tests set up
 * in-process: offline packages, loopback routing, captured mail. Plus two
 * simulations: a remote-management worker (ManageWP / MainWP style, updating
 * from a front-end request) and a page cache plugin serving on `init`.
 */

if ( ! file_exists( '/tmp/wpc-web-tests.on' ) ) {
	return;
}

// Loopback: inside the container the site answers on port 80; home is :8088.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== $pre || 0 !== strpos( (string) $url, 'http://localhost:8088/' ) ) {
			return $pre;
		}
		$args['headers']         = (array) ( $args['headers'] ?? array() );
		$args['headers']['Host'] = 'localhost:8088';
		return wp_remote_request( 'http://localhost/' . substr( $url, strlen( 'http://localhost:8088/' ) ), $args );
	},
	1,
	3
);

// Offline packages built by lib/upgrade-helpers.php (wpc_plugin_zip()).
add_filter(
	'upgrader_pre_download',
	static function ( $reply, $package ) {
		if ( ! preg_match( '#^https://packages\.invalid/(.+\.zip)$#', (string) $package, $m ) ) {
			return $reply;
		}
		$copy = wp_tempnam( $m[1] );
		copy( get_temp_dir() . 'wpc-packages/' . $m[1], $copy );
		return $copy;
	},
	10,
	2
);
remove_action( 'upgrader_process_complete', 'wp_version_check' );
remove_action( 'upgrader_process_complete', 'wp_update_plugins' );
remove_action( 'upgrader_process_complete', 'wp_update_themes' );
remove_action( 'upgrader_process_complete', array( 'Language_Pack_Upgrader', 'async_upgrade' ), 20 );

// Mail: kept in an option the test reads (written after any restore).
add_filter(
	'pre_wp_mail',
	static function ( $null, $atts ) {
		$mails   = (array) get_option( 'wpc_test_mails', array() );
		$mails[] = array( 'to' => $atts['to'], 'subject' => $atts['subject'], 'message' => $atts['message'] );
		update_option( 'wpc_test_mails', $mails, false );
		return true;
	},
	10,
	2
);

// Diagnostics: which request stored a WooCommerce guest session (read by
// wpc_guest_trace() in safe-update-woo-checks.php).
add_action(
	'shutdown',
	static function () {
		if ( ! function_exists( 'WC' ) || ! WC()->session || is_user_logged_in() || ! WC()->session->has_session() ) {
			return;
		}
		$line = gmdate( 'H:i:s' ) . ' ' . ( $_SERVER['REQUEST_METHOD'] ?? 'CLI' ) . ' ' . ( $_SERVER['REQUEST_URI'] ?? '' ) . ' customer=' . WC()->session->get_customer_id() . "\n";
		file_put_contents( get_temp_dir() . 'wpc-guest-origin.log', $line, FILE_APPEND );
	},
	99
);

// Remote-management worker: updates a plugin from a FRONT-END request.
if ( isset( $_GET['wpc_remote_update'], $_GET['plugin'] ) ) {
	add_action(
		'init',
		static function () {
			if ( ! hash_equals( (string) get_option( 'wpc_remote_token' ), (string) $_GET['wpc_remote_update'] ) ) {
				status_header( 403 );
				exit;
			}
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$result   = $upgrader->bulk_upgrade( array( sanitize_text_field( wp_unslash( $_GET['plugin'] ) ) ) );
			echo 'REMOTE-DONE ' . wp_json_encode( array_map( 'is_array', (array) $result ) );
			exit;
		},
		1
	);
}

// Page cache plugin answering on `init` (after WordPress started, before the
// page is rendered) for the WooCommerce cart page, anonymous GETs only.
// Option wpc_test_cache: 'full' = keyed by the whole URL; 'strip' = ignores
// unknown query parameters (like many CDN / host cache-key rules).
add_action(
	'init',
	static function () {
		$mode = (string) get_option( 'wpc_test_cache', '' );
		if ( '' === $mode || is_admin() || is_user_logged_in() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		// The cart by ?page_id= (plain permalinks) or by its path (pretty permalinks).
		$cart = (int) get_option( 'woocommerce_cart_page_id' );
		$path = trailingslashit( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) );
		$is   = isset( $_GET['page_id'] ) ? (int) $_GET['page_id'] === $cart : ( $cart && trailingslashit( (string) wp_parse_url( (string) get_permalink( $cart ), PHP_URL_PATH ) ) === $path && '/' !== $path );
		if ( ! $is ) {
			return;
		}
		$query = wp_unslash( $_GET ) + array( '__path' => $path );
		if ( 'strip' === $mode ) {
			$query = array_intersect_key( $query, array( 'page_id' => 1, '__path' => 1 ) );
		}
		ksort( $query );
		$file = get_temp_dir() . 'wpc-cache-' . md5( http_build_query( $query ) ) . '.html';
		if ( is_file( $file ) ) {
			header( 'X-Test-Cache: HIT' );
			readfile( $file );
			exit;
		}
		header( 'X-Test-Cache: MISS' );
		ob_start(
			static function ( $html ) use ( $file ) {
				if ( 200 === http_response_code() && strlen( $html ) > 500 && false === strpos( $html, 'wp_scraping_result' ) ) {
					file_put_contents( $file, $html );
				}
				return $html;
			}
		);
	},
	0
);
