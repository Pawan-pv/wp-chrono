<?php
/**
 * Deletes every theme that is neither the active theme nor its parent.
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Reset;

defined( 'ABSPATH' ) || exit;

final class InactiveThemesCleaner extends Cleaner {

	public function availability() {
		if ( is_multisite() ) {
			return 'Not available on multisite: a theme inactive here may be active on another site.';
		}
		return wp_is_file_mod_allowed( 'wp_chrono_cleanup' ) ? true : 'File modifications are disabled (DISALLOW_FILE_MODS).';
	}

	public function preview(): array {
		$bytes   = 0;
		$details = array();
		foreach ( $this->inactive() as $theme ) {
			$bytes    += Files::size( $theme->get_stylesheet_directory() );
			$details[] = $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' );
		}
		return self::summary( count( $details ), $bytes, $details );
	}

	public function run( array $options, float $deadline ): array {
		$fs = Files::init_filesystem();
		if ( is_wp_error( $fs ) ) {
			return self::result( true, 0, array( 'Error: ' . $fs->get_error_message() ) );
		}
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		$cursor  = (int) ( $options['cursor'] ?? 0 );
		$pending = array_slice( $this->inactive(), $cursor, null, true );
		$log     = array();
		$deleted = 0;

		foreach ( $pending as $stylesheet => $theme ) {
			$result = delete_theme( $stylesheet );
			if ( true === $result ) {
				$deleted++;
				$log[] = 'Deleted theme ' . $theme->get( 'Name' ) . '.';
			} else {
				$cursor++;
				$log[] = sprintf( 'Could not delete %s: %s', $theme->get( 'Name' ), is_wp_error( $result ) ? $result->get_error_message() : 'filesystem credentials required' );
			}

			if ( self::out_of_time( $deadline ) ) {
				return self::result( false, $deleted, $log, $cursor );
			}
		}

		$log[] = sprintf( '%d inactive themes deleted.', $deleted );
		return self::result( true, $deleted, $log );
	}

	/**
	 * @return array<string,\WP_Theme>
	 */
	private function inactive(): array {
		$themes = wp_get_themes();
		unset( $themes[ get_stylesheet() ], $themes[ get_template() ] );
		return $themes;
	}
}
