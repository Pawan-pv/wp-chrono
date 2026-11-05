<?php
/**
 * Scheduled snapshots: next run at the chosen hour in the SITE's timezone.
 *
 * @package WPChrono
 */

namespace WPChrono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPChrono\Services\Scheduler;

final class SchedulerTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpc_test_timezone'] );
	}

	private static function local_ts( string $local, string $tz ): int {
		return ( new \DateTimeImmutable( $local, new \DateTimeZone( $tz ) ) )->getTimestamp();
	}

	private static function local( int $ts, string $tz ): string {
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( new \DateTimeZone( $tz ) )->format( 'Y-m-d H:i D' );
	}

	/**
	 * @dataProvider cases
	 */
	public function test_next_run( string $tz, string $frequency, int $hour, string $now, string $expected ): void {
		$GLOBALS['wpc_test_timezone'] = $tz;
		$this->assertSame( $expected, self::local( Scheduler::next_run( $frequency, $hour, self::local_ts( $now, $tz ) ), $tz ) );
	}

	/** @return array<string,array{string,string,int,string,string}> */
	public function cases(): array {
		return array(
			'daily, hour still ahead today'   => array( 'UTC', 'daily', 3, '2026-10-30 01:00', '2026-10-30 03:00 Fri' ),
			'daily, hour already passed'      => array( 'UTC', 'daily', 3, '2026-10-30 04:00', '2026-10-31 03:00 Sat' ),
			'daily, exactly at the hour'      => array( 'UTC', 'daily', 3, '2026-10-30 03:00', '2026-10-31 03:00 Sat' ),
			'daily, midnight'                 => array( 'UTC', 'daily', 0, '2026-12-31 23:59', '2027-01-01 00:00 Fri' ),
			'weekly goes to the next Sunday'  => array( 'UTC', 'weekly', 3, '2026-10-30 01:00', '2026-11-01 03:00 Sun' ),
			'weekly, Sunday before the hour'  => array( 'UTC', 'weekly', 3, '2026-11-01 02:59', '2026-11-01 03:00 Sun' ),
			'weekly, Sunday after the hour'   => array( 'UTC', 'weekly', 3, '2026-11-01 03:01', '2026-11-08 03:00 Sun' ),
			'site timezone, not UTC'          => array( 'Asia/Kolkata', 'daily', 3, '2026-10-30 23:00', '2026-10-31 03:00 Sat' ),
			'across the end of US DST'        => array( 'America/New_York', 'daily', 3, '2026-10-31 12:00', '2026-11-01 03:00 Sun' ),
			'across the start of EU DST'      => array( 'Europe/Berlin', 'daily', 4, '2026-03-28 12:00', '2026-03-29 04:00 Sun' ),
		);
	}

	public function test_the_result_is_always_in_the_future(): void {
		$GLOBALS['wpc_test_timezone'] = 'Pacific/Chatham'; // +12:45 / +13:45
		$now = self::local_ts( '2026-04-05 02:30', 'Pacific/Chatham' );
		foreach ( array( 'daily', 'weekly' ) as $frequency ) {
			for ( $hour = 0; $hour < 24; $hour++ ) {
				$next = Scheduler::next_run( $frequency, $hour, $now );
				$this->assertGreaterThan( $now, $next );
				$this->assertLessThanOrEqual( $now + ( 'daily' === $frequency ? 1 : 7 ) * 86400 + 3600, $next );
			}
		}
	}
}
