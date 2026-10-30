<?php
/**
 * Phase 3 checks: chunked snapshots, diff, atomic restore, export, scavenger,
 * batch-aware auto-snapshots.
 *
 *   docker compose exec wp wp eval-file /tests/snapshot-checks.php --user=chrono
 *
 * DESTRUCTIVE. Only run against the disposable dev-env stack.
 */

use WPChrono\API\RestController;
use WPChrono\Core\Schema;
use WPChrono\Core\Storage;
use WPChrono\Services\ResetService;
use WPChrono\Services\Settings;
use WPChrono\Services\SnapshotService;

$GLOBALS['wpc_fails'] = 0;

function wpc_check( string $label, bool $ok, string $extra = '' ): void {
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) . "\n";
	if ( ! $ok ) {
		$GLOBALS['wpc_fails']++;
	}
}

function wpc_call( string $method, string $route, array $params = array() ): array {
	$request = new WP_REST_Request( $method, '/wp-chrono/v1/' . $route );
	$request->set_header( 'X-WP-Chrono-Nonce', wp_create_nonce( RestController::NONCE_ACTION ) );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
}

function wpc_table_exists( string $name ): bool {
	global $wpdb;
	return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
}

global $wpdb;
$service  = new SnapshotService();
$admin_id = get_current_user_id();
$p        = $wpdb->prefix;

// Leftovers from a previous run.
foreach ( array( 'wpc_fk_child', 'wpc_fk_parent', 'wpc_nopk', 'wpc_composite', 'wpc_after' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$p}{$t}" );
}
$long = substr( $p . 'chrono_extremely_long_plugin_table_name_for_truncation_checks_xyz', 0, 64 );
$wpdb->query( "DROP TABLE IF EXISTS `{$long}`" );

// ---------------------------------------------------------------------------
echo "== Seed ==\n";
$post_id = wp_insert_post( array( 'post_title' => 'WPC meta host', 'post_status' => 'publish' ) );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'wpc_bulk'" );
$values = array();
for ( $i = 0; $i < 12000; $i++ ) {
	$values[] = $wpdb->prepare( '(%d,%s,%s)', $post_id, 'wpc_bulk', 'v' . $i );
}
foreach ( array_chunk( $values, 2000 ) as $chunk ) {
	$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $chunk ) );
}
$wpdb->query( "CREATE TABLE {$p}wpc_nopk (a INT, b VARCHAR(10)) ENGINE=InnoDB" );
$wpdb->query( "INSERT INTO {$p}wpc_nopk VALUES (1,'x'),(2,'y'),(3,'z')" );
$wpdb->query( "CREATE TABLE {$p}wpc_composite (a INT, b VARCHAR(20), v INT, PRIMARY KEY (a,b)) ENGINE=InnoDB" );
$rows = array();
for ( $i = 0; $i < 7000; $i++ ) {
	$rows[] = '(' . ( $i % 70 ) . ",'k" . $i . "'," . $i . ')';
}
$wpdb->query( "INSERT INTO {$p}wpc_composite VALUES " . implode( ',', $rows ) );
$wpdb->query( "CREATE TABLE {$p}wpc_fk_parent (id INT PRIMARY KEY) ENGINE=InnoDB" );
$wpdb->query( "CREATE TABLE {$p}wpc_fk_child (id INT PRIMARY KEY, parent_id INT, CONSTRAINT wpc_fk_cascade FOREIGN KEY (parent_id) REFERENCES {$p}wpc_fk_parent (id) ON DELETE CASCADE) ENGINE=InnoDB" );
$wpdb->query( "INSERT INTO {$p}wpc_fk_parent VALUES (1),(2)" );
$wpdb->query( "INSERT INTO {$p}wpc_fk_child VALUES (10,1),(20,2)" );
$wpdb->query( "CREATE TABLE `{$long}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, v INT) ENGINE=InnoDB COMMENT='original comment'" );
$wpdb->query( "INSERT INTO `{$long}` (v) VALUES (1),(2)" );
update_option( 'wpc_diff_probe', 'before' );
update_option( 'wpc_deleted', 'gone soon' );
delete_option( 'wpc_added' );
$blogname    = get_option( 'blogname' );
$live_meta   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" );
$posts_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
echo "  seeded: {$live_meta} postmeta rows, 7000 composite-key rows, no-PK table, 64-char table\n";

// ---------------------------------------------------------------------------
echo "\n== Chunked snapshot creation ==\n";
list( $status, $data ) = wpc_call( 'POST', 'snapshots', array( 'label' => 'Phase 3 baseline', 'note' => 'test' ) );
wpc_check( 'POST /snapshots registers a building snapshot', 201 === $status && 'building' === $data['snapshot']['status'], "HTTP {$status}" );
$id = (int) $data['snapshot']['id'];

$ticks = 0;
do {
	$progress = $service->build( $id, 0.0 ); // Zero budget: one batch per tick, maximal resumption.
	$ticks++;
} while ( ! is_wp_error( $progress ) && ! $progress['done'] && $ticks < 500 );
wpc_check( 'build resumes across many ticks', ! is_wp_error( $progress ) && $progress['done'] && $ticks > 5, "{$ticks} ticks" );

$snap   = $service->get( $id );
$tables = $service->tables( $id );
wpc_check( 'snapshot status ready', 'ready' === $snap['status'] );
wpc_check( 'postmeta copied exactly (keyset)', (int) $tables[ $wpdb->postmeta ]['row_count'] === $live_meta && 'keyset' === $tables[ $wpdb->postmeta ]['copy_mode'], "{$tables[ $wpdb->postmeta ]['row_count']} / {$live_meta}" );
wpc_check( 'composite-key table copied (composite keyset)', 7000 === (int) $tables[ "{$p}wpc_composite" ]['row_count'] && 'composite' === $tables[ "{$p}wpc_composite" ]['copy_mode'] );
wpc_check( 'no-PK table copied (single)', 3 === (int) $tables[ "{$p}wpc_nopk" ]['row_count'] && 'single' === $tables[ "{$p}wpc_nopk" ]['copy_mode'] );
wpc_check( 'size + schema hash recorded', $snap['size_bytes'] > 0 && 32 === strlen( $tables[ $wpdb->posts ]['schema_hash'] ) );

$names_ok = true;
foreach ( $tables as $orig => $row ) {
	$names_ok = $names_ok && strlen( $row['snapshot_table'] ) <= 64 && 0 === strpos( $row['snapshot_table'], "_wpc_{$id}_" );
}
wpc_check( 'all snapshot table names <= 64 chars with _wpc_{id}_ tag', $names_ok );
$long_copy = $tables[ $long ]['snapshot_table'] ?? '';
$comment   = $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', $wpdb->dbname, $long_copy ) );
$decoded   = json_decode( (string) $comment, true );
wpc_check( '64-char original truncated safely, COMMENT carries full name', 64 === strlen( $long_copy ) && $long === ( $decoded['orig'] ?? '' ) && 1 === ( $decoded['wpc'] ?? 0 ), $long_copy );
wpc_check( 'original table comment remembered', 'original comment' === $tables[ $long ]['orig_comment'] );
$fk_row     = $tables[ "{$p}wpc_fk_child" ];
$fk_comment = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', $wpdb->dbname, $fk_row['snapshot_table'] ) ), true );
wpc_check( 'foreign key recorded in tracking table + COMMENT', false !== strpos( (string) $fk_row['foreign_keys'], 'ON DELETE CASCADE' ) && false !== strpos( (string) ( $fk_comment['fk'][0] ?? '' ), 'wpc_fk_cascade' ) );
wpc_check( 'snapshot tables excluded from live table list', ! array_filter( array_keys( WPChrono\Services\Database::site_tables() ), array( Schema::class, 'is_snapshot_table' ) ) );

// ---------------------------------------------------------------------------
echo "\n== Diff ==\n";
update_option( 'wpc_diff_probe', 'after' );
add_option( 'wpc_added', 'new value' );
delete_option( 'wpc_deleted' );
update_option( 'blogname', 'Changed Title' );
set_transient( 'wpc_noise', 'ignored', 300 );
for ( $i = 0; $i < 5; $i++ ) {
	wp_insert_post( array( 'post_title' => "After snapshot {$i}", 'post_status' => 'draft' ) );
}
$wpdb->query( "CREATE TABLE {$p}wpc_after (id INT PRIMARY KEY) ENGINE=InnoDB" );
$wpdb->query( "DROP TABLE {$p}wpc_nopk" );
$wpdb->query( "ALTER TABLE {$p}wpc_composite ADD COLUMN extra INT NULL" );
Settings::update( array( 'auto_retention' => 7 ) ); // Must survive the restore below.
$wpdb->query( "ALTER TABLE {$p}wpc_fk_child DROP FOREIGN KEY wpc_fk_cascade" );

list( $status, $diff ) = wpc_call( 'GET', "snapshots/{$id}/diff" );
wpc_check( 'GET /snapshots/{id}/diff', 200 === $status, "{$diff['duration_ms']} ms" );
$by_name = static function ( array $list ) {
	return array_column( $list, null, 'name' );
};
$mod = $by_name( $diff['options']['modified'] );
$add = $by_name( $diff['options']['added'] );
$del = $by_name( $diff['options']['deleted'] );
wpc_check( 'option modified: old/new values', isset( $mod['wpc_diff_probe'] ) && 'before' === $mod['wpc_diff_probe']['old_value'] && 'after' === $mod['wpc_diff_probe']['new_value'] );
wpc_check( 'blogname labelled "Site title"', isset( $mod['blogname'] ) && 'Site title' === $mod['blogname']['label'], $mod['blogname']['summary'] ?? '' );
wpc_check( 'option added detected', isset( $add['wpc_added'] ) );
wpc_check( 'option deleted detected', isset( $del['wpc_deleted'] ) );
wpc_check( 'transients + wp_chrono_* excluded', ! isset( $add['_transient_wpc_noise'] ) && ! isset( $mod['wp_chrono_settings'] ) && ! isset( $add['wp_chrono_settings'] ) );

$tbl      = $diff['tables'];
$modified = array_column( $tbl['modified_tables'], null, 'table' );
wpc_check( 'added table detected', in_array( "{$p}wpc_after", array_column( $tbl['added_tables'], 'table' ), true ) );
wpc_check( 'removed table detected', in_array( "{$p}wpc_nopk", array_column( $tbl['removed_tables'], 'table' ), true ) );
wpc_check( 'wp_posts +5 rows', isset( $modified[ $wpdb->posts ] ) && 5 === $modified[ $wpdb->posts ]['rows_delta'], (string) ( $modified[ $wpdb->posts ]['rows_delta'] ?? 'n/a' ) );
wpc_check( 'schema change detected', ! empty( $modified[ "{$p}wpc_composite" ]['schema_changed'] ) && $modified[ "{$p}wpc_composite" ]['schema_diff']['added'] );
wpc_check( 'same-count content edit detected via checksum', ! empty( $modified[ $wpdb->options ]['content_changed'] ) || 0 !== ( $modified[ $wpdb->options ]['rows_delta'] ?? 0 ) );
wpc_check( 'unchanged tables counted', $tbl['unchanged_tables'] > 0, (string) $tbl['unchanged_tables'] );

// ---------------------------------------------------------------------------
echo "\n== Atomic restore ==\n";
$token       = WP_Session_Tokens::get_instance( $admin_id )->create( time() + HOUR_IN_SECONDS );
$salt_before = get_option( 'nonce_salt' );

list( $status ) = wpc_call( 'POST', "snapshots/{$id}/restore", array( 'confirm' => 'nope' ) );
wpc_check( 'restore without RESTORE rejected', 400 === $status );

list( $status, $tick ) = wpc_call( 'POST', "snapshots/{$id}/restore", array( 'confirm' => 'RESTORE', 'drop_extra' => true ) );
wpc_check( 'restore job started', 200 === $status && 'staging' === ( $tick['phase'] ?? '' ), "HTTP {$status}" );
$n = 0;
while ( 200 === $status && empty( $tick['done'] ) && $n++ < 200 ) {
	list( $status, $tick ) = wpc_call( 'POST', 'snapshots/restore/tick', array( 'job' => $tick['job'], 'confirm' => 'RESTORE' ) );
}
wp_cache_flush();
wpc_check( 'restore finished with atomic swap', 200 === $status && ! empty( $tick['done'] ), 200 === $status ? "swap {$tick['swap_ms']} ms, {$tick['rows_copied']} rows" : wp_json_encode( $tick ) );
wpc_check( 'options rolled back', 'before' === get_option( 'wpc_diff_probe' ) && false === get_option( 'wpc_added' ) && 'gone soon' === get_option( 'wpc_deleted' ) && get_option( 'blogname' ) === $blogname );
wpc_check( 'rows rolled back', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) === $posts_count && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ) === $live_meta );
wpc_check( 'dropped table restored with data', 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpc_nopk" ) );
wpc_check( 'schema rolled back', null === $wpdb->get_var( "SHOW COLUMNS FROM {$p}wpc_composite LIKE 'extra'" ) );
wpc_check( 'extra table dropped (drop_extra)', ! wpc_table_exists( "{$p}wpc_after" ) );
wpc_check( 'original table comment restored', 'original comment' === $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', $wpdb->dbname, $long ) ) );
wpc_check( 'admin session token survives restore', null !== WP_Session_Tokens::get_instance( $admin_id )->get( $token ) );
wpc_check( 'DB-stored salts preserved', get_option( 'nonce_salt' ) === $salt_before );
wpc_check( 'WP Chrono settings survive restore', 7 === Settings::get()['auto_retention'] );
wpc_check( 'snapshot not consumed', 'ready' === $service->get( $id )['status'] && wpc_table_exists( $tables[ $wpdb->posts ]['snapshot_table'] ) );
$leftovers = $wpdb->get_col( "SHOW TABLES LIKE '\\_wpc\\_stg\\_%'" ) + $wpdb->get_col( "SHOW TABLES LIKE '\\_wpc\\_old\\_%'" );
wpc_check( 'no staging / parked tables left', ! $leftovers, implode( ', ', $leftovers ) );
$rc = $wpdb->get_row( $wpdb->prepare( 'SELECT DELETE_RULE, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s', $wpdb->dbname, "{$p}wpc_fk_child", 'wpc_fk_cascade' ), ARRAY_A );
wpc_check( 'foreign key re-created after restore', $rc && 'CASCADE' === $rc['DELETE_RULE'] && "{$p}wpc_fk_parent" === $rc['REFERENCED_TABLE_NAME'], wp_json_encode( $rc ) );
$wpdb->query( "DELETE FROM {$p}wpc_fk_parent WHERE id = 1" );
wpc_check( 'ON DELETE CASCADE works after restore', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpc_fk_child WHERE parent_id = 1" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpc_fk_child" ) );
$wpdb->query( "INSERT INTO {$p}wpc_fk_child VALUES (30, 999)" );
wpc_check( 'FK rejects orphan child rows after restore', '' !== $wpdb->last_error );
wpc_check( 'FK re-creation logged', (bool) preg_grep( '/Re-created \d+ foreign key/', $tick['log'] ), implode( ' | ', $tick['log'] ) );
wpc_check( 'FOREIGN_KEY_CHECKS back to 1', '1' === (string) $wpdb->get_var( 'SELECT @@FOREIGN_KEY_CHECKS' ) );

// An admin created after the snapshot must not be locked out by restoring it.
$late = wp_insert_user( array( 'user_login' => 'latecomer_' . wp_generate_password( 4, false ), 'user_pass' => 'x', 'role' => 'administrator' ) );
wp_set_current_user( $late );
$late_token = WP_Session_Tokens::get_instance( $late )->create( time() + HOUR_IN_SECONDS );
list( $status, $tick ) = wpc_call( 'POST', "snapshots/{$id}/restore", array( 'confirm' => 'RESTORE' ) );
while ( 200 === $status && empty( $tick['done'] ) ) {
	list( $status, $tick ) = wpc_call( 'POST', 'snapshots/restore/tick', array( 'job' => $tick['job'], 'confirm' => 'RESTORE' ) );
}
wp_cache_flush();
clean_user_cache( $late );
wpc_check(
	'admin missing from snapshot is re-created with session',
	200 === $status && user_can( $late, 'manage_options' ) && null !== WP_Session_Tokens::get_instance( $late )->get( $late_token ),
	implode( ' | ', $tick['log'] ?? array() )
);
wp_set_current_user( $admin_id );

// ---------------------------------------------------------------------------
echo "\n== Export ==\n";
$job = '';
do {
	$export = $service->export_tick( $id, $job, 0.0 );
	$job    = is_wp_error( $export ) ? '' : ( $export['job'] ?? '' );
} while ( ! is_wp_error( $export ) && ! $export['done'] );
$dir  = Storage::ensure();
$file = is_wp_error( $export ) ? '' : $dir . $export['file'];
wpc_check( 'export written to sealed storage', ! is_wp_error( $export ) && is_file( $file ) && file_exists( $dir . '.htaccess' ) && file_exists( $dir . 'web.config' ) && file_exists( $dir . 'index.php' ) );
wpc_check( 'export file name has 32-char random token', (bool) preg_match( '/^snapshot-\d{8}-\d{6}-[A-Za-z0-9]{32}\.sql\.gz$/', basename( $file ) ) );
$sql = $file ? implode( '', gzfile( $file ) ) : '';
wpc_check( 'dump uses original table names', false !== strpos( $sql, "CREATE TABLE `{$wpdb->posts}`" ) && false !== strpos( $sql, "INSERT INTO `{$wpdb->options}`" ) && false === strpos( $sql, 'CREATE TABLE `_wpc_' ) );
wpc_check( 'dump restores original comment, not the wpc tag', false !== strpos( $sql, "COMMENT='original comment'" ) && false === strpos( $sql, '"wpc":1' ) );
wpc_check( 'dump re-adds foreign keys', false !== strpos( $sql, "ALTER TABLE `{$p}wpc_fk_child` ADD CONSTRAINT `wpc_fk_cascade` FOREIGN KEY" ) );
wpc_check( 'dump contains all 12k bulk meta rows', 12000 === substr_count( $sql, "'wpc_bulk'" ) );
wpc_check( 'download URL is nonce-protected', false !== strpos( (string) ( $export['download_url'] ?? '' ), '_wpnonce=' ) );

// ---------------------------------------------------------------------------
echo "\n== Orphan scavenger ==\n";
$orphans = array(
	'_wpc_999_deadbeef_' . $p . 'posts',
	'_wpc_stg_abc123_deadbeef_' . $p . 'options',
	'a1b2c3_' . $p . 'posts',
	'zz_tagged_orphan',
);
foreach ( $orphans as $o ) {
	$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$o}` (id INT PRIMARY KEY) ENGINE=InnoDB" );
}
$wpdb->query( "ALTER TABLE zz_tagged_orphan COMMENT = '{\"wpc\":1,\"snap\":\"5\",\"orig\":\"{$p}foo\"}'" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS `_wpc_3_deadbeef_other_posts` (id INT) COMMENT = '{\"wpc\":1,\"snap\":\"3\",\"orig\":\"other_posts\"}'" );
$wpdb->query( "ALTER TABLE {$wpdb->comments} COMMENT = '{\"wpc\":1}'" );

list( $status, $scan ) = wpc_call( 'GET', 'snapshots/orphans' );
$found = array_column( $scan['tables'], 'name' );
sort( $found );
$want = $orphans;
sort( $want );
wpc_check( 'scan finds exactly the 4 orphans', $found === $want, implode( ', ', $found ) );
wpc_check( 'another install\'s snapshot table spared', ! in_array( '_wpc_3_deadbeef_other_posts', $found, true ) );
wpc_check( 'live table with wpc comment spared', ! in_array( $wpdb->comments, $found, true ) );
wpc_check( 'owned snapshot tables spared', ! in_array( $tables[ $wpdb->posts ]['snapshot_table'], $found, true ) );

list( $status ) = wpc_call( 'POST', 'snapshots/orphans/purge', array( 'confirm' => 'nope' ) );
wpc_check( 'purge without PURGE rejected', 400 === $status );
list( $status, $purged ) = wpc_call( 'POST', 'snapshots/orphans/purge', array( 'confirm' => 'PURGE' ) );
$gone = true;
foreach ( $orphans as $o ) {
	$gone = $gone && ! wpc_table_exists( $o );
}
wpc_check( 'purge drops orphans only', 200 === $status && $gone && wpc_table_exists( '_wpc_3_deadbeef_other_posts' ) && wpc_table_exists( $wpdb->comments ) );
$wpdb->query( 'DROP TABLE IF EXISTS `_wpc_3_deadbeef_other_posts`' );
$wpdb->query( "ALTER TABLE {$wpdb->comments} COMMENT = ''" );

// ---------------------------------------------------------------------------
echo "\n== Auto-snapshots ==\n";
Settings::update( array( 'auto_snapshot' => true, 'auto_retention' => 2 ) );
$count_auto = static function () use ( $service ) {
	return count(
		array_filter(
			$service->all(),
			static function ( $s ) {
				return 'auto' === $s['trigger_type'];
			}
		)
	);
};
$max_id = static function () use ( $service ) {
	return (int) max( array_merge( array( 0 ), array_column( $service->all(), 'id' ) ) );
};
$new_autos = static function ( int $after ) use ( $service ) {
	return count(
		array_filter(
			$service->all(),
			static function ( $s ) use ( $after ) {
				return 'auto' === $s['trigger_type'] && $s['id'] > $after;
			}
		)
	);
};
delete_transient( SnapshotService::AUTO_LOCK );
$before = $max_id();
foreach ( array( 'akismet/akismet.php', 'hello.php', 'foo/foo.php' ) as $plugin ) {
	apply_filters( 'upgrader_pre_install', true, array( 'plugin' => $plugin, 'temp_backup' => array() ) );
}
wpc_check( 'bulk update of 3 plugins => exactly 1 auto-snapshot', 1 === $new_autos( $before ), $new_autos( $before ) . ' created' );
wpc_check( 'batch lock transient set', false !== get_transient( SnapshotService::AUTO_LOCK ) );

for ( $i = 0; $i < 3; $i++ ) {
	delete_transient( SnapshotService::AUTO_LOCK );
	apply_filters( 'upgrader_pre_install', true, array( 'plugin' => 'akismet/akismet.php' ) );
}
wpc_check( 'retention keeps only the newest 2 auto-snapshots', 2 === $count_auto(), (string) $count_auto() );

delete_transient( SnapshotService::AUTO_LOCK );
Settings::update( array( 'auto_snapshot' => false ) );
$before = $max_id();
apply_filters( 'upgrader_pre_install', true, array( 'plugin' => 'akismet/akismet.php' ) );
wpc_check( 'toggle off => no auto-snapshot', 0 === $new_autos( $before ) );
Settings::update( array( 'auto_snapshot' => true, 'auto_retention' => 5 ) );

// ---------------------------------------------------------------------------
echo "\n== Safety snapshot hook (Phase 2 wiring) ==\n";
$pre = ( new ResetService() )->preflight( 'transients', true );
wpc_check( 'reset preflight creates a real safety snapshot', is_array( $pre['snapshot'] ) && 'safety' === $pre['snapshot']['trigger_type'] && 'ready' === $pre['snapshot']['status'], $pre['log'][1] ?? '' );

echo "\n" . ( $GLOBALS['wpc_fails'] ? "{$GLOBALS['wpc_fails']} check(s) FAILED" : 'All checks passed' ) . "\n";
