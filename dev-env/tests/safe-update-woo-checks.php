<?php
/**
 * Safe Updates on a WooCommerce store (real WooCommerce 9.9.7, real
 * Plugin_Upgrader, real loopback requests through nginx + PHP-FPM):
 *
 * - checkout broken (PHP fatal) while the home page still works → undone
 * - checkout renders without its form (HTTP 200, no fatal)         → undone
 * - a one-off error on one page, a slow page, a custom URL         → per URL
 * - probes never create carts, sessions or orders
 * - loopback blocked → nothing undone, admin notice explains why
 * - every trigger checks the shop pages: dashboard (AJAX + update.php),
 *   WP-CLI, WP-Cron auto-update, remote management, and WordPress core
 *   updates via WP-CLI, WP-Cron and Dashboard → Updates
 *
 * Run through tests/safe-update-woo-checks.sh, which installs WooCommerce
 * from dev-env/cache first and removes it (with its data) afterwards.
 *
 *   wp eval-file /tests/safe-update-woo-checks.php --user=chrono
 */

require __DIR__ . '/lib/upgrade-helpers.php';

use WPChrono\Services\SafeUpdates;
use WPChrono\Services\Settings;
use WPChrono\Services\Snapshot\FileArchive;
use WPChrono\Services\SnapshotService;

wpc_guest_trace( 'Setup' ); echo "== Setup ==\n";
$wpc_home = untrailingslashit( home_url() );
// Dev stack: PHP runs as root, and WP-CLI refuses that without --allow-root.
define( 'WPC_CLI_ROOT', function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ? ' --allow-root' : '' );
add_filter( 'wp_chrono_safe_updates_probe_url', static function ( $url ) use ( $wpc_home ) {
	return str_replace( $wpc_home, 'http://localhost', $url );
} );
add_filter( 'http_request_args', static function ( $args, $url ) use ( $wpc_home ) {
	if ( 0 === strpos( $url, 'http://localhost/' ) ) {
		$args['headers']['Host'] = wp_parse_url( $wpc_home, PHP_URL_HOST ) . ':' . wp_parse_url( $wpc_home, PHP_URL_PORT );
	}
	return $args;
}, 10, 2 );
add_filter( 'wp_chrono_safe_updates_retry_delay', static function () {
	return 1;
} );
$GLOBALS['wpc_mails'] = array();
add_filter( 'pre_wp_mail', static function ( $null, $atts ) {
	$GLOBALS['wpc_mails'][] = $atts;
	return true;
}, 10, 2 );

/** The newest auto-snapshot and its Safe Updates record. */
function wpc_last_auto(): array {
	foreach ( ( new SnapshotService() )->all() as $s ) {
		if ( 'auto' === $s['trigger_type'] ) {
			return ( new SnapshotService() )->get( $s['id'] );
		}
	}
	return array();
}
function wpc_reset_batch(): void {
	delete_transient( SnapshotService::AUTO_LOCK );
	wp_cache_flush();
}

/** Prints guest WooCommerce sessions when new ones appeared, so the step that made one is visible. */
function wpc_guest_trace( string $next ): void {
	global $wpdb;
	static $seen = array();
	if ( ! function_exists( 'WC' ) || ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}woocommerce_sessions'" ) ) {
		return;
	}
	$now = $wpdb->get_col( "SELECT session_key FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key NOT REGEXP '^[0-9]+$'" );
	$new = array_diff( $now, $seen );
	if ( $new ) {
		echo '      [trace] new guest session(s) before "' . $next . '": ' . implode( ', ', $new ) . "\n";
		$log = get_temp_dir() . 'wpc-guest-origin.log';
		if ( is_readable( $log ) ) {
			echo '      [trace] stored by: ' . str_replace( "\n", "\n                         ", trim( (string) file_get_contents( $log ) ) ) . "\n";
			@unlink( $log ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
	$seen = $now;
}

/** Rows that would show a probe wrote store data: sessions, orders, saved carts. */
function wpc_store_rows(): array {
	global $wpdb;
	$n = array(
		'sessions' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions" ),
		'orders'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('shop_order','shop_order_placehold')" ),
		'carts'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_woocommerce\\_persistent\\_cart%'" ),
	);
	// HPOS tables may be created during the run: a missing table counts as 0.
	$n['hpos_orders'] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders' ) ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders" ) : 0;
	return $n;
}
/** Upgrades the fixture add-on and returns its Safe Updates record. */
function wpc_update_addon( string $version ): array {
	wpc_reset_batch();
	ob_start();
	wpc_bulk_upgrade_plugins( array( 'wpc-shop-addon/wpc-shop-addon.php' => $version ) );
	ob_end_clean();
	wp_cache_flush();
	return (array) ( wpc_last_auto()['meta']['safe_update'] ?? array() );
}
/** Puts add-on v1.0 back by hand and resets its database marker. */
function wpc_addon_v1(): void {
	wpc_install_plugin( 'wpc-shop-addon', '1.0', $GLOBALS['wpc_v1'] );
	update_option( 'wpc_shop_db', 'v1' );
	wp_cache_flush();
	if ( ! is_plugin_active( 'wpc-shop-addon/wpc-shop-addon.php' ) ) {
		activate_plugin( 'wpc-shop-addon/wpc-shop-addon.php' );
	}
	sleep( 3 ); // Let PHP-FPM's OPcache (revalidate_freq=2) see the hand-written file.
}

/** Offers $file → $version through the update_plugins transient, as wordpress.org would. */
function wpc_offer_update( string $file, string $version ): void {
	wp_clean_plugins_cache();
	$slug                    = dirname( $file );
	$transient               = new stdClass();
	$transient->last_checked = time();
	$transient->checked      = array();
	$transient->response     = array();
	foreach ( get_plugins() as $f => $data ) {
		$transient->checked[ $f ] = $data['Version'];
	}
	$transient->checked[ $file ]  = wpc_disk_version( $file );
	$transient->response[ $file ] = (object) array(
		'slug'        => $slug,
		'plugin'      => $file,
		'new_version' => $version,
		'package'     => "https://packages.invalid/{$slug}-{$version}.zip",
		'url'         => "https://packages.invalid/{$slug}",
	);
	set_site_transient( 'update_plugins', $transient );
}
/** Safe Updates record of the newest auto-snapshot, read fresh from the database. */
function wpc_trigger_result(): array {
	wp_cache_flush();
	return (array) ( wpc_last_auto()['meta']['safe_update'] ?? array() );
}
/** Logs in to wp-admin over real HTTP; returns the session cookies. */
function wpc_login(): array {
	$r = wp_remote_post(
		'http://localhost:8088/wp-login.php',
		array(
			'body'        => array( 'log' => 'chrono', 'pwd' => 'chrono-pass', 'testcookie' => '1' ),
			'cookies'     => array( new WP_Http_Cookie( array( 'name' => 'wordpress_test_cookie', 'value' => 'WP Cookie check' ) ) ),
			'redirection' => 0,
			'timeout'     => 60,
		)
	);
	return wp_remote_retrieve_cookies( $r );
}

// Web requests and child WP-CLI runs get the same offline setup (fixtures/wpc-test-web.php).
wp_mkdir_p( WPMU_PLUGIN_DIR );
touch( '/tmp/wpc-web-tests.on' );
copy( __DIR__ . '/fixtures/wpc-test-web.php', WPMU_PLUGIN_DIR . '/wpc-test-web.php' );
include WPMU_PLUGIN_DIR . '/wpc-test-web.php'; // This process loaded mu-plugins before the copy.
delete_transient( '_wc_activation_redirect' ); // WooCommerce's first-visit setup redirect.
update_option( 'woocommerce_coming_soon', 'no' ); // A live store (new shops start "coming soon").
update_option( 'woocommerce_store_pages_only', 'no' );
delete_option( 'wpc_test_cache' );
array_map( 'unlink', glob( get_temp_dir() . 'wpc-cache-*.html' ) ?: array() );

wpc_check( 'WooCommerce is active', class_exists( 'WooCommerce' ) && function_exists( 'wc_get_page_id' ), defined( 'WC_VERSION' ) ? WC_VERSION : 'missing' );
if ( ! class_exists( 'WooCommerce' ) ) {
	echo "\n1 check(s) FAILED (0 passed) — run through safe-update-woo-checks.sh\n";
	return;
}
$settings_before = Settings::get();
Settings::update( array( 'auto_snapshot' => true, 'safe_updates' => true, 'safe_updates_urls' => array() ) );
delete_option( SafeUpdates::STATE );
delete_transient( SnapshotService::AUTO_LOCK );

// Fixture: v1 harmless; v2 fatals on checkout only; v3 empties the checkout
// page (HTTP 200, no fatal); v4/v5 healthy; v6 fatals on one custom URL.
$GLOBALS['wpc_v1'] = "if ( false === get_option( 'wpc_shop_db' ) ) { add_option( 'wpc_shop_db', 'v1' ); }";
$v2 = "update_option( 'wpc_shop_db', 'v2' );\nadd_action( 'template_redirect', function () { if ( function_exists( 'is_checkout' ) && is_checkout() ) { wpc_shop_undefined_function(); } } );";
$v3 = "add_filter( 'the_content', function ( \$c ) { return ( function_exists( 'is_checkout' ) && is_checkout() ) ? '<p>Checkout is temporarily unavailable.</p>' : \$c; }, 1 );";
$v6 = "if ( isset( \$_GET['wpc_custom'] ) ) { wpc_shop_custom_undefined(); }";
$v7 = "add_filter( 'the_content', function ( \$c ) { return ( function_exists( 'is_cart' ) && is_cart() ) ? '<p>The cart is temporarily unavailable.</p>' : \$c; }, 1 );";
$addon = wpc_install_plugin( 'wpc-shop-addon', '1.0', $GLOBALS['wpc_v1'] );
activate_plugin( $addon );
update_option( 'wpc_shop_db', 'v1' );
wpc_plugin_zip( 'wpc-shop-addon', '2.0', $v2 );
wpc_plugin_zip( 'wpc-shop-addon', '3.0', $v3 );
wpc_plugin_zip( 'wpc-shop-addon', '4.0' );
wpc_plugin_zip( 'wpc-shop-addon', '5.0' );
wpc_plugin_zip( 'wpc-shop-addon', '6.0', $v6 );
wpc_plugin_zip( 'wpc-shop-addon', '7.0', $v7 );

// A deliberately slow page (6 s), used as a custom URL later.
wp_mkdir_p( WPMU_PLUGIN_DIR );
file_put_contents( WPMU_PLUGIN_DIR . '/wpc-slow-page.php', "<?php\nif ( isset( \$_GET['wpc_slow'] ) ) { sleep( 6 ); }\n" );

wpc_guest_trace( 'Shop pages are part of the check' ); echo "\n== Shop pages are part of the check ==\n";
$targets = SafeUpdates::targets();
foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
	wpc_check( "target wc_{$page} → wc_get_page_permalink( '{$page}' )", isset( $targets[ "wc_{$page}" ] ) && wc_get_page_permalink( $page ) === $targets[ "wc_{$page}" ]['url'], $targets[ "wc_{$page}" ]['url'] ?? 'missing' );
}
$store_before = wpc_store_rows();
$probe        = SafeUpdates::probe();
wpc_check( 'home page OK', true === ( $probe['front']['ok'] ?? null ), (string) ( $probe['front']['reason'] ?? '' ) );
foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
	$r = $probe['pages'][ "wc_{$page}" ] ?? array();
	wpc_check( "{$page}: loaded with its WooCommerce markup", true === ( $r['ok'] ?? null ) && 200 === ( $r['status'] ?? 0 ), (string) ( $r['reason'] ?? 'missing' ) );
}
wpc_check( 'empty-cart checkout is loaded, not redirected to the cart (probe mode)', 200 === ( $probe['pages']['wc_checkout']['status'] ?? 0 ) );
// The real permalink: with pretty permalinks (prod stack) ?page_id= would get
// WordPress's canonical 301 instead of WooCommerce's empty-cart 302.
$wpc_checkout = str_replace( $wpc_home, 'http://localhost', wc_get_page_permalink( 'checkout' ) );
$plain        = wp_remote_get( $wpc_checkout, array( 'redirection' => 0 ) );
wpc_check( 'a normal visitor with an empty cart is still redirected (probe mode is not global)', 302 === (int) wp_remote_retrieve_response_code( $plain ), 'HTTP ' . wp_remote_retrieve_response_code( $plain ) );
$forged = wp_remote_get( add_query_arg( 'wpc_probe', 'forgedtoken123', $wpc_checkout ), array( 'redirection' => 0 ) );
wpc_check( 'a forged probe token changes nothing', 302 === (int) wp_remote_retrieve_response_code( $forged ), 'HTTP ' . wp_remote_retrieve_response_code( $forged ) );
wpc_check( 'probes created no session, order or saved cart', wpc_store_rows() === $store_before, wp_json_encode( wpc_store_rows() ) );

wpc_guest_trace( 'Checkout broken (PHP fatal) while the home page works' ); echo "\n== Checkout broken (PHP fatal) while the home page works ==\n";
$GLOBALS['wpc_mails'] = array();
$su = wpc_update_addon( '2.0' );
wpc_check( 'baseline covered the shop pages', isset( wpc_last_auto()['meta']['safe_update']['baseline']['pages']['wc_checkout'] ) );
wpc_check( 'update undone', 'undone' === ( $su['status'] ?? '' ), ( $su['status'] ?? 'none' ) . ': ' . ( $su['message'] ?? '' ) );
wpc_check( 'only the checkout failed (home page fine)', array( 'wc_checkout' ) === ( $su['failing'] ?? null ) && true === ( $su['after']['front']['ok'] ?? null ), wp_json_encode( $su['failing'] ?? null ) );
wpc_check( 'fatal captured and the culprit named', 'wpc-shop-addon' === ( $su['culprit']['slug'] ?? '' ) && false !== strpos( (string) ( $su['error']['message'] ?? '' ), 'wpc_shop_undefined_function' ), (string) ( $su['error']['message'] ?? '' ) );
wpc_check( 'files back to 1.0, database back to v1', '1.0' === wpc_disk_version( $addon ) && 'v1' === get_option( 'wpc_shop_db' ), wpc_disk_version( $addon ) . ' / ' . get_option( 'wpc_shop_db' ) );
wpc_check( 'message and email name the checkout', false !== strpos( (string) ( $su['message'] ?? '' ), 'checkout' ) && 1 === count( $GLOBALS['wpc_mails'] ) );
wpc_check( 'the failure repeated on every check before the undo (3 probes)', 'wc_checkout' === implode( ',', $su['failing'] ?? array() ) );

wpc_guest_trace( 'Checkout renders WITHOUT its form (HTTP 200, no PHP error)' ); echo "\n== Checkout renders WITHOUT its form (HTTP 200, no PHP error) ==\n";
$su = wpc_update_addon( '3.0' );
wpc_check( 'update undone although no fatal occurred', 'undone' === ( $su['status'] ?? '' ) && null === ( $su['error'] ?? null ), ( $su['status'] ?? 'none' ) . ': ' . ( $su['reason'] ?? '' ) );
wpc_check( 'reason: the checkout content is missing', false !== strpos( (string) ( $su['reason'] ?? '' ), 'the WooCommerce checkout: HTTP 200 but the page content is missing' ), (string) ( $su['reason'] ?? '' ) );
wpc_check( 'files back to 1.0', '1.0' === wpc_disk_version( $addon ) );

wpc_guest_trace( 'Three failures per URL: a one-off error on one page is not "broken"' ); echo "\n== Three failures per URL: a one-off error on one page is not \"broken\" ==\n";
$checkout_id = wc_get_page_id( 'checkout' );
$wpc_fake    = array( 'left' => 0 );
add_filter( 'pre_http_request', $wpc_fake_filter = static function ( $pre, $args, $url ) use ( &$wpc_fake, $checkout_id ) {
	if ( $wpc_fake['left'] > 0 && false !== strpos( $url, 'wp_scrape_key=' ) && false !== strpos( $url, 'page_id=' . $checkout_id ) ) {
		$wpc_fake['left']--;
		return array( 'headers' => array(), 'body' => 'Bad gateway', 'response' => array( 'code' => 502, 'message' => 'Bad Gateway' ), 'cookies' => array(), 'filename' => null );
	}
	return $pre;
}, 10, 3 );
add_action( 'upgrader_process_complete', $wpc_arm = static function () use ( &$wpc_fake ) {
	$wpc_fake['left'] = 2; // Fails twice, works on the third check.
}, 5 );
$su = wpc_update_addon( '4.0' );
remove_action( 'upgrader_process_complete', $wpc_arm, 5 );
remove_filter( 'pre_http_request', $wpc_fake_filter, 10 );
wpc_check( 'checkout failed twice, then worked → "passed", nothing undone', 'passed' === ( $su['status'] ?? '' ) && '4.0' === wpc_disk_version( $addon ), ( $su['status'] ?? 'none' ) . ': ' . ( $su['message'] ?? '' ) );

wpc_guest_trace( 'A slow page is not a broken page' ); echo "\n== A slow page is not a broken page ==\n";
Settings::update( array( 'safe_updates_urls' => array( home_url( '/?wpc_slow=1' ) ) ) );
$su   = wpc_update_addon( '5.0' );
$slow = $su['after']['pages']['url_1'] ?? array();
wpc_check( 'custom URL took > 5 s and counted as working', true === ( $slow['ok'] ?? null ) && ( $slow['ms'] ?? 0 ) >= 5000, ( $slow['ms'] ?? 0 ) . ' ms, ' . ( $slow['reason'] ?? 'missing' ) );
wpc_check( 'update kept ("passed")', 'passed' === ( $su['status'] ?? '' ) && '5.0' === wpc_disk_version( $addon ), ( $su['status'] ?? 'none' ) . ': ' . ( $su['message'] ?? '' ) );

wpc_guest_trace( 'Custom URLs (setting + filter)' ); echo "\n== Custom URLs (setting + filter) ==\n";
Settings::update( array( 'safe_updates_urls' => array( home_url( '/?wpc_custom=1' ), 'https://evil.example/steal', home_url( '/?wpc_custom=1' ) ) ) );
wpc_check( 'setting keeps only this site\'s URLs, without duplicates', array( home_url( '/?wpc_custom=1' ) ) === Settings::get()['safe_updates_urls'], wp_json_encode( Settings::get()['safe_updates_urls'] ) );
add_filter( 'wp_chrono_safe_updates_probe_targets', $wpc_extra = static function ( $t ) {
	$t['shop_page'] = array( 'label' => 'the shop', 'url' => wc_get_page_permalink( 'shop' ) ?: home_url( '/' ), 'expect' => array() );
	$t['offsite']   = array( 'label' => 'other server', 'url' => 'https://example.org/', 'expect' => array() );
	return $t;
} );
$targets = SafeUpdates::targets();
wpc_check( 'filter adds targets; other hosts are dropped', isset( $targets['shop_page'], $targets['url_1'] ) && ! isset( $targets['offsite'] ), implode( ', ', array_keys( $targets ) ) );
remove_filter( 'wp_chrono_safe_updates_probe_targets', $wpc_extra );
$su = wpc_update_addon( '6.0' );
wpc_check( 'fatal on a custom URL only → undone', 'undone' === ( $su['status'] ?? '' ) && array( 'url_1' ) === ( $su['failing'] ?? null ), ( $su['status'] ?? 'none' ) . ': ' . ( $su['reason'] ?? '' ) );
wpc_check( 'add-on back to 5.0', '5.0' === wpc_disk_version( $addon ) );
Settings::update( array( 'safe_updates_urls' => array() ) );

wpc_guest_trace( 'Loopback blocked by the host' ); echo "\n== Loopback blocked by the host ==\n";
add_filter( 'wp_chrono_safe_updates_probe_url', $wpc_dead = static function () {
	return 'http://localhost:9/'; // Nothing listens: loopback refused.
}, 20 );
$su = wpc_update_addon( '6.0' ); // Breaks the custom URL again, but nothing can be checked.
remove_filter( 'wp_chrono_safe_updates_probe_url', $wpc_dead, 20 );
wpc_check( '"unverified", nothing undone', 'unverified' === ( $su['status'] ?? '' ) && '6.0' === wpc_disk_version( $addon ), ( $su['status'] ?? 'none' ) . ': ' . ( $su['message'] ?? '' ) );
$state = SafeUpdates::state();
wpc_check( 'loopback problem remembered with the reason', null !== $state['loopback'] && false !== strpos( (string) $state['loopback']['reason'], 'No answer' ), (string) ( $state['loopback']['reason'] ?? 'none' ) );
ob_start();
SafeUpdates::notice();
$html = (string) ob_get_clean();
wpc_check( 'admin notice explains: loopback blocked, no automatic undo, what to do', false !== strpos( $html, 'data-wp-chrono-notice="loopback"' ) && false !== strpos( $html, 'not undone automatically' ) && false !== strpos( $html, 'ask your host' ) );
wpc_addon_v1();
$su = wpc_update_addon( '4.0' );
wpc_check( 'next checkable update clears the notice', 'passed' === ( $su['status'] ?? '' ) && null === SafeUpdates::state()['loopback'], ( $su['status'] ?? 'none' ) );

wpc_guest_trace( 'Every update trigger runs the full check (checkout breaks, home page fine)' ); echo "\n== Every update trigger runs the full check (checkout breaks, home page fine) ==\n";
/** Asserts the newest auto-snapshot's check undid a checkout-only break. */
function wpc_assert_checkout_undone( string $trigger, string $addon, bool $admin = false ): void {
	$su   = wpc_trigger_result();
	$snap = wpc_last_auto();
	echo "      [{$trigger}] snapshot #" . ( $snap['id'] ?? '?' ) . ' "' . ( $snap['label'] ?? '' ) . '" checkout: ' . (string) ( $su['after']['pages']['wc_checkout']['reason'] ?? 'n/a' ) . ' | baseline: ' . (string) ( $su['baseline']['pages']['wc_checkout']['reason'] ?? 'n/a' ) . "
";
	wpc_check( "{$trigger}: shop pages in the baseline", isset( $su['baseline']['pages']['wc_checkout'], $su['baseline']['pages']['wc_cart'], $su['baseline']['pages']['wc_myaccount'] ) );
	wpc_check( "{$trigger}: checkout break detected and undone", 'undone' === ( $su['status'] ?? '' ) && array( 'wc_checkout' ) === ( $su['failing'] ?? null ) && '1.0' === wpc_disk_version( $addon ), ( $su['status'] ?? 'no check ran' ) . ' / ' . wp_json_encode( $su['failing'] ?? null ) . ' / v' . wpc_disk_version( $addon ) );
	if ( $admin ) {
		wpc_check( "{$trigger}: wp-admin checked too, with the updating admin's session", true === ( $su['after']['admin']['ok'] ?? null ), (string) ( $su['after']['admin']['reason'] ?? 'not checked' ) );
	}
}

// 1a. Dashboard, the default path: Plugins screen → "Update now" (AJAX, plugin stays active).
wpc_addon_v1();
wpc_reset_batch();
wpc_offer_update( $addon, '2.0' );
$jar    = wpc_login();
$wpc_pr = wp_remote_get( 'http://localhost:8088/wp-admin/plugins.php', array( 'cookies' => $jar, 'timeout' => 60, 'redirection' => 0 ) );
$page   = (string) wp_remote_retrieve_body( $wpc_pr );
$ajax   = preg_match( '#"ajax_nonce":"([0-9a-f]+)"#', $page, $m ) ? $m[1] : '';
wpc_check( 'dashboard (AJAX): Plugins screen ready to "Update now"', '' !== $ajax && false !== strpos( $page, 'wpc-shop-addon' ), is_wp_error( $wpc_pr ) ? $wpc_pr->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $wpc_pr ) );
$r = wp_remote_post(
	'http://localhost:8088/wp-admin/admin-ajax.php',
	array( 'cookies' => $jar, 'timeout' => 300, 'body' => array( 'action' => 'update-plugin', 'plugin' => $addon, 'slug' => 'wpc-shop-addon', '_ajax_nonce' => $ajax ) )
);
wpc_check( 'dashboard (AJAX): update request answered', 200 === (int) wp_remote_retrieve_response_code( $r ), substr( (string) wp_remote_retrieve_body( $r ), 0, 160 ) );
wpc_assert_checkout_undone( 'dashboard (AJAX)', $addon, true );
wpc_guest_trace( '1b update.php (after 1a dashboard AJAX)' );

// 1b. Dashboard without JavaScript: wp-admin/update.php deactivates the plugin,
// updates it, then reactivates it in a second request (the "activate" iframe).
wpc_addon_v1();
wpc_reset_batch();
wpc_offer_update( $addon, '2.0' );
$page = (string) wp_remote_retrieve_body( wp_remote_get( 'http://localhost:8088/wp-admin/plugins.php', array( 'cookies' => $jar, 'timeout' => 60, 'redirection' => 0 ) ) );
$link = preg_match( '#update\.php\?action=upgrade-plugin(?:&amp;|&)plugin=wpc-shop-addon%2Fwpc-shop-addon\.php(?:&amp;|&)_wpnonce=([0-9a-f]+)#', $page, $m ) ? $m[1] : '';
wpc_check( 'dashboard (update.php): Plugins screen offers the "update now" link', '' !== $link );
$body = (string) wp_remote_retrieve_body( wp_remote_get( 'http://localhost:8088/wp-admin/update.php?action=upgrade-plugin&plugin=' . rawurlencode( $addon ) . '&_wpnonce=' . $link, array( 'cookies' => $jar, 'timeout' => 300 ) ) );
wp_cache_flush();
$su = wpc_trigger_result();
wpc_check( 'dashboard (update.php): plugin deactivated for the update → check deferred, not run on a site without the new code', 'watching' === ( $su['status'] ?? '' ) && $addon === ( SafeUpdates::state()['pending']['plugin'] ?? '' ) && ! is_plugin_active( $addon ), ( $su['status'] ?? 'none' ) . ' / pending=' . wp_json_encode( SafeUpdates::state()['pending'] ) );
$amp      = '(?:&amp;|&#038;|&)'; // esc_url() writes & as &#038;.
$activate = preg_match( '~update\.php\?action=activate-plugin' . $amp . 'networkwide=?' . $amp . 'plugin=wpc-shop-addon%2Fwpc-shop-addon\.php' . $amp . '_wpnonce=([0-9a-f]+)~', $body, $m ) ? $m[1] : ''; // Not '#': $amp contains '&#038;'. WP 6.7+ writes an empty networkwide without '='.
if ( '' === $activate ) {
	echo '      update.php output: ' . substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ), -300 ) . "\n";
	preg_match_all( '~<iframe[^>]*>|[^"\'\s<>]*activate-plugin[^"\'\s<>]*~', $body, $wpc_raw );
	echo '      raw reactivation markup: ' . ( $wpc_raw[0] ? implode( ' | ', array_unique( $wpc_raw[0] ) ) : 'none (no iframe, no activate-plugin URL)' ) . "\n";
}
wpc_check( 'dashboard (update.php): WordPress asks to reactivate it', '' !== $activate );
wp_remote_get( 'http://localhost:8088/wp-admin/update.php?action=activate-plugin&networkwide=&plugin=' . rawurlencode( $addon ) . '&_wpnonce=' . $activate, array( 'cookies' => $jar, 'timeout' => 300 ) );
wpc_assert_checkout_undone( 'dashboard (update.php, after reactivation)', $addon, true );
wpc_check( 'dashboard (update.php): plugin active again on v1.0', is_plugin_active( $addon ) && '1.0' === wpc_disk_version( $addon ) );
wpc_guest_trace( '2 WP-CLI (after 1b update.php)' );

// 2. WP-CLI: a separate `wp plugin update` process.
wpc_addon_v1();
wpc_reset_batch();
wpc_offer_update( $addon, '2.0' );
exec( 'HOME=/tmp/wp-cli-home php -d memory_limit=512M /cache/wp-cli.phar' . WPC_CLI_ROOT . ' --path=' . escapeshellarg( untrailingslashit( ABSPATH ) ) . ' plugin update wpc-shop-addon 2>&1', $cli_out );
wpc_check( 'WP-CLI: `wp plugin update` ran', false !== strpos( implode( "\n", $cli_out ), 'wpc-shop-addon' ), substr( implode( ' | ', $cli_out ), 0, 160 ) );
wpc_assert_checkout_undone( 'WP-CLI', $addon );
wpc_guest_trace( '3 auto-update (after 2 WP-CLI)' );

// 3. Background auto-update: WordPress's own WP_Automatic_Updater (what WP-Cron runs).
wpc_addon_v1();
wpc_reset_batch();
wpc_offer_update( $addon, '2.0' );
delete_option( 'auto_updater.lock' );
// In its own process, as WP-Cron runs it (core's `pre_auto_update` marks the whole process).
// The lab's wp-config disables auto-updates; the child lifts that for this run only.
exec( 'HOME=/tmp/wp-cli-home php -d memory_limit=512M /cache/wp-cli.phar' . WPC_CLI_ROOT . ' --path=' . escapeshellarg( untrailingslashit( ABSPATH ) ) . ' eval ' . escapeshellarg( 'add_filter( "automatic_updater_disabled", "__return_false" ); add_filter( "auto_update_plugin", function ( $u, $i ) { return "wpc-shop-addon/wpc-shop-addon.php" === ( $i->plugin ?? "" ) ? true : $u; }, 10, 2 ); wp_maybe_auto_update(); echo "AUTO-DONE";' ) . ' 2>&1', $auto_out );
wpc_check( 'auto-update: WP_Automatic_Updater ran in its own process', false !== strpos( implode( "
", $auto_out ), 'AUTO-DONE' ), substr( implode( ' | ', $auto_out ), -160 ) );
wpc_assert_checkout_undone( 'auto-update (cron)', $addon );
wpc_guest_trace( '4 remote management (after 3 auto-update)' );

// 4. Remote-management dashboard: the upgrader runs inside a FRONT-END request.
wpc_addon_v1();
wpc_reset_batch();
wpc_offer_update( $addon, '2.0' );
update_option( 'wpc_remote_token', $wpc_token = wp_generate_password( 20, false ) );
$r = wp_remote_get( 'http://localhost:8088/?wpc_remote_update=' . $wpc_token . '&plugin=' . rawurlencode( $addon ), array( 'timeout' => 300 ) );
wpc_check( 'remote management: worker ran the update from a front-end request', false !== strpos( (string) wp_remote_retrieve_body( $r ), 'REMOTE-DONE' ), is_wp_error( $r ) ? $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $r ) . ' ' . substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $r ) ), 0, 200 ) );
wpc_assert_checkout_undone( 'remote management', $addon );
wpc_guest_trace( 'end of triggers (after 4 remote management)' );
delete_option( 'wpc_remote_token' );
delete_site_transient( 'update_plugins' );

wpc_guest_trace( 'WordPress core updates run the full check too (core update breaks checkout)' ); echo "\n== WordPress core updates run the full check too (core update breaks checkout) ==\n";
// A core "release" built from this site's own core files: version bumped, and
// default-filters.php made to fatal on the checkout only. Undo must put the
// original core back on every trigger.
$wpc_core_from = $GLOBALS['wp_version'];
$wpc_core_to   = preg_replace_callback( '/(\d+)$/', static function ( $m ) { return (string) ( (int) $m[1] + 90 ); }, $wpc_core_from );
$wpc_core_pkg  = wpc_pkg_dir() . "wordpress-{$wpc_core_to}.zip";
$zip           = new ZipArchive();
$zip->open( $wpc_core_pkg, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$root  = untrailingslashit( ABSPATH );
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $files as $file ) {
	$rel = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( wp_normalize_path( $root ) ) ), '/' );
	$top = strtok( $rel, '/' );
	if ( ! in_array( $top, array( 'wp-admin', 'wp-includes' ), true ) && ( false !== strpos( $rel, '/' ) || '.' === $rel[0] || 'wp-config.php' === $rel ) ) {
		continue; // Core only: wp-admin, wp-includes, root files incl. readme.html (update_core() checks it); never wp-config / wp-content / dotfiles.
	}
	if ( 'wp-includes/version.php' === $rel ) {
		$zip->addFromString( 'wordpress/' . $rel, str_replace( "\$wp_version = '{$wpc_core_from}';", "\$wp_version = '{$wpc_core_to}';", file_get_contents( $file->getPathname() ) ) );
	} elseif ( 'wp-includes/default-filters.php' === $rel ) {
		$zip->addFromString( 'wordpress/' . $rel, file_get_contents( $file->getPathname() ) . "\n// wpc-core-break\nadd_action( 'template_redirect', function () { if ( function_exists( 'is_checkout' ) && is_checkout() ) { wpc_core_breaks_checkout(); } } );\n" );
	} else {
		$zip->addFile( $file->getPathname(), 'wordpress/' . $rel );
	}
}
$zip->addFromString( 'wordpress/wp-content/index.php', "<?php\n// Silence is golden.\n" );
$zip->close();
wpc_check( "core package {$wpc_core_from} → {$wpc_core_to} built (breaks checkout only)", is_file( $wpc_core_pkg ) && $wpc_core_to !== $wpc_core_from, size_format( (int) @filesize( $wpc_core_pkg ) ) );

/** Offers the test core release through the update_core transient, as api.wordpress.org would. */
function wpc_offer_core( string $to, string $response ): void {
	$url = "https://packages.invalid/wordpress-{$to}.zip";
	set_site_transient(
		'update_core',
		(object) array(
			'updates'         => array(
				(object) array(
					'response' => $response, 'download' => $url, 'locale' => 'en_US', 'current' => $to, 'version' => $to,
					'packages' => (object) array( 'full' => $url, 'no_content' => false, 'new_bundled' => false, 'partial' => false, 'rollback' => false ),
					'php_version' => '7.4', 'mysql_version' => '5.5.5', 'new_bundled' => '', 'partial_version' => false,
				),
			),
			'last_checked'    => time(),
			'version_checked' => $GLOBALS['wp_version'],
			'translations'    => array(),
		)
	);
}
function wpc_core_ready(): void {
	wpc_reset_batch();
	delete_option( 'core_updater.lock' );
	delete_option( 'auto_updater.lock' );
	$state            = SafeUpdates::state();
	$state['blocked'] = array(); // Each trigger installs the same test version.
	update_option( SafeUpdates::STATE, $state, false );
	update_option( 'wpc_test_mails', array(), false );
}
/** Asserts the newest auto-snapshot's check undid the checkout-breaking core update. */
function wpc_assert_core_undone( string $trigger, string $from, string $to, bool $admin = false ): void {
	$su   = wpc_trigger_result();
	$snap = wpc_last_auto();
	echo "      [{$trigger}] snapshot #" . ( $snap['id'] ?? '?' ) . ' "' . ( $snap['label'] ?? '' ) . '" status: ' . ( $su['status'] ?? 'none' ) . ' / ' . (string) ( $su['reason'] ?? '' ) . "\n";
	wpc_check( "{$trigger}: core snapshot taken", "Before updating WordPress {$from}" === ( $snap['label'] ?? '' ), (string) ( $snap['label'] ?? 'none' ) );
	wpc_check( "{$trigger}: shop pages in the baseline", isset( $su['baseline']['pages']['wc_checkout'], $su['baseline']['pages']['wc_cart'], $su['baseline']['pages']['wc_myaccount'] ) );
	wpc_check( "{$trigger}: checkout break detected and undone", 'undone' === ( $su['status'] ?? '' ) && array( 'wc_checkout' ) === ( $su['failing'] ?? null ), ( $su['status'] ?? 'no check ran' ) . ' / ' . wp_json_encode( $su['failing'] ?? null ) );
	wpc_check( "{$trigger}: message names WordPress {$to}", false !== strpos( (string) ( $su['message'] ?? '' ), "WordPress {$to}" ), substr( (string) ( $su['message'] ?? '' ), 0, 160 ) );
	wpc_check( "{$trigger}: core files back to {$from}", FileArchive::core_version() === $from && false === strpos( (string) file_get_contents( ABSPATH . WPINC . '/default-filters.php' ), 'wpc-core-break' ), FileArchive::core_version() );
	if ( $admin ) {
		wpc_check( "{$trigger}: wp-admin checked too", true === ( $su['after']['admin']['ok'] ?? null ), (string) ( $su['after']['admin']['reason'] ?? 'not checked' ) );
	}
	sleep( 3 ); // Let PHP-FPM's OPcache see the restored core before the next trigger.
}
$wpc_cli = 'HOME=/tmp/wp-cli-home php -d memory_limit=512M /cache/wp-cli.phar' . WPC_CLI_ROOT . ' --path=' . escapeshellarg( untrailingslashit( ABSPATH ) );
wpc_addon_v1();

// 5a. WP-CLI: `wp core update <zip>`.
wpc_core_ready();
$cli_out = array();
exec( $wpc_cli . ' core update ' . escapeshellarg( $wpc_core_pkg ) . ' --version=' . escapeshellarg( $wpc_core_to ) . ' --force 2>&1', $cli_out );
wpc_check( 'core via WP-CLI: `wp core update` ran and warned about the undo', false !== strpos( implode( "\n", $cli_out ), 'Safe Updates' ), substr( implode( ' | ', $cli_out ), -200 ) );
wpc_assert_core_undone( 'core via WP-CLI', $wpc_core_from, $wpc_core_to );

// 5b. Background core auto-update (WP-Cron's wp_maybe_auto_update), own process.
wpc_core_ready();
wpc_offer_core( $wpc_core_to, 'autoupdate' );
$wpc_auto_core = 'add_filter( "automatic_updater_disabled", "__return_false" ); add_filter( "allow_minor_auto_core_updates", "__return_true" ); add_filter( "allow_major_auto_core_updates", "__return_true" ); add_filter( "auto_update_core", "__return_true", 10 ); add_filter( "pre_http_request", function ( $p, $a, $u ) { return false !== strpos( $u, "api.wordpress.org" ) ? new WP_Error( "offline", "offline" ) : $p; }, 10, 3 ); wp_maybe_auto_update(); echo "AUTO-DONE";';
// First while this version is blocked: Safe Updates must keep core's auto-updater away from it.
$state            = SafeUpdates::state();
$state['blocked'] = array( 'core:wordpress' => $wpc_core_to );
update_option( SafeUpdates::STATE, $state, false );
$auto_out = array();
exec( $wpc_cli . ' eval ' . escapeshellarg( $wpc_auto_core ) . ' 2>&1', $auto_out );
wp_cache_flush();
wpc_check( 'core via cron: a version Safe Updates undid before is not auto-installed', FileArchive::core_version() === $wpc_core_from && false !== strpos( implode( "\n", $auto_out ), 'AUTO-DONE' ), FileArchive::core_version() . ' | ' . substr( implode( ' | ', $auto_out ), -120 ) );
wpc_core_ready();
wpc_offer_core( $wpc_core_to, 'autoupdate' );
$auto_out = array();
exec( $wpc_cli . ' eval ' . escapeshellarg( $wpc_auto_core ) . ' 2>&1', $auto_out );
wpc_check( 'core via cron: WP_Automatic_Updater ran in its own process', false !== strpos( implode( "\n", $auto_out ), 'AUTO-DONE' ), substr( implode( ' | ', $auto_out ), -160 ) );
wpc_assert_core_undone( 'core via cron auto-update', $wpc_core_from, $wpc_core_to );

// 5c. Dashboard: Dashboard → Updates → "Update to version …" (update-core.php).
wpc_core_ready();
wpc_offer_core( $wpc_core_to, 'upgrade' );
$jar   = wpc_login();
$page  = (string) wp_remote_retrieve_body( wp_remote_get( 'http://localhost:8088/wp-admin/update-core.php', array( 'cookies' => $jar, 'timeout' => 60, 'redirection' => 0 ) ) );
$nonce = preg_match( '#action="update-core\.php\?action=do-core-upgrade".*?name="_wpnonce" value="([0-9a-f]+)"#s', $page, $m ) ? $m[1] : '';
wpc_check( 'core via dashboard: Updates screen offers the test release', '' !== $nonce && false !== strpos( $page, $wpc_core_to ), substr( wp_strip_all_tags( $page ), 0, 120 ) );
$r = wp_remote_post(
	'http://localhost:8088/wp-admin/update-core.php?action=do-core-upgrade',
	array( 'cookies' => $jar, 'timeout' => 300, 'redirection' => 0, 'body' => array( '_wpnonce' => $nonce, '_wp_http_referer' => '/wp-admin/update-core.php', 'version' => $wpc_core_to, 'locale' => 'en_US', 'upgrade' => 'Update now' ) )
);
wpc_check( 'core via dashboard: update screen shows why it was undone (no redirect to About)', 200 === (int) wp_remote_retrieve_response_code( $r ) && false !== strpos( (string) wp_remote_retrieve_body( $r ), 'WP Chrono Safe Updates' ), 'HTTP ' . wp_remote_retrieve_response_code( $r ) );
wp_cache_flush();
wpc_assert_core_undone( 'core via dashboard', $wpc_core_from, $wpc_core_to, true );
$mails = (array) get_option( 'wpc_test_mails', array() );
wpc_check( 'core via dashboard: admin emailed about the undo', (bool) array_filter( $mails, static function ( $m ) { return false !== strpos( (string) $m['subject'], 'undone automatically' ); } ), count( $mails ) . ' mail(s)' );
delete_site_transient( 'update_core' );
wpc_core_ready();
@unlink( $wpc_core_pkg );

wpc_guest_trace( 'Caches: every check request is cache-busting' ); echo "\n== Caches: every check request is cache-busting ==\n";
$wpc_seen = array();
add_filter( 'http_request_args', $wpc_spy = static function ( $args, $url ) use ( &$wpc_seen ) {
	if ( false !== strpos( (string) $url, 'wpc_probe=' ) ) {
		$wpc_seen[] = array( 'url' => $url, 'headers' => (array) ( $args['headers'] ?? array() ) );
	}
	return $args;
}, 10, 2 );
SafeUpdates::probe();
remove_filter( 'http_request_args', $wpc_spy, 10 );
$urls    = array_column( $wpc_seen, 'url' );
$headers = $wpc_seen[0]['headers'] ?? array();
wpc_check( 'each check request has a unique query string', count( $urls ) >= 4 && count( array_unique( $urls ) ) === count( $urls ), count( $urls ) . ' requests' );
wpc_check( 'and no-cache request headers', false !== strpos( (string) ( $headers['Cache-Control'] ?? '' ), 'no-store' ) && 'no-cache' === ( $headers['Pragma'] ?? '' ), wp_json_encode( array_intersect_key( $headers, array( 'Cache-Control' => 1, 'Pragma' => 1 ) ) ) );

// Anonymous visitors with an empty cart are redirected away from the checkout,
// so the cache test uses the cart page (cached for visitors, like a real store).
$plain = wc_get_page_permalink( 'cart' ); // Not ?page_id=: its canonical 301 would leave the :8088 loopback rewrite.
wpc_addon_v1();
update_option( 'wpc_test_cache', 'full' );
wp_remote_get( $plain );
$wpc_first = wp_remote_get( $plain );
$wpc_again = wp_remote_get( $plain );
$hit       = wp_remote_retrieve_header( $wpc_again, 'x-test-cache' );
wpc_check( 'test page cache serves the cart to visitors (HIT)', 'HIT' === $hit, 'first: HTTP ' . wp_remote_retrieve_response_code( $wpc_first ) . ' ' . wp_remote_retrieve_header( $wpc_first, 'x-test-cache' ) . ' ' . strlen( wp_remote_retrieve_body( $wpc_first ) ) . ' bytes; again: ' . wp_remote_retrieve_response_code( $wpc_again ) . ' ' . (string) $hit );
$wpc_stale = '';
add_action( 'upgrader_process_complete', $wpc_visit = static function () use ( $plain, &$wpc_stale ) {
	sleep( 3 ); // As a visitor would, once the update is live.
	$wpc_stale = (string) wp_remote_retrieve_body( wp_remote_get( $plain ) );
}, 5 );
$su = wpc_update_addon( '7.0' );
remove_action( 'upgrader_process_complete', $wpc_visit, 5 );
wpc_check( 'cache keyed by URL: after the update visitors still got the stale healthy cart…', false !== strpos( $wpc_stale, 'wp-block-woocommerce-cart' ) );
wpc_check( '…but the check bypassed it, saw the broken cart and undid the update', 'undone' === ( $su['status'] ?? '' ) && array( 'wc_cart' ) === ( $su['failing'] ?? null ), ( $su['status'] ?? 'none' ) . ': ' . ( $su['reason'] ?? '' ) );

array_map( 'unlink', glob( get_temp_dir() . 'wpc-cache-*.html' ) ?: array() );
update_option( 'wpc_test_cache', 'strip' ); // Cache key ignores unknown query parameters: can't be bypassed.
wpc_addon_v1();
wp_remote_get( $plain );
$su = wpc_update_addon( '7.0' );
wpc_check( 'cache ignoring query strings: the cart is never reported as working', array_key_exists( 'ok', $su['after']['pages']['wc_cart'] ?? array() ) && null === $su['after']['pages']['wc_cart']['ok'] && array_key_exists( 'ok', $su['baseline']['pages']['wc_cart'] ?? array() ) && null === $su['baseline']['pages']['wc_cart']['ok'], 'baseline: ' . (string) ( $su['baseline']['pages']['wc_cart']['reason'] ?? 'missing' ) . ' | after: ' . (string) ( $su['after']['pages']['wc_cart']['reason'] ?? 'missing' ) );
wpc_check( '…it is logged as skipped (served from a cache), not as passed', false !== strpos( (string) ( $su['skipped']['wc_cart'] ?? '' ), 'cache' ) && false !== strpos( (string) ( $su['message'] ?? '' ), 'Not checked' ), (string) ( $su['skipped']['wc_cart'] ?? 'not logged' ) );
delete_option( 'wpc_test_cache' );
array_map( 'unlink', glob( get_temp_dir() . 'wpc-cache-*.html' ) ?: array() );

wpc_guest_trace( 'Baseline already failing: page skipped and logged, never judged' ); echo "\n== Baseline already failing: page skipped and logged, never judged ==\n";
wpc_addon_v1();
wp_update_post( array( 'ID' => $checkout_id, 'post_password' => 'members-only' ) );
$su = wpc_update_addon( '3.0' );
wpc_check( 'password-protected checkout: skipped, nothing undone', 'passed' === ( $su['status'] ?? '' ) && isset( $su['skipped']['wc_checkout'] ) && '3.0' === wpc_disk_version( $addon ), ( $su['status'] ?? 'none' ) . ' / ' . (string) ( $su['skipped']['wc_checkout'] ?? 'not skipped' ) );
wp_update_post( array( 'ID' => $checkout_id, 'post_password' => '' ) );
wpc_addon_v1();
update_option( 'woocommerce_coming_soon', 'yes' );
update_option( 'woocommerce_store_pages_only', 'yes' );
$su = wpc_update_addon( '3.0' );
wpc_check( 'WooCommerce "coming soon" store: shop pages skipped, home page still checked', 'passed' === ( $su['status'] ?? '' ) && isset( $su['skipped']['wc_checkout'] ) && true === ( $su['after']['front']['ok'] ?? null ), ( $su['status'] ?? 'none' ) . ' / ' . (string) ( $su['skipped']['wc_checkout'] ?? 'not skipped' ) );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );

// The store switching to "coming soon" DURING an update is not a broken shop.
wpc_addon_v1();
add_action( 'upgrader_process_complete', $wpc_cs = static function () {
	update_option( 'woocommerce_coming_soon', 'yes' );
	update_option( 'woocommerce_store_pages_only', 'yes' );
}, 5 );
$su = wpc_update_addon( '4.0' );
remove_action( 'upgrader_process_complete', $wpc_cs, 5 );
wpc_check( 'store switched to "coming soon" during the update: not mistaken for a broken shop', 'passed' === ( $su['status'] ?? '' ) && '4.0' === wpc_disk_version( $addon ) && 'unavailable' === ( $su['after']['pages']['wc_checkout']['answer'] ?? '' ), ( $su['status'] ?? 'none' ) . ' / ' . (string) ( $su['after']['pages']['wc_checkout']['reason'] ?? '' ) );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );

wpc_guest_trace( 'Classic (shortcode) checkout' ); echo "\n== Classic (shortcode) checkout ==\n";
$wpc_block_checkout = get_post_field( 'post_content', $checkout_id );
$wpc_block_cart     = get_post_field( 'post_content', wc_get_page_id( 'cart' ) );
wp_update_post( array( 'ID' => $checkout_id, 'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->' ) );
wp_update_post( array( 'ID' => wc_get_page_id( 'cart' ), 'post_content' => '<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->' ) );
wpc_addon_v1();
$probe = SafeUpdates::probe();
wpc_check( 'classic checkout form found (name="checkout")', in_array( 'name="checkout"', (array) ( $probe['pages']['wc_checkout']['markers'] ?? array() ), true ), wp_json_encode( $probe['pages']['wc_checkout']['markers'] ?? null ) );
wpc_check( 'classic cart found (empty-cart message)', array() !== array_intersect( array( 'cart-empty', 'wc-empty-cart-message' ), (array) ( $probe['pages']['wc_cart']['markers'] ?? array() ) ), wp_json_encode( $probe['pages']['wc_cart']['markers'] ?? null ) );
$su = wpc_update_addon( '3.0' );
wpc_check( 'classic checkout emptied by an update → undone', 'undone' === ( $su['status'] ?? '' ) && false !== strpos( (string) ( $su['reason'] ?? '' ), 'name="checkout"' ), ( $su['status'] ?? 'none' ) . ': ' . ( $su['reason'] ?? '' ) );
wp_update_post( array( 'ID' => $checkout_id, 'post_content' => $wpc_block_checkout ) );
wp_update_post( array( 'ID' => wc_get_page_id( 'cart' ), 'post_content' => $wpc_block_cart ) );
$probe = SafeUpdates::probe();
wpc_check( 'block checkout back (wp-block-woocommerce-checkout)', in_array( 'wp-block-woocommerce-checkout', (array) ( $probe['pages']['wc_checkout']['markers'] ?? array() ), true ) );

wpc_guest_trace( 'Store data after every probe of this suite' ); echo "\n== Store data after every probe of this suite ==\n";
// Probes are anonymous: a guest session would mean a probe stored data. The
// suite itself also logs in as the admin and updates through admin-ajax.php,
// where WooCommerce keeps a session for that logged-in user: not a probe's.
global $wpdb;
$wpc_keys   = $wpdb->get_col( "SELECT session_key FROM {$wpdb->prefix}woocommerce_sessions" );
$wpc_guests = array_values( array_filter( $wpc_keys, static function ( $k ) { return ! ( ctype_digit( (string) $k ) && get_userdata( (int) $k ) ); } ) );
$wpc_rows   = wpc_store_rows();
echo '      sessions: ' . wp_json_encode( $wpc_keys ) . "\n";
wpc_check( 'no guest session was created by any probe', array() === $wpc_guests, wp_json_encode( $wpc_guests ) );
wpc_check( 'no order or saved cart was created by any probe', array( $wpc_rows['orders'], $wpc_rows['carts'], $wpc_rows['hpos_orders'] ) === array( $store_before['orders'], $store_before['carts'], $store_before['hpos_orders'] ), wp_json_encode( $wpc_rows ) . ' vs ' . wp_json_encode( $store_before ) );

wpc_guest_trace( 'A real order arrives before the break is detected' ); echo "\n== A real order arrives before the break is detected ==\n";
wpc_addon_v1();
$GLOBALS['wpc_mails'] = array();
$wpc_order_id         = 0;
add_action( 'upgrader_process_complete', $wpc_buyer = static function () use ( &$wpc_order_id ) {
	$order = wc_create_order();
	$order->set_billing_email( 'buyer@example.com' );
	$order->set_total( '19.99' );
	$order->save();
	$wpc_order_id = (int) $order->get_id();
}, 5 );
$su = wpc_update_addon( '2.0' );
remove_action( 'upgrader_process_complete', $wpc_buyer, 5 );
wpc_check( 'checkout break undone on the files-only path', 'undone' === ( $su['status'] ?? '' ) && 'files' === ( $su['mode'] ?? '' ) && '1.0' === wpc_disk_version( $addon ), ( $su['status'] ?? 'none' ) . ' / ' . ( $su['mode'] ?? '' ) . ' / ' . wp_json_encode( $su['fresh'] ?? null ) );
$wpc_order = $wpc_order_id ? wc_get_order( $wpc_order_id ) : false;
wpc_check( 'the order survived (database not rolled back)', $wpc_order && 'buyer@example.com' === $wpc_order->get_billing_email(), "order #{$wpc_order_id}" );
wpc_check( 'counted as an order, not as a post', 1 === ( $su['fresh']['new WooCommerce order(s)'] ?? 0 ) && ! isset( $su['fresh']['new post(s) or page(s)'] ), wp_json_encode( $su['fresh'] ?? null ) );
ob_start();
SafeUpdates::notice();
$html = (string) ob_get_clean();
wpc_check( 'admin notice: database kept because of the new order', false !== strpos( $html, 'NOT rolled back' ) && false !== strpos( $html, '1 new WooCommerce order' ) );
$mail = end( $GLOBALS['wpc_mails'] );
wpc_check( 'email: database kept because of the new order', 1 === count( $GLOBALS['wpc_mails'] ) && false !== strpos( (string) ( $mail['message'] ?? '' ), 'NOT rolled back' ) && false !== strpos( (string) ( $mail['message'] ?? '' ), '1 new WooCommerce order' ), (string) ( $mail['subject'] ?? 'no mail' ) );
if ( $wpc_order ) {
	$wpc_order->delete( true );
}
update_option( 'wpc_shop_db', 'v1' );

wpc_guest_trace( 'Cleanup' ); echo "\n== Cleanup ==\n";
@unlink( WPMU_PLUGIN_DIR . '/wpc-test-web.php' );
@unlink( '/tmp/wpc-web-tests.on' );
delete_option( 'wpc_test_mails' );
deactivate_plugins( array( $addon ) );
delete_plugins( array( $addon ) );
@unlink( WPMU_PLUGIN_DIR . '/wpc-slow-page.php' );
delete_option( 'wpc_shop_db' );
delete_option( SafeUpdates::STATE );
delete_transient( SnapshotService::AUTO_LOCK );
Settings::update( $settings_before );
wpc_check( 'fixtures removed, settings restored', ! is_dir( WP_PLUGIN_DIR . '/wpc-shop-addon' ) && Settings::get() == $settings_before );

echo "\n" . ( $GLOBALS['wpc_fails'] ? "{$GLOBALS['wpc_fails']} check(s) FAILED ({$GLOBALS['wpc_passes']} passed)" : "All checks passed ({$GLOBALS['wpc_passes']} passed)" ) . "\n";
