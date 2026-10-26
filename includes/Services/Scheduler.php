<?php
/**
 * Scheduled snapshots: daily, or weekly on Sundays, at a chosen hour in the
 * site's timezone, through WP-Cron.
 *
 * A snapshot is built in slices (SLICE seconds per cron run) and re-queues
 * itself until it is ready, so it finishes on hosts with short time limits.
 * Scheduled snapshots have their own trigger type and retention, so they
 * never push out the pre-update snapshots (and vice versa).
 *
 * The event is re-registered whenever it is missing — restoring a database
 * also restores WordPress's cron list, which may not contain it.
 *
 * @package WPChrono
 */

namespace WPChrono\Services;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	const HOOK = 'wp_chrono_scheduled_snapshot';

	const CONTINUE_HOOK = 'wp_chrono_scheduled_snapshot_continue';

	/** Seconds of snapshot building per cron run. */
	const SLICE = 20;

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( self::CONTINUE_HOOK, array( self::class, 'resume' ) );
		add_action( 'init', array( self::class, 'sync' ), 20 );
	}

	/** Plugin deactivation: no orphaned cron events (sync() re-adds them on reactivation). */
	public static function deactivate(): void {
		wp_unschedule_hook( self::HOOK );
		wp_unschedule_hook( self::CONTINUE_HOOK );
	}

	/**
	 * Makes the cron event match the settings: (re)schedules it when it is
	 * missing or its recurrence changed, removes it when scheduling is off.
	 */
	public static function sync( bool $force = false ): void {
		$settings = Settings::get();
		$event    = wp_get_scheduled_event( self::HOOK );
		if ( 'off' === $settings['schedule'] ) {
			if ( $event ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}
		if ( $event && ! $force && $event->schedule === $settings['schedule'] ) {
			return;
		}
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( self::next_run( $settings['schedule'], $settings['schedule_hour'] ), $settings['schedule'], self::HOOK );
	}

	/**
	 * Next run time (Unix timestamp): today or later at $hour in the site's
	 * timezone; for 'weekly', the next Sunday.
	 */
	public static function next_run( string $frequency, int $hour, ?int $now = null ): int {
		$tz   = wp_timezone();
		$now  = ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( $tz );
		$next = $now->setTime( $hour, 0 );
		while ( $next <= $now || ( 'weekly' === $frequency && '0' !== $next->format( 'w' ) ) ) {
			$next = $next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}

	/** Next scheduled run (ISO 8601, UTC) or null. */
	public static function next(): ?string {
		$ts = wp_next_scheduled( self::HOOK );
		return $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : null;
	}

	/** Cron: start a scheduled snapshot (unless one is still being built). */
	public static function run(): void {
		$service = new SnapshotService();
		foreach ( $service->all() as $snapshot ) {
			if ( 'scheduled' === $snapshot['trigger_type'] && 'building' === $snapshot['status'] && strtotime( $snapshot['created_at'] ) > time() - DAY_IN_SECONDS ) {
				self::resume( (int) $snapshot['id'] );
				return;
			}
		}
		$created = $service->create( 'Scheduled snapshot', 'scheduled' );
		if ( is_wp_error( $created ) ) {
			error_log( 'WP Chrono: scheduled snapshot failed: ' . $created->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}
		self::resume( (int) $created['id'] );
	}

	/** Cron: build one slice; re-queue until the snapshot is ready. */
	public static function resume( $id ): void {
		$id      = (int) $id;
		$service = new SnapshotService();
		/**
		 * Seconds of snapshot building per WP-Cron run.
		 *
		 * @param int $seconds
		 */
		$progress = $service->build( $id, (float) apply_filters( 'wp_chrono_scheduled_slice', self::SLICE ) );
		if ( is_wp_error( $progress ) ) {
			error_log( 'WP Chrono: scheduled snapshot #' . $id . ' failed: ' . $progress->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}
		if ( empty( $progress['done'] ) ) {
			wp_schedule_single_event( time() + 5, self::CONTINUE_HOOK, array( $id ) );
			return;
		}
		$service->prune_trigger( 'scheduled', Settings::get()['schedule_keep'] );
		/**
		 * A scheduled snapshot is ready (e.g. to push it off-site).
		 *
		 * @param int $id Snapshot ID.
		 */
		do_action( 'wp_chrono_scheduled_snapshot_ready', $id );
	}
}
