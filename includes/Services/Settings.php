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
		'auto_retention'  => 5,
		'storage_warn_mb' => 500,
		'rollback_max_mb' => 250,
	);

	/**
	 * @return array{auto_snapshot:bool,auto_retention:int,storage_warn_mb:int,rollback_max_mb:int}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$merged = array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
		return array(
			'auto_snapshot'   => (bool) $merged['auto_snapshot'],
			'auto_retention'  => max( 1, min( 50, (int) $merged['auto_retention'] ) ),
			'storage_warn_mb' => max( 1, min( 1048576, (int) $merged['storage_warn_mb'] ) ),
			'rollback_max_mb' => max( 1, min( 10240, (int) $merged['rollback_max_mb'] ) ),
		);
	}

	/**
	 * @param array<string,mixed> $changes
	 * @return array{auto_snapshot:bool,auto_retention:int,storage_warn_mb:int,rollback_max_mb:int}
	 */
	public static function update( array $changes ): array {
		$next = array_merge( self::get(), array_intersect_key( $changes, self::DEFAULTS ) );
		update_option( self::OPTION, $next, false );
		return self::get();
	}
}
