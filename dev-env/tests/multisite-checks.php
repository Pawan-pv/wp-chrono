<?php
/**
 * Milestone 2.4: strict multisite table scoping. Runs inside the throwaway
 * network built by multisite-checks.sh, once per phase:
 *
 *   sub-create   (--url=…/sub/)  subsite takes its own snapshot
 *   main         (--url=…/)      main-site snapshot / restore / delete never touch wp_2_*
 *   sub-restore  (--url=…/sub/)  subsite snapshot still intact and restorable
 *   sub-caps     (--url=…/sub/ --user=subadmin)  subsite admin cannot put code on disk
 *   super-caps   (--url=…/sub/ --user=admin)     super admin still can
 */

use WPChrono\API\RestController;
use WPChrono\Services\Database;
use WPChrono\Services\DiffService;
use WPChrono\Services\SnapshotService;

$GLOBALS['wpc_fails']  = 0;
$GLOBALS['wpc_passes'] = 0;
function wpc_check( string $label, bool $ok, string $extra = '' ): void {
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) . "\n";
	$ok ? $GLOBALS['wpc_passes']++ : $GLOBALS['wpc_fails']++;
}
function wpc_titles( string $table ): array {
	global $wpdb;
	return $wpdb->get_col( "SELECT post_title FROM {$table} WHERE post_title LIKE 'wpc-%' ORDER BY post_title" ); // phpcs:ignore
}
/**
 * @return array{0:int,1:mixed}
 */
function wpc_call( string $method, string $route, array $params = array() ): array {
	$request = new WP_REST_Request( $method, '/wp-chrono/v1/' . $route );
	$request->set_header( 'X-WP-Chrono-Nonce', wp_create_nonce( RestController::NONCE_ACTION ) );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
}
function wpc_forbidden( array $call ): bool {
	return 403 === $call[0] && 'wp_chrono_forbidden' === ( $call[1]['code'] ?? '' );
}

global $wpdb;
$phase   = $args[0] ?? '';
$service = class_exists( SnapshotService::class ) ? new SnapshotService() : null; // Gone in after-uninstall.
$main_p  = $wpdb->base_prefix;
$sub_p   = $wpdb->base_prefix . '2_';

if ( 'sub-create' === $phase ) {
	echo "== Subsite (blog 2) takes its own snapshot ==\n";
	wpc_check( 'running as subsite with prefix wp_2_', 2 === get_current_blog_id() && $sub_p === $wpdb->prefix, $wpdb->prefix );
	wp_insert_post( array( 'post_title' => 'wpc-sub-before', 'post_status' => 'publish' ) );
	$tables = array_keys( Database::site_tables() );
	wpc_check( 'subsite scope is wp_2_* only', $tables && ! array_filter( $tables, static function ( $t ) use ( $sub_p ) { return 0 !== strpos( $t, $sub_p ); } ), count( $tables ) . ' tables' );
	$snap = $service->create_and_build( 'Subsite baseline', 'manual' );
	wpc_check( 'subsite snapshot built and verified', ! is_wp_error( $snap ) && ! empty( $snap['meta']['verified'] ), is_wp_error( $snap ) ? $snap->get_error_message() : "#{$snap['id']}" );
	echo 'SUBSNAP=' . ( is_wp_error( $snap ) ? 0 : $snap['id'] ) . "\n";
}

if ( 'main' === $phase ) {
	echo "== Main site never touches wp_2_* ==\n";
	$sub_snap = (int) ( $args[1] ?? 0 );
	wpc_check( 'running as main site (blog 1, prefix = base prefix)', 1 === get_current_blog_id() && $main_p === $wpdb->prefix );
	wp_insert_post( array( 'post_title' => 'wpc-main-before', 'post_status' => 'publish' ) );

	$tables = array_keys( Database::site_tables() );
	$leak   = array_filter( $tables, static function ( $t ) use ( $main_p ) { return (bool) preg_match( '/^' . preg_quote( $main_p, '/' ) . '\d+_/', $t ); } );
	wpc_check( 'main-site table list excludes every wp_N_* subsite table', $tables && ! $leak, count( $tables ) . ' tables' . ( $leak ? ', leaked: ' . implode( ',', $leak ) : '' ) );
	wpc_check( 'network tables (wp_blogs, wp_users) stay with the main site', in_array( $wpdb->blogs, $tables, true ) && in_array( $wpdb->users, $tables, true ) );

	$snap = $service->create_and_build( 'Main baseline', 'manual' );
	$snap_tables = array_keys( $service->tables( $snap['id'] ) );
	wpc_check( 'main-site snapshot copies no wp_2_* table', ! array_filter( $snap_tables, static function ( $t ) use ( $sub_p ) { return 0 === strpos( $t, $sub_p ); } ), count( $snap_tables ) . ' tables in #' . $snap['id'] );
	wpc_check( 'snapshot IDs collide across sites (each site has its own counter)', $snap['id'] === $sub_snap, "main #{$snap['id']} / sub #{$sub_snap}" );

	// Change both sites after the snapshot, and add a network user.
	wp_insert_post( array( 'post_title' => 'wpc-main-after', 'post_status' => 'publish' ) );
	$net_user = wp_insert_user( array( 'user_login' => 'wpc-net-user', 'user_pass' => wp_generate_password(), 'user_email' => 'net@example.test' ) );
	add_user_to_blog( 2, (int) $net_user, 'editor' );
	switch_to_blog( 2 );
	wp_insert_post( array( 'post_title' => 'wpc-sub-after', 'post_status' => 'publish' ) );
	restore_current_blog();
	$sub_before = wpc_titles( $sub_p . 'posts' );

	$diff = ( new DiffService( $service ) )->compare( $snap['id'] );
	$added = array_column( $diff['tables']['added_tables'], 'table' );
	wpc_check( 'diff does not report subsite tables as "added"', ! array_filter( $added, static function ( $t ) use ( $sub_p ) { return 0 === strpos( $t, $sub_p ); } ), implode( ',', $added ) ?: 'none added' );

	$r = $service->restore( $snap['id'], true ); // drop_extra: the most aggressive mode.
	while ( ! is_wp_error( $r ) && empty( $r['done'] ) ) {
		$r = $service->restore_tick( $r['job'], INF );
	}
	wp_cache_flush();
	wpc_check( 'main-site restore (with drop_extra) finished', ! is_wp_error( $r ) && $r['done'], is_wp_error( $r ) ? $r->get_error_message() : "{$r['tables_restored']} tables, {$r['tables_removed']} dropped" );
	wpc_check( 'main site rolled back', array( 'wpc-main-before' ) === wpc_titles( $main_p . 'posts' ) );
	wpc_check( 'wp_2_posts untouched by the main-site restore', $sub_before === wpc_titles( $sub_p . 'posts' ), implode( ',', wpc_titles( $sub_p . 'posts' ) ) );
	$sub_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $sub_p ) . '%' ) );
	wpc_check( 'drop_extra did not drop any wp_2_* table', count( $sub_tables ) >= 10, count( $sub_tables ) . ' wp_2_* tables remain' );
	wpc_check( 'network tables are in the snapshot (so opting in is possible)', in_array( $wpdb->users, $snap_tables, true ) && in_array( $wpdb->blogs, $snap_tables, true ) );
	$still = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login = %s", 'wpc-net-user' ) );
	wpc_check( 'default main-site restore kept the network user added after the snapshot', (bool) $still );
	wpc_check( '…and kept the subsite membership (wp_usermeta untouched)', (bool) get_user_meta( (int) $still, $sub_p . 'capabilities', true ) );
	wpc_check( 'restore log says which network tables were kept', (bool) preg_grep( '/^Kept 7 network-wide tables untouched/', (array) $r['log'] ), (string) ( preg_grep( '/^Kept/', (array) $r['log'] ) ? current( preg_grep( '/^Kept/', (array) $r['log'] ) ) : '' ) );

	$r2 = $service->undo_update( $snap['id'], 'db', false, true );
	wp_cache_flush();
	$gone = ! $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login = %s", 'wpc-net-user' ) );
	wpc_check( 'opt-in (--include-network-tables) also rolls back network users', ! is_wp_error( $r2 ) && $gone && ! preg_grep( '/^Kept/', (array) $r2['log'] ) );
	$sub_snap_row = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $sub_p . 'wpchrono_snapshots WHERE id = %d', $sub_snap ) ); // phpcs:ignore
	wpc_check( 'opt-in restore still never touches wp_2_* tables', $sub_before === wpc_titles( $sub_p . 'posts' ) && 1 === (int) $sub_snap_row );

	$orphans = array_column( $service->scavenger()->scan()['tables'], 'name' );
	wpc_check( "main-site orphan scan spares subsite snapshot #{$sub_snap}'s copies", ! $orphans, implode( ',', $orphans ) ?: 'no orphans' );

	$service->delete( $snap['id'] );
	$sub_copies = $wpdb->get_col( $wpdb->prepare( 'SELECT snapshot_table FROM ' . $sub_p . 'wpchrono_snapshot_tables WHERE snapshot_id = %d', $sub_snap ) ); // phpcs:ignore
	$alive      = array_filter( $sub_copies, static function ( $t ) use ( $wpdb ) { return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ); } );
	wpc_check( "deleting main-site #{$snap['id']} kept subsite #{$sub_snap}'s tables", $sub_copies && count( $alive ) === count( $sub_copies ), count( $alive ) . '/' . count( $sub_copies ) . ' copies alive' );
}

if ( 'sub-restore' === $phase ) {
	echo "== Subsite snapshot still intact and restorable ==\n";
	$sub_snap = (int) ( $args[1] ?? 0 );
	$check    = $service->verify( $sub_snap );
	wpc_check( 'subsite snapshot still verifies after main-site operations', $check['verified'], implode( ' | ', $check['mismatches'] ) );
	$main_before = wpc_titles( $main_p . 'posts' );
	$r = $service->restore( $sub_snap, true );
	while ( ! is_wp_error( $r ) && empty( $r['done'] ) ) {
		$r = $service->restore_tick( $r['job'], INF );
	}
	wp_cache_flush();
	wpc_check( 'subsite restore finished', ! is_wp_error( $r ) && $r['done'], is_wp_error( $r ) ? $r->get_error_message() : "{$r['tables_restored']} tables" );
	wpc_check( 'network-activated WP Chrono not also site-activated by the restore', ! in_array( WP_CHRONO_BASENAME, (array) get_option( 'active_plugins', array() ), true ) );
	wpc_check( 'subsite rolled back (wpc-sub-after gone)', array( 'wpc-sub-before' ) === wpc_titles( $sub_p . 'posts' ), implode( ',', wpc_titles( $sub_p . 'posts' ) ) );
	wpc_check( 'main site untouched by the subsite restore', $main_before === wpc_titles( $main_p . 'posts' ) );
	wpc_check( 'network users table untouched by a subsite restore', ! in_array( $wpdb->users, array_keys( $service->tables( $sub_snap ) ), true ) );
}

if ( 'sub-caps' === $phase ) {
	echo "== Subsite admin: database yes, code on disk no ==\n";
	wpc_check( 'acting as a subsite administrator, not a super admin', current_user_can( 'manage_options' ) && ! is_super_admin() );
	$call = wpc_call( 'GET', 'status' );
	wpc_check( 'can open WP Chrono on the subsite', 200 === $call[0], "HTTP {$call[0]}" );
	wpc_check( 'blueprint import rejected', wpc_forbidden( wpc_call( 'POST', 'blueprints/import', array( 'blueprint' => '{}' ) ) ) );
	wpc_check( 'blueprint package upload rejected', wpc_forbidden( wpc_call( 'POST', 'blueprints/abcdefgh/packages' ) ) );
	wpc_check( 'blueprint apply rejected', wpc_forbidden( wpc_call( 'POST', 'blueprints/abcdefgh/apply', array( 'confirm' => 'RESET' ) ) ) );
	foreach ( array( 'files', 'full' ) as $mode ) {
		wpc_check( "restore mode={$mode} rejected", wpc_forbidden( wpc_call( 'POST', 'snapshots/999999/restore', array( 'confirm' => 'RESTORE', 'mode' => $mode ) ) ) );
	}
	wpc_check( 'database-only restore not blocked by the file gate', ! wpc_forbidden( wpc_call( 'POST', 'snapshots/999999/restore', array( 'confirm' => 'RESTORE', 'mode' => 'db' ) ) ) );
	wpc_check( 'recovery script generate rejected', wpc_forbidden( wpc_call( 'POST', 'recovery', array( 'password' => '' ) ) ) );
	wpc_check( 'recovery script delete rejected', wpc_forbidden( wpc_call( 'DELETE', 'recovery' ) ) );
	wpc_check( 'no recovery script was written', ! glob( ABSPATH . 'wp-chrono-recovery-*.php' ) );
}

if ( 'seed-uninstall' === $phase ) {
	// Everything the network uninstall must remove, on every site.
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site ) {
		switch_to_blog( (int) $site );
		WPChrono\Core\Installer::maybe_upgrade();
		WPChrono\Core\Storage::ensure();
		WPChrono\Services\Settings::update( array( 'schedule' => 'daily' ) );
		WPChrono\Services\Scheduler::sync( true );
		set_transient( 'wp_chrono_license_check', time(), HOUR_IN_SECONDS );
		set_transient( '_wpchrono_auto_snap_lock', array( 'snapshot' => 0 ), HOUR_IN_SECONDS );
		echo 'STORAGE=' . get_option( 'wp_chrono_storage_dir' ) . "\n";
		restore_current_blog();
	}
	set_site_transient( 'wp_chrono_update_info', array( 'version' => '' ), HOUR_IN_SECONDS );
}

if ( 'after-uninstall' === $phase ) {
	echo "== Network uninstall leaves nothing behind ==\n";
	$tables = $wpdb->get_col( "SHOW TABLES WHERE `Tables_in_{$wpdb->dbname}` LIKE '\\_wpc\\_%' OR `Tables_in_{$wpdb->dbname}` LIKE '%wpchrono%'" ); // phpcs:ignore
	wpc_check( 'no WP Chrono or snapshot tables on any site', ! $tables, implode( ',', $tables ) ?: 'none' );
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site ) {
		switch_to_blog( (int) $site );
		$left = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%wp\\_chrono%' OR option_name LIKE '%\\_wpchrono\\_%'" ); // phpcs:ignore
		wpc_check( "site {$site}: no options or transients left", ! $left, implode( ',', $left ) ?: 'none' );
		wpc_check( "site {$site}: no scheduled snapshot event left", false === wp_next_scheduled( 'wp_chrono_scheduled_snapshot' ) );
		restore_current_blog();
	}
	$meta = $wpdb->get_col( "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE '%wp\\_chrono%'" ); // phpcs:ignore
	wpc_check( 'no network (sitemeta) leftovers', ! $meta, implode( ',', $meta ) ?: 'none' );
	$dirs = glob( WP_CONTENT_DIR . '/wp-chrono-*', GLOB_ONLYDIR );
	wpc_check( 'every site\'s storage folder removed', ! $dirs, implode( ',', array_map( 'basename', (array) $dirs ) ) ?: 'none' );
}

if ( 'super-caps' === $phase ) {
	echo "== Super admin keeps every action ==\n";
	wpc_check( 'acting as a super admin', is_super_admin() );
	wpc_check( 'blueprint import passes the file gate', ! wpc_forbidden( wpc_call( 'POST', 'blueprints/import', array( 'blueprint' => '{}' ) ) ) );
	wpc_check( 'restore mode=full passes the file gate', ! wpc_forbidden( wpc_call( 'POST', 'snapshots/999999/restore', array( 'confirm' => 'RESTORE', 'mode' => 'full' ) ) ) );
	$call = wpc_call( 'POST', 'recovery', array( 'password' => '' ) );
	wpc_check( 'recovery script generated', 201 === $call[0], "HTTP {$call[0]}" );
	$call = wpc_call( 'DELETE', 'recovery' );
	wpc_check( 'recovery script deleted', 200 === $call[0] && ! glob( ABSPATH . 'wp-chrono-recovery-*.php' ), "HTTP {$call[0]}" );
}

echo "\n" . ( $GLOBALS['wpc_fails'] ? "{$GLOBALS['wpc_fails']} check(s) FAILED ({$GLOBALS['wpc_passes']} passed)" : "All checks passed ({$GLOBALS['wpc_passes']} passed)" ) . "\n";
