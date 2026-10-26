<?php
/**
 * Plugin settings (single autoload=off option).
 *
 * @package WPChrono
 */

namespace WPChrono\Services;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'wp_chrono_settings';

	const DEFAULTS = array(
		'auto_snapshot'   => true,
		'safe_updates'    => true,
		'auto_retention'  => 5,
		'storage_warn_mb' => 500,
		'rollback_max_mb' => 250,
		'schedule'        => 'off',
		'schedule_hour'   => 3,
		'schedule_keep'   => 3,
	);

	const SCHEDULES = array( 'off', 'daily', 'weekly' );

	/**
	 * @return array{auto_snapshot:bool,safe_updates:bool,auto_retention:int,storage_warn_mb:int,rollback_max_mb:int,schedule:string,schedule_hour:int,schedule_keep:int}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$merged = array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
		return array(
			'auto_snapshot'   => (bool) $merged['auto_snapshot'],
			'safe_updates'    => (bool) $merged['safe_updates'],
			'auto_retention'  => max( 1, min( 50, (int) $merged['auto_retention'] ) ),
			'storage_warn_mb' => max( 1, min( 1048576, (int) $merged['storage_warn_mb'] ) ),
			'rollback_max_mb' => max( 1, min( 10240, (int) $merged['rollback_max_mb'] ) ),
			'schedule'        => in_array( $merged['schedule'], self::SCHEDULES, true ) ? (string) $merged['schedule'] : 'off',
			'schedule_hour'   => max( 0, min( 23, (int) $merged['schedule_hour'] ) ),
			'schedule_keep'   => max( 1, min( 50, (int) $merged['schedule_keep'] ) ),
		);
	}

	/**
	 * @param array<string,mixed> $changes
	 * @return array{auto_snapshot:bool,safe_updates:bool,auto_retention:int,storage_warn_mb:int,rollback_max_mb:int,schedule:string,schedule_hour:int,schedule_keep:int}
	 */
	public static function update( array $changes ): array {
		$before = self::get();
		$next   = array_merge( $before, array_intersect_key( $changes, self::DEFAULTS ) );
		update_option( self::OPTION, $next, false );
		$after = self::get();
		if ( $after['schedule'] !== $before['schedule'] || $after['schedule_hour'] !== $before['schedule_hour'] ) {
			Scheduler::sync( true );
		}
		return $after;
	}
}
