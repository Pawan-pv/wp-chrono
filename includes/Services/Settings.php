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
		'keep_data_on_uninstall' => false,
		'safe_updates_urls'      => array(),
	);

	/** Most extra pages the admin can add to the Safe Updates check. */
	const MAX_URLS = 10;

	const SCHEDULES = array( 'off', 'daily', 'weekly' );

	/**
	 * @return array{auto_snapshot:bool,safe_updates:bool,auto_retention:int,storage_warn_mb:int,rollback_max_mb:int,schedule:string,schedule_hour:int,schedule_keep:int,keep_data_on_uninstall:bool,safe_updates_urls:string[]}
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
			// Deleting the plugin then keeps snapshots, exports and settings.
			'keep_data_on_uninstall' => (bool) $merged['keep_data_on_uninstall'],
			// Extra pages Safe Updates checks (this site's own host only).
			'safe_updates_urls'      => self::clean_urls( $merged['safe_updates_urls'] ),
		);
	}

	/**
	 * @param mixed $urls Array or newline-separated string.
	 * @return string[]
	 */
	public static function clean_urls( $urls ): array {
		$list = is_array( $urls ) ? $urls : preg_split( '/[\r\n]+/', (string) $urls );
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$out  = array();
		foreach ( (array) $list as $url ) {
			$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
			if ( '' !== $url && strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === $host && ! in_array( $url, $out, true ) ) {
				$out[] = $url;
			}
		}
		return array_slice( $out, 0, self::MAX_URLS );
	}

	/**
	 * @param array<string,mixed> $changes
	 * @return array{auto_snapshot:bool,safe_updates:bool,auto_retention:int,storage_warn_mb:int,rollback_max_mb:int,schedule:string,schedule_hour:int,schedule_keep:int,keep_data_on_uninstall:bool,safe_updates_urls:string[]}
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
