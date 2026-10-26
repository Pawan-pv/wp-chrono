<?php
/**
 * Deletes every inactive plugin (running its uninstall routine) via
 * delete_plugins() / WP_Filesystem. WP Chrono itself is never touched.
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Reset;

defined( 'ABSPATH' ) || exit;

final class InactivePluginsCleaner extends Cleaner {

	public function availability() {
		if ( is_multisite() ) {
			return 'Not available on multisite: a plugin inactive here may be active on another site.';
		}
		return wp_is_file_mod_allowed( 'wp_chrono_cleanup' ) ? true : 'File modifications are disabled (DISALLOW_FILE_MODS).';
	}

	public function preview(): array {
		$bytes   = 0;
		$details = array();
		foreach ( $this->inactive() as $file => $data ) {
			$bytes    += Files::size( $this->path( $file ) );
			$details[] = $data['Name'] . ( empty( $data['Version'] ) ? '' : ' ' . $data['Version'] );
		}
		return self::summary( count( $details ), $bytes, $details );
	}

	public function run( array $options, float $deadline ): array {
		$fs = Files::init_filesystem();
		if ( is_wp_error( $fs ) ) {
			return self::result( true, 0, array( 'Error: ' . $fs->get_error_message() ) );
		}

		// The cursor counts plugins already attempted, so a plugin that fails to
		// delete is skipped on the next tick instead of being retried forever.
		$cursor  = (int) ( $options['cursor'] ?? 0 );
		$pending = array_slice( $this->inactive(), $cursor, null, true );
		$log     = array();
		$deleted = 0;

		foreach ( $pending as $file => $data ) {
			$result = delete_plugins( array( $file ) );
			if ( true === $result ) {
				$deleted++;
				$log[] = "Deleted plugin {$data['Name']}.";
			} else {
				$cursor++;
				$log[] = sprintf( 'Could not delete %s: %s', $data['Name'], is_wp_error( $result ) ? $result->get_error_message() : 'filesystem credentials required' );
			}

			if ( self::out_of_time( $deadline ) ) {
				return self::result( false, $deleted, $log, $cursor );
			}
		}

		$log[] = sprintf( '%d inactive plugins deleted.', $deleted );
		return self::result( true, $deleted, $log );
	}

	/**
	 * @return array<string,array<string,string>>
	 */
	private function inactive(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$active = (array) get_option( 'active_plugins', array() );
		$all    = get_plugins();
		unset( $all[ WP_CHRONO_BASENAME ] );
		return array_diff_key( $all, array_flip( $active ) );
	}

	private function path( string $file ): string {
		$dir = dirname( $file );
		return WP_PLUGIN_DIR . '/' . ( '.' === $dir ? $file : $dir );
	}
}
