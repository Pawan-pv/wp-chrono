<?php
/**
 * Scheduled snapshots: schedule maths in the site's timezone, WP-Cron
 * (re)scheduling and self-healing, sliced building that survives tiny time
 * budgets, per-type retention, deactivation.
 *
 *   wp eval-file /tests/scheduled-checks.php --user=chrono
 */

use WPChrono\Services\Scheduler;
use WPChrono\Services\Settings;
use WPChrono\Services\SnapshotService;

$GLOBALS['wpc_fails']  = 0;
$GLOBALS['wpc_passes'] = 0;
function wpc_check( string $label, bool $ok, string $extra = '' ): void {
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) . "\n";
	$ok ? $GLOBALS['wpc_passes']++ : $GLOBALS['wpc_fails']++;
}
function wpc_ids( string $trigger ): array {
	$ids = array();
	foreach ( ( new SnapshotService() )->all() as $s ) {
		if ( $trigger === $s['trigger_type'] ) {
			$ids[] = (int) $s['id'];
		}
	}
	return $ids;
}
/** Runs queued "continue" events like WP-Cron would; returns how many slices it took. */
function wpc_drain(): int {
	$slices = 0;
	while ( $slices < 2000 ) {
		$crons = _get_cron_array();
		$found = null;
		foreach ( $crons as $ts => $hooks ) {
			if ( isset( $hooks[ Scheduler::CONTINUE_HOOK ] ) ) {
				$found = array( $ts, reset( $hooks[ Scheduler::CONTINUE_HOOK ] )['args'] );
				break;
			}
		}
		if ( ! $found ) {
			break;
		}
		wp_unschedule_event( $found[0], Scheduler::CONTINUE_HOOK, $found[1] );
		do_action_ref_array( Scheduler::CONTINUE_HOOK, $found[1] );
		$slices++;
	}
	return $slices;
}

$settings_before = Settings::get();
$tz_before       = get_option( 'timezone_string' );
$offset_before   = get_option( 'gmt_offset' );
$auto_before     = wpc_ids( 'auto' );

echo "== Schedule maths (site timezone Asia/Kolkata, UTC+5:30) ==\n";
update_option( 'timezone_string', 'Asia/Kolkata' );
$tz  = wp_timezone();
$at  = static function ( string $local ) use ( $tz ) {
	return ( new DateTimeImmutable( $local, $tz ) )->getTimestamp();
};
$fmt = static function ( int $ts ) use ( $tz ) {
	return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->format( 'D Y-m-d H:i' );
};
// 2026-10-27 is a Tuesday.
wpc_check( 'daily 03:00, now Tue 01:00 → today 03:00', $fmt( Scheduler::next_run( 'daily', 3, $at( '2026-10-27 01:00' ) ) ) === 'Tue 2026-10-27 03:00' );
wpc_check( 'daily 03:00, now Tue 03:00 → tomorrow', $fmt( Scheduler::next_run( 'daily', 3, $at( '2026-10-27 03:00' ) ) ) === 'Wed 2026-10-28 03:00' );
wpc_check( 'daily 23:00, now Tue 23:30 → Wed 23:00', $fmt( Scheduler::next_run( 'daily', 23, $at( '2026-10-27 23:30' ) ) ) === 'Wed 2026-10-28 23:00' );
wpc_check( 'weekly 03:00, now Tue → Sunday 03:00', $fmt( Scheduler::next_run( 'weekly', 3, $at( '2026-10-27 10:00' ) ) ) === 'Sun 2026-11-01 03:00' );
wpc_check( 'weekly 03:00, now Sun 02:00 → same Sunday', $fmt( Scheduler::next_run( 'weekly', 3, $at( '2026-11-01 02:00' ) ) ) === 'Sun 2026-11-01 03:00' );
wpc_check( 'weekly 03:00, now Sun 04:00 → next Sunday', $fmt( Scheduler::next_run( 'weekly', 3, $at( '2026-11-01 04:00' ) ) ) === 'Sun 2026-11-08 03:00' );
wpc_check( 'UTC timestamp is the local hour minus 5:30', Scheduler::next_run( 'daily', 3, $at( '2026-10-27 01:00' ) ) === strtotime( '2026-10-26 21:30:00 UTC' ) );

echo "\n== WP-Cron scheduling ==\n";
Settings::update( array( 'schedule' => 'off' ) );
wpc_check( 'off by default / when off: no event', false === wp_next_scheduled( Scheduler::HOOK ) );
Settings::update( array( 'schedule' => 'daily', 'schedule_hour' => 3 ) );
$event = wp_get_scheduled_event( Scheduler::HOOK );
wpc_check( 'daily → recurring "daily" event at the next 03:00', $event && 'daily' === $event->schedule && $event->timestamp === Scheduler::next_run( 'daily', 3 ), $event ? $fmt( $event->timestamp ) : 'none' );
Settings::update( array( 'schedule_hour' => 22 ) );
$event = wp_get_scheduled_event( Scheduler::HOOK );
wpc_check( 'hour changed → rescheduled', $event && '22:00' === ( new DateTimeImmutable( '@' . $event->timestamp ) )->setTimezone( $tz )->format( 'H:i' ) );
Settings::update( array( 'schedule' => 'weekly' ) );
$event = wp_get_scheduled_event( Scheduler::HOOK );
wpc_check( 'weekly → recurring "weekly" event on a Sunday', $event && 'weekly' === $event->schedule && 'Sun' === ( new DateTimeImmutable( '@' . $event->timestamp ) )->setTimezone( $tz )->format( 'D' ) );
wp_clear_scheduled_hook( Scheduler::HOOK ); // What a database restore can do to the cron list.
Scheduler::sync();
wpc_check( 'event lost (e.g. by a database restore) → re-added by sync()', false !== wp_next_scheduled( Scheduler::HOOK ) );
$res = rest_do_request( new WP_REST_Request( 'GET', '/wp-chrono/v1/settings' ) );
$args = rest_get_server()->get_routes()['/wp-chrono/v1/settings'][1]['args'] ?? array();
wpc_check( 'REST /settings: schedule fields + next run', array( 'off', 'daily', 'weekly' ) === ( $args['schedule']['enum'] ?? null ) && isset( $args['schedule_hour'], $args['schedule_keep'] ) );
wpc_check( 'Scheduler::next() reports the event', null !== Scheduler::next() );

echo "\n== Building in slices ==\n";
Settings::update( array( 'schedule_keep' => 2 ) );
add_filter( 'wp_chrono_scheduled_slice', $wpc_tiny = static function () {
	return 0.001; // Far below any real limit: forces many slices.
} );
$ready_ids = array();
add_action( 'wp_chrono_scheduled_snapshot_ready', static function ( $id ) use ( &$ready_ids ) {
	$ready_ids[] = (int) $id;
} );
do_action( Scheduler::HOOK );
$first  = max( wpc_ids( 'scheduled' ) ?: array( 0 ) );
$queued = false !== wp_next_scheduled( Scheduler::CONTINUE_HOOK, array( $first ) );
wpc_check( 'cron run created a "scheduled" snapshot and queued the rest', $first > 0 && $queued );
$slices = wpc_drain();
$snap   = ( new SnapshotService() )->get( $first );
wpc_check( 'finished across many cron runs', ! is_wp_error( $snap ) && 'ready' === $snap['status'] && $slices > 1, "{$slices} slices" );
wpc_check( 'labelled and typed as scheduled', 'Scheduled snapshot' === $snap['label'] && 'scheduled' === $snap['trigger_type'] );
wpc_check( 'verified like any snapshot', true === ( $snap['meta']['verified'] ?? null ) );
wpc_check( '"ready" action fired once (hook for off-site copies)', array( $first ) === $ready_ids );
remove_filter( 'wp_chrono_scheduled_slice', $wpc_tiny );

echo "\n== Retention (keep 2 scheduled) ==\n";
do_action( Scheduler::HOOK );
wpc_drain();
do_action( Scheduler::HOOK );
wpc_drain();
$scheduled = wpc_ids( 'scheduled' );
wpc_check( 'only the newest 2 scheduled snapshots kept', 2 === count( $scheduled ) && ! in_array( $first, $scheduled, true ), implode( ',', $scheduled ) );
wpc_check( 'update snapshots untouched by scheduled retention', wpc_ids( 'auto' ) === $auto_before );

echo "\n== A run while one is still building resumes it ==\n";
$building = ( new SnapshotService() )->create( 'Scheduled snapshot', 'scheduled' );
$count    = count( wpc_ids( 'scheduled' ) );
add_filter( 'wp_chrono_scheduled_slice', $wpc_tiny );
do_action( Scheduler::HOOK );
remove_filter( 'wp_chrono_scheduled_slice', $wpc_tiny );
wpc_check( 'no second snapshot started', count( wpc_ids( 'scheduled' ) ) === $count );
wpc_drain();
wpc_check( 'the building one finished', 'ready' === ( new SnapshotService() )->get( (int) $building['id'] )['status'] );

echo "\n== Deactivation ==\n";
Scheduler::deactivate();
wpc_check( 'deactivate removes the events', false === wp_next_scheduled( Scheduler::HOOK ) );
Scheduler::sync();
wpc_check( 'reactivation (sync on init) restores them', false !== wp_next_scheduled( Scheduler::HOOK ) );

echo "\n== Cleanup ==\n";
foreach ( wpc_ids( 'scheduled' ) as $id ) {
	( new SnapshotService() )->delete( $id );
}
Settings::update( $settings_before );
update_option( 'timezone_string', $tz_before );
update_option( 'gmt_offset', $offset_before );
wpc_check( 'schedule off again, no events, no scheduled snapshots left', false === wp_next_scheduled( Scheduler::HOOK ) && ! wpc_ids( 'scheduled' ) );

echo "\n" . ( $GLOBALS['wpc_fails'] ? "{$GLOBALS['wpc_fails']} check(s) FAILED ({$GLOBALS['wpc_passes']} passed)" : "All checks passed ({$GLOBALS['wpc_passes']} passed)" ) . "\n";
