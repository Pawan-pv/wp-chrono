<?php
/**
 * Resets wp_options to a fresh install's defaults.
 *
 * Kept: site URLs and identity, active plugins and theme, theme mods, user
 * roles, salts (so nobody is logged out), cron, permalinks and every
 * `wp_chrono_*` option. Everything else is deleted, then core's
 * populate_options() / populate_roles() write the defaults back.
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Reset;

defined( 'ABSPATH' ) || exit;

final class OptionsCleaner extends Cleaner {

	const KEEP = array(
		'siteurl',
		'home',
		'blogname',
		'blogdescription',
		'admin_email',
		'WPLANG',
		'blog_public',
		'active_plugins',
		'template',
		'stylesheet',
		'current_theme',
		'db_version',
		'initial_db_version',
		'permalink_structure',
		'category_base',
		'tag_base',
		'cron',
		'uninstall_plugins',
		'recently_activated',
		'auth_key',
		'auth_salt',
		'logged_in_key',
		'logged_in_salt',
		'nonce_key',
		'nonce_salt',
		'secure_auth_key',
		'secure_auth_salt',
	);

	public function availability() {
		return is_multisite() ? 'Not available on multisite yet.' : true;
	}

	public function preview(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB -- where() is built with $wpdb->prepare().
		$row = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_name) + LENGTH(option_value)), 0) AS b FROM {$wpdb->options} WHERE " . $this->where(), ARRAY_A );
		return self::summary(
			(int) $row['n'],
			(int) $row['b'],
			array( sprintf( '%d options kept: site URLs, theme, plugins, roles, salts, permalinks, WP Chrono settings', count( $this->keep() ) ) )
		);
	}

	public function run( array $options, float $deadline ): array {
		global $wpdb;
		$deleted = 0;
		do {
			$batch    = (int) $wpdb->query( "DELETE FROM {$wpdb->options} WHERE " . $this->where() . ' LIMIT ' . self::BATCH ); // phpcs:ignore WordPress.DB
			$deleted += $batch;
			if ( $batch >= self::BATCH && self::out_of_time( $deadline ) ) {
				return self::result( false, $deleted, array( sprintf( 'Deleted %d options so far…', $deleted ) ) );
			}
		} while ( $batch >= self::BATCH );

		wp_cache_flush();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		populate_options();
		populate_roles();
		wp_cache_flush();

		return self::result(
			true,
			$deleted,
			array( sprintf( 'Deleted %d options.', $deleted ), 'Restored WordPress default options and roles.' )
		);
	}

	/**
	 * @return string[]
	 */
	private function keep(): array {
		global $wpdb;
		return array_merge( self::KEEP, array( $wpdb->get_blog_prefix() . 'user_roles' ) );
	}

	private function where(): string {
		global $wpdb;
		$keep = $this->keep();
		return $wpdb->prepare(
			'option_name NOT IN (' . implode( ',', array_fill( 0, count( $keep ), '%s' ) ) . ') AND option_name NOT LIKE %s AND option_name NOT LIKE %s',
			array_merge( $keep, array( $wpdb->esc_like( 'theme_mods_' ) . '%', $wpdb->esc_like( 'wp_chrono_' ) . '%' ) )
		);
	}
}
