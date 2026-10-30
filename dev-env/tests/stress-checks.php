<?php
/**
 * Large-database stress + memory benchmark.
 *
 * Seeds 220k+ rows (> 50 MB) across a single-integer-PK table (wp_postmeta,
 * keyset), a composite-PK table (wp_term_relationships, composite keyset) and
 * a keyless log table (single statement), then snapshots it in short ticks
 * while writing to the site between ticks, diffs, exports and restores.
 *
 *   docker compose exec wp wp eval-file /tests/stress-checks.php --user=chrono
 */

use WPChrono\Services\Database;
use WPChrono\Services\DiffService;
use WPChrono\Services\SnapshotService;

$GLOBALS['wpc_fails'] = 0;

function wpc_check( string $label, bool $ok, string $extra = '' ): void {
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) . "\n";
	if ( ! $ok ) {
		$GLOBALS['wpc_fails']++;
	}
}

function wpc_mb( int $bytes ): string {
	return number_format( $bytes / 1048576, 1 ) . ' MB';
}

/** Runs $fn while tracking PHP peak memory above the baseline taken just before it. */
function wpc_measure( callable $fn ): array {
	gc_collect_cycles();
	memory_reset_peak_usage();
	$base   = memory_get_usage();
	$start  = microtime( true );
	$result = $fn();
	return array( $result, memory_get_peak_usage() - $base, microtime( true ) - $start );
}

global $wpdb;
$service = new SnapshotService();
$p       = $wpdb->prefix;
$log     = "{$p}wpc_stress_log";

const META_ROWS = 130000;
const TERM_ROWS = 60000;
const LOG_ROWS  = 32000;
const TICK      = 0.2; // Short ticks force many resumptions.
const MEM_LIMIT = 48 * 1048576; // Bounded: < 48 MB above baseline for any phase.

// ---------------------------------------------------------------------------
echo "== Seed ==\n";
$t0 = microtime( true );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'wpc_stress'" );
$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id >= 5000000" );
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_title LIKE 'wpc concurrent %'" );
$wpdb->query( "DROP TABLE IF EXISTS {$log}" );
$wpdb->query( "CREATE TABLE {$log} (at DATETIME, level VARCHAR(10), message TEXT) ENGINE=InnoDB" );

$pad  = str_repeat( 'x', 300 );
$host = (int) wp_insert_post( array( 'post_title' => 'wpc stress host', 'post_status' => 'draft' ) );
for ( $i = 0; $i < META_ROWS; $i += 2500 ) {
	$values = array();
	for ( $j = $i; $j < min( $i + 2500, META_ROWS ); $j++ ) {
		$values[] = "({$host},'wpc_stress','{$j}-{$pad}')";
	}
	$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $values ) );
}
for ( $i = 0; $i < TERM_ROWS; $i += 5000 ) {
	$values = array();
	for ( $j = $i; $j < min( $i + 5000, TERM_ROWS ); $j++ ) {
		$values[] = '(' . ( 5000000 + intdiv( $j, 3 ) ) . ',' . ( 1 + $j % 3 ) . ',0)';
	}
	$wpdb->query( "INSERT INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) VALUES " . implode( ',', $values ) );
}
$msg = str_repeat( 'log line ', 22 );
for ( $i = 0; $i < LOG_ROWS; $i += 4000 ) {
	$values = array_fill( 0, min( 4000, LOG_ROWS - $i ), "(NOW(),'info','{$msg}')" );
	$wpdb->query( "INSERT INTO {$log} VALUES " . implode( ',', $values ) );
}
$wpdb->query( "ANALYZE TABLE {$wpdb->postmeta}, {$wpdb->term_relationships}, {$log}" );
$tables     = Database::site_tables();
$seed_bytes = array_sum( array_column( $tables, 'bytes' ) );
$seed_rows  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships}" ) + LOG_ROWS;
printf( "  %s rows in the three stress tables, %s across %d site tables, seeded in %.1fs\n", number_format( $seed_rows ), wpc_mb( $seed_bytes ), count( $tables ), microtime( true ) - $t0 );
wpc_check( 'seeded >= 200k rows and >= 50 MB', $seed_rows >= 200000 && $seed_bytes >= 50 * 1048576 );

// ---------------------------------------------------------------------------
echo "\n== Chunked snapshot with concurrent writes between ticks ==\n";
$snap      = $service->create( 'stress', 'manual' );
$id        = (int) $snap['id'];
$ticks     = 0;
$peaks     = array();
$written   = 0;
$started   = microtime( true );
$error     = null;
do {
	list( $progress, $peak ) = wpc_measure(
		static function () use ( $service, $id ) {
			return $service->build( $id, TICK );
		}
	);
	$peaks[] = $peak;
	$ticks++;
	if ( is_wp_error( $progress ) ) {
		$error = $progress->get_error_message();
		break;
	}
	// The site keeps working while the snapshot is built: 50 new posts, plus
	// rows landing in the middle of both keyset ranges.
	for ( $k = 0; $k < 50; $k++ ) {
		wp_insert_post( array( 'post_title' => "wpc concurrent {$ticks}-{$k}", 'post_status' => 'publish' ) );
	}
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d,'wpc_stress','late')", $host ) );
	$wpdb->query( 'INSERT IGNORE INTO ' . $wpdb->term_relationships . ' VALUES (' . ( 5000000 + 7 * $ticks ) . ',9,0)' ); // Lands inside the composite range.
	$written += 52;
} while ( ! $progress['done'] && $ticks < 1000 );
$build_time = microtime( true ) - $started;
$snapshot   = $service->get( $id );
wpc_check( 'snapshot completed despite concurrent writes', null === $error && 'ready' === $snapshot['status'], $error ?? sprintf( '%d ticks, %.1fs, %s rows, %s', $ticks, $build_time, number_format( $snapshot['row_count'] ), wpc_mb( $snapshot['size_bytes'] ) ) );
wpc_check( 'build needed many resumable ticks', $ticks >= 5, "{$ticks} ticks of " . TICK . 's' );

$max_peak  = max( $peaks );
$early     = max( array_slice( $peaks, 0, 3 ) );
wpc_check( 'per-tick peak memory bounded', $max_peak < MEM_LIMIT, 'max ' . wpc_mb( $max_peak ) . ' above baseline; process peak ' . wpc_mb( memory_get_peak_usage( true ) ) . ' real' );
wpc_check( 'no memory growth across ticks', $max_peak - $early <= 4 * 1048576, 'first ticks ' . wpc_mb( $early ) . ', overall ' . wpc_mb( $max_peak ) );

// Consistency: each keyset copy must equal "every live row up to the last key copied" — no gaps, no duplicates.
$t        = $service->tables( $id );
$copy     = static function ( $orig ) use ( $t ) {
	return Database::quote_identifier( $t[ $orig ]['snapshot_table'] );
};
$max_meta = (int) $wpdb->get_var( 'SELECT MAX(meta_id) FROM ' . $copy( $wpdb->postmeta ) );
$live_le  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_id <= {$max_meta}" );
wpc_check( 'keyset copy (postmeta) is gap-free and duplicate-free', $live_le === (int) $t[ $wpdb->postmeta ]['row_count'], "{$t[ $wpdb->postmeta ]['row_count']} copied = {$live_le} live up to meta_id {$max_meta}" );

// Composite keys don't grow monotonically: a new post gets a low object_id
// *behind* the cursor. Every row that existed before the build must be present.
$seeded_in_copy = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $copy( $wpdb->term_relationships ) . ' WHERE object_id >= 5000000 AND term_taxonomy_id <= 3' );
$tr_dupes       = (int) $wpdb->get_var( 'SELECT COUNT(*) - COUNT(DISTINCT object_id, term_taxonomy_id) FROM ' . $copy( $wpdb->term_relationships ) );
$last           = $wpdb->get_row( 'SELECT object_id, term_taxonomy_id FROM ' . $copy( $wpdb->term_relationships ) . ' ORDER BY object_id DESC, term_taxonomy_id DESC LIMIT 1', ARRAY_A );
$behind         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id < %d OR (object_id = %d AND term_taxonomy_id <= %d)", $last['object_id'], $last['object_id'], $last['term_taxonomy_id'] ) ) - (int) $t[ $wpdb->term_relationships ]['row_count'];
wpc_check( 'composite keyset copy (term_relationships): all pre-existing rows, no duplicates', TERM_ROWS === $seeded_in_copy && 0 === $tr_dupes && 'composite' === $t[ $wpdb->term_relationships ]['copy_mode'], "{$seeded_in_copy} / " . TERM_ROWS . ' seeded rows' );
echo "  {$behind} rows were written behind the composite cursor mid-copy and are not in the snapshot (multi-request snapshots are not point-in-time).
";
wpc_check( 'keyless log table copied in one statement', LOG_ROWS === (int) $t[ $log ]['row_count'] && 'single' === $t[ $log ]['copy_mode'] );
$dupes = (int) $wpdb->get_var( 'SELECT COUNT(*) - COUNT(DISTINCT ID) FROM ' . $copy( $wpdb->posts ) );
wpc_check( 'posts copied without duplicates while being written to', 0 === $dupes );
echo "  concurrent writes during build: {$written} rows. Rows written after a table finished copying are, by design, not in the snapshot.\n";

// ---------------------------------------------------------------------------
echo "\n== Diff ==\n";
list( $diff, $peak, $secs ) = wpc_measure(
	static function () use ( $service, $id ) {
		return ( new DiffService( $service ) )->compare( $id );
	}
);
$modified = array_column( $diff['tables']['modified_tables'], null, 'table' );
wpc_check( 'diff completes on a 50 MB+ database', ! is_wp_error( $diff ), sprintf( '%.2fs, peak %s', $secs, wpc_mb( $peak ) ) );
wpc_check( 'diff sees posts written after the copy', ( $modified[ $wpdb->posts ]['rows_delta'] ?? 0 ) > 0, '+' . ( $modified[ $wpdb->posts ]['rows_delta'] ?? 0 ) . ' rows' );
wpc_check( 'diff memory bounded', $peak < MEM_LIMIT );

// ---------------------------------------------------------------------------
echo "\n== Streaming .sql.gz export ==\n";
$job    = '';
$ticks  = 0;
$peaks  = array();
$start  = microtime( true );
do {
	list( $export, $peak ) = wpc_measure(
		static function () use ( $service, $id, $job ) {
			return $service->export_tick( $id, $job, TICK );
		}
	);
	$peaks[] = $peak;
	$ticks++;
	$job = is_wp_error( $export ) ? '' : ( $export['job'] ?? '' );
} while ( ! is_wp_error( $export ) && ! $export['done'] && $ticks < 2000 );
wpc_check( 'export finished', ! is_wp_error( $export ) && $export['done'], sprintf( '%d ticks, %.1fs, %s gzipped', $ticks, microtime( true ) - $start, wpc_mb( (int) ( $export['bytes'] ?? 0 ) ) ) );
wpc_check( 'export peak memory bounded', max( $peaks ) < MEM_LIMIT, 'max ' . wpc_mb( max( $peaks ) ) );
$rows_in_dump = 0;
$gz           = gzopen( WPChrono\Core\Storage::ensure() . $export['file'], 'rb' );
while ( ! gzeof( $gz ) ) {
	$rows_in_dump += substr_count( (string) gzgets( $gz, 1 << 20 ), ",'wpc_stress','" );
}
gzclose( $gz );
$expected = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $copy( $wpdb->postmeta ) . " WHERE meta_key = 'wpc_stress'" );
wpc_check( 'every stress row is in the dump', $rows_in_dump === $expected, "{$rows_in_dump} / {$expected}" );

// ---------------------------------------------------------------------------
echo "\n== Atomic staging restore ==\n";
$ticks = 0;
$peaks = array();
$start = microtime( true );
$tick  = $service->restore( $id, true );
while ( ! is_wp_error( $tick ) && ! $tick['done'] && $ticks < 2000 ) {
	list( $tick, $peak ) = wpc_measure(
		static function () use ( $service, $tick ) {
			return $service->restore_tick( $tick['job'], TICK );
		}
	);
	$peaks[] = $peak;
	$ticks++;
}
wp_cache_flush();
wpc_check( 'restore finished', ! is_wp_error( $tick ) && $tick['done'], is_wp_error( $tick ) ? $tick->get_error_message() : sprintf( '%d ticks, %.1fs total, atomic swap %d ms', $ticks, microtime( true ) - $start, $tick['swap_ms'] ) );
wpc_check( 'restore peak memory bounded', $peaks && max( $peaks ) < MEM_LIMIT, $peaks ? 'max ' . wpc_mb( max( $peaks ) ) : '' );

$mismatch = array();
foreach ( $t as $orig => $row ) {
	$live = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::quote_identifier( $orig ) );
	if ( $live !== (int) $row['row_count'] ) {
		$mismatch[] = "{$orig}: {$live} live vs {$row['row_count']} in snapshot";
	}
}
wpc_check( 'exact row counts after restore, every table', ! $mismatch, $mismatch ? implode( '; ', $mismatch ) : count( $t ) . ' tables match' );
wpc_check( 'posts written after the snapshot are gone', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'wpc concurrent %'" ) || (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) === (int) $t[ $wpdb->posts ]['row_count'] );

// Leave the stack small for the suites that follow.
$service->delete( $id );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'wpc_stress'" );
$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id >= 5000000" );
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_title LIKE 'wpc concurrent %'" );
$wpdb->query( "DROP TABLE IF EXISTS {$log}" );
WPChrono\Services\Snapshot\Exporter::delete_file( $export['file'] );

echo "\n" . ( $GLOBALS['wpc_fails'] ? "{$GLOBALS['wpc_fails']} check(s) FAILED" : 'All checks passed' ) . "\n";
