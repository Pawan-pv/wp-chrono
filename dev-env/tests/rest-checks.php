<?php
/**
 * In-process checks for WP Chrono's REST routes and reset engine.
 *
 *   docker compose run --rm cli eval-file /tests/rest-checks.php --user=chrono
 *
 * DESTRUCTIVE: seeds junk data, then runs every cleaner and a full reset.
 * Only run against the disposable dev-env stack.
 */

use WPChrono\API\RestController;

$GLOBALS['wpc_fails'] = 0;

function wpc_check( string $label, bool $ok, string $extra = '' ): void {
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) . "\n";
	if ( ! $ok ) {
		$GLOBALS['wpc_fails']++;
	}
}

/**
 * @return array{0:int,1:mixed}
 */
function wpc_call( string $method, string $route, array $params = array(), $nonce = true ): array {
	$request = new WP_REST_Request( $method, '/wp-chrono/v1/' . $route );
	if ( true === $nonce ) {
		$request->set_header( 'X-WP-Chrono-Nonce', wp_create_nonce( RestController::NONCE_ACTION ) );
	} elseif ( is_string( $nonce ) ) {
		$request->set_header( 'X-WP-Chrono-Nonce', $nonce );
	}
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
}

/** Runs a partial reset to completion through REST, following the cursor. */
function wpc_run( string $target, array $params = array() ): array {
	$cursor = 0;
	$all    = array( 'processed' => 0, 'log' => array() );
	for ( $i = 0; $i < 100; $i++ ) {
		list( $status, $data ) = wpc_call( 'POST', "reset/partial/{$target}", $params + array( 'confirm' => 'RESET', 'cursor' => $cursor ) );
		if ( 200 !== $status ) {
			return array( 'status' => $status, 'data' => $data );
		}
		$all['processed'] += $data['processed'];
		$all['log']        = array_merge( $all['log'], $data['log'] );
		$cursor            = $data['cursor'];
		if ( $data['done'] ) {
			return $all + array( 'status' => 200 );
		}
	}
	return $all + array( 'status' => 0 );
}

global $wpdb;
$admin_id = get_current_user_id();
echo "Acting admin ID: {$admin_id}\n\n";

// ---------------------------------------------------------------------------
echo "== Security ==\n";
wp_set_current_user( 0 );
list( $status ) = wpc_call( 'GET', 'status' );
wpc_check( 'anonymous request rejected', in_array( $status, array( 401, 403 ), true ), "HTTP {$status}" );
wp_set_current_user( $admin_id );

list( $status, $data ) = wpc_call( 'GET', 'status', array(), false );
wpc_check( 'missing chrono nonce rejected', 403 === $status && 'wp_chrono_invalid_nonce' === ( $data['code'] ?? '' ), "HTTP {$status}" );

list( $status ) = wpc_call( 'GET', 'status', array(), 'forged' );
wpc_check( 'forged chrono nonce rejected', 403 === $status, "HTTP {$status}" );

$subscriber = wp_insert_user( array( 'user_login' => 'sub_' . wp_generate_password( 4, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $subscriber );
list( $status ) = wpc_call( 'GET', 'status' );
wpc_check( 'subscriber with valid nonce rejected', 403 === $status, "HTTP {$status}" );
wp_set_current_user( $admin_id );

list( $status, $data ) = wpc_call( 'POST', 'reset/partial/transients' );
wpc_check( 'destructive call without confirm rejected', 400 === $status && 'wp_chrono_unconfirmed' === ( $data['code'] ?? '' ), "HTTP {$status}" );

list( $status ) = wpc_call( 'POST', 'reset/partial/not_a_target', array( 'confirm' => 'RESET' ) );
wpc_check( 'unknown target rejected', in_array( $status, array( 400, 404 ), true ), "HTTP {$status}" );

// DISALLOW_FILE_MODS: core routes it through this filter, for capabilities too.
add_filter( 'file_mod_allowed', '__return_false' );
$forbidden = static function ( array $call ): bool {
	return 403 === $call[0] && 'wp_chrono_forbidden' === ( $call[1]['code'] ?? '' );
};
wpc_check( 'file mods off: blueprint import rejected', $forbidden( wpc_call( 'POST', 'blueprints/import', array( 'blueprint' => '{}' ) ) ) );
wpc_check( 'file mods off: blueprint apply rejected', $forbidden( wpc_call( 'POST', 'blueprints/abcdefgh/apply', array( 'confirm' => 'RESET' ) ) ) );
wpc_check( 'file mods off: file restore rejected', $forbidden( wpc_call( 'POST', 'snapshots/999999/restore', array( 'confirm' => 'RESTORE', 'mode' => 'full' ) ) ) );
wpc_check( 'file mods off: database restore still allowed', ! $forbidden( wpc_call( 'POST', 'snapshots/999999/restore', array( 'confirm' => 'RESTORE', 'mode' => 'db' ) ) ) );
wpc_check( 'file mods off: recovery script not written', $forbidden( wpc_call( 'POST', 'recovery', array( 'password' => '' ) ) ) );
remove_filter( 'file_mod_allowed', '__return_false' );

// ---------------------------------------------------------------------------
echo "\n== Status & preview ==\n";
list( $status, $data ) = wpc_call( 'GET', 'status' );
wpc_check( 'GET /status', 200 === $status && $data['database']['table_count'] > 0, "{$data['database']['table_count']} tables" );
wpc_check( 'storage sealed', true === $data['storage']['protected'] );

// Seed junk for every cleaner.
set_transient( 'wpc_test_transient', str_repeat( 'x', 500 ), HOUR_IN_SECONDS );
set_site_transient( 'wpc_test_site_transient', 'y', HOUR_IN_SECONDS );
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => 987654, 'meta_key' => 'wpc_orphan', 'meta_value' => 'x' ) );
$wpdb->insert( $wpdb->commentmeta, array( 'comment_id' => 987654, 'meta_key' => 'wpc_orphan', 'meta_value' => 'x' ) );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpc_parent (id INT PRIMARY KEY) ENGINE=InnoDB" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpc_child (id INT PRIMARY KEY, parent_id INT, FOREIGN KEY (parent_id) REFERENCES {$wpdb->prefix}wpc_parent(id)) ENGINE=InnoDB" );
$wpdb->query( "INSERT IGNORE INTO {$wpdb->prefix}wpc_parent VALUES (1),(2)" );
$wpdb->query( "INSERT IGNORE INTO {$wpdb->prefix}wpc_child VALUES (1,1),(2,2)" );
$uploads = wp_get_upload_dir()['basedir'];
wp_mkdir_p( $uploads . '/2026/10' );
file_put_contents( $uploads . '/2026/10/wpc-test.txt', 'hello' );
file_put_contents( $uploads . '/.htaccess', "# keep me\n" );
mkdir( $uploads . '/wp-chrono-keepme' );
file_put_contents( $uploads . '/wp-chrono-keepme/data.txt', 'keep' );
add_option( 'wpc_junk_option', 'junk' );

list( $status, $data ) = wpc_call( 'GET', 'reset/preview' );
wpc_check( 'GET /reset/preview', 200 === $status && 8 === count( $data['targets'] ) );
$t = $data['targets'];
wpc_check( 'preview: transients found', $t['transients']['items'] >= 2, (string) $t['transients']['items'] );
wpc_check( 'preview: orphaned meta found', $t['orphaned_meta']['items'] >= 2, (string) $t['orphaned_meta']['items'] );
wpc_check( 'preview: custom tables found', count( $t['custom_tables']['details'] ) >= 2, implode( ', ', $t['custom_tables']['details'] ) );
wpc_check( 'preview: uploads found', $t['uploads']['items'] >= 1, (string) $t['uploads']['items'] );
wpc_check( 'preview: inactive themes found', $t['inactive_themes']['items'] >= 1, implode( ', ', $t['inactive_themes']['details'] ) );
wpc_check( 'preview: WooCommerce unavailable without data', false === $t['woocommerce']['available'], (string) $t['woocommerce']['reason'] );

list( $status, $data ) = wpc_call( 'POST', 'reset/partial/transients', array( 'dry_run' => true ) );
wpc_check( 'dry run returns preview, changes nothing', 200 === $status && $data['items'] >= 2 && false !== get_transient( 'wpc_test_transient' ) );

list( $status ) = wpc_call( 'POST', 'reset/partial/woocommerce', array( 'confirm' => 'RESET' ) );
wpc_check( 'unavailable target returns 409', 409 === $status, "HTTP {$status}" );

// ---------------------------------------------------------------------------
echo "\n== Partial cleaners ==\n";
list( $status, $data ) = wpc_call( 'POST', 'reset/preflight', array( 'target' => 'transients', 'snapshot' => true ) );
wpc_check( 'preflight logs state + snapshot hook', 200 === $status && 2 === count( $data['log'] ), $data['log'][1] ?? '' );

$r = wpc_run( 'transients' );
wp_cache_flush();
wpc_check( 'transients purged', 200 === $r['status'] && false === get_transient( 'wpc_test_transient' ) && false === get_site_transient( 'wpc_test_site_transient' ), "{$r['processed']} rows" );

$r       = wpc_run( 'orphaned_meta' );
$orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'wpc_orphan'" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE meta_key = 'wpc_orphan'" );
wpc_check( 'orphaned meta purged', 200 === $r['status'] && 0 === $orphans, "{$r['processed']} rows" );

$r     = wpc_run( 'custom_tables', array( 'mode' => 'truncate' ) );
$rows  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpc_parent" );
$fails = array_filter( $r['log'], static function ( $l ) { return 0 === strpos( $l, 'Failed' ); } );
wpc_check( 'custom tables truncated despite FK constraint', 200 === $r['status'] && 0 === $rows && ! $fails, implode( ' | ', $fails ) );

$r      = wpc_run( 'custom_tables', array( 'mode' => 'drop' ) );
$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wpc_parent' ) );
wpc_check( 'custom tables dropped', 200 === $r['status'] && null === $exists );
wpc_check( 'FOREIGN_KEY_CHECKS restored to 1', '1' === (string) $wpdb->get_var( 'SELECT @@FOREIGN_KEY_CHECKS' ) );

$r = wpc_run( 'uploads' );
wpc_check( 'uploads file deleted', 200 === $r['status'] && ! file_exists( $uploads . '/2026/10/wpc-test.txt' ), "{$r['processed']} files" );
wpc_check( 'uploads .htaccess kept', file_exists( $uploads . '/.htaccess' ) );
wpc_check( 'uploads wp-chrono-* dir kept', file_exists( $uploads . '/wp-chrono-keepme/data.txt' ) );

$r = wpc_run( 'inactive_themes' );
// wp_get_themes() caches in-process, so check the theme folders on disk.
$theme_dirs = array_values( array_diff( scandir( get_theme_root() ), array( '.', '..', 'index.php' ) ) );
wpc_check( 'inactive themes deleted', 200 === $r['status'] && array( get_stylesheet() ) === $theme_dirs, implode( ', ', $theme_dirs ) );

$r = wpc_run( 'inactive_plugins' );
wp_clean_plugins_cache();
$left = array_keys( get_plugins() );
wpc_check( 'inactive plugins deleted, WP Chrono kept', 200 === $r['status'] && array( WP_CHRONO_BASENAME ) === $left, implode( ', ', $left ) );

$siteurl = get_option( 'siteurl' );
$r       = wpc_run( 'options' );
wp_cache_flush();
wpc_check( 'options reset removed junk', 200 === $r['status'] && false === get_option( 'wpc_junk_option' ), "{$r['processed']} options" );
wpc_check( 'options reset kept siteurl + WP Chrono state', get_option( 'siteurl' ) === $siteurl && false !== get_option( 'wp_chrono_db_version' ) && in_array( WP_CHRONO_BASENAME, (array) get_option( 'active_plugins' ), true ) );
wpc_check( 'options reset restored defaults', '10' === (string) get_option( 'posts_per_page' ) && current_user_can( 'manage_options' ) );

// ---------------------------------------------------------------------------
echo "\n== Full reset ==\n";
$before_pass = $wpdb->get_var( $wpdb->prepare( "SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", $admin_id ) );
$manager     = WP_Session_Tokens::get_instance( $admin_id );
$token       = $manager->create( time() + HOUR_IN_SECONDS );
$blogname    = get_option( 'blogname' );
$bob         = wp_insert_user( array( 'user_login' => 'bob', 'user_pass' => 'x', 'role' => 'editor' ) );
wp_insert_post( array( 'post_title' => 'WPC doomed post', 'post_status' => 'publish' ) );
$wpdb->query( "CREATE TABLE IF NOT EXISTS `_wpc_1_deadbeef_wp_options` LIKE {$wpdb->options}" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpc_plugin_table (id INT PRIMARY KEY) ENGINE=InnoDB" );

list( $status, $data ) = wpc_call( 'POST', 'reset/full', array( 'confirm' => 'nope' ) );
wpc_check( 'full reset without RESET rejected', 400 === $status );

list( $status, $data ) = wpc_call( 'POST', 'reset/full', array( 'confirm' => 'RESET', 'keep_theme' => true ) );
wp_cache_flush();
wpc_check( 'POST /reset/full', 200 === $status, 200 === $status ? "{$data['tables_dropped']} tables in {$data['duration_ms']} ms" : wp_json_encode( $data ) );
if ( 200 === $status ) {
	foreach ( $data['steps'] as $step ) {
		echo "        {$step['ms']} ms  {$step['label']}\n";
	}
}

$after = $wpdb->get_row( $wpdb->prepare( "SELECT ID, user_pass FROM {$wpdb->users} WHERE ID = %d", $admin_id ) );
wpc_check( 'admin keeps original ID', null !== $after, "ID {$admin_id}" );
wpc_check( 'admin keeps password hash', $after && $after->user_pass === $before_pass );
wpc_check( 'admin session token survives', null !== WP_Session_Tokens::get_instance( $admin_id )->get( $token ) );
wpc_check( 'admin is administrator', user_can( $admin_id, 'manage_options' ) );
wpc_check( 'other users removed', false === get_user_by( 'login', 'bob' ) );
wpc_check( 'content removed', null === $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_title = 'WPC doomed post'" ) );
wpc_check( 'custom table dropped', null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wpc_plugin_table' ) ) );
wpc_check( 'snapshot table _wpc_* preserved', '_wpc_1_deadbeef_wp_options' === $wpdb->get_var( "SHOW TABLES LIKE '\\_wpc\\_1\\_deadbeef\\_wp\\_options'" ) );
wpc_check( 'tracking tables preserved', null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wpchrono_snapshots' ) ) );
wpc_check( 'blogname + siteurl preserved', get_option( 'blogname' ) === $blogname && get_option( 'siteurl' ) === $siteurl );
wpc_check( 'WP Chrono still active + options kept', array( WP_CHRONO_BASENAME ) === get_option( 'active_plugins' ) && false !== get_option( 'wp_chrono_db_version' ) && false !== get_option( 'wp_chrono_storage_dir' ) );
wpc_check( 'WP Chrono options keep autoload=off', in_array( $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'wp_chrono_db_version'" ), array( 'no', 'off' ), true ) );

$wpdb->query( 'DROP TABLE IF EXISTS `_wpc_1_deadbeef_wp_options`' );

echo "\n" . ( $GLOBALS['wpc_fails'] ? "{$GLOBALS['wpc_fails']} check(s) FAILED" : 'All checks passed' ) . "\n";
