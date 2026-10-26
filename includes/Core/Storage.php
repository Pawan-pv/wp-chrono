<?php
/**
 * Private on-disk storage for exports (.sql.gz / .zip).
 *
 * The directory name carries a random suffix, and it is sealed with .htaccess
 * (Apache 2.2 + 2.4), web.config (IIS) and index.php. Nginx ignores all three,
 * which is why every file inside also gets a 32-char random token in its name.
 *
 * @package WPChrono
 */

namespace WPChrono\Core;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Storage {

	const OPTION_DIR = 'wp_chrono_storage_dir';

	/**
	 * Absolute path (with trailing slash) of the protected directory, created and
	 * sealed on demand.
	 *
	 * @return string|WP_Error
	 */
	public static function ensure() {
		$name = get_option( self::OPTION_DIR );
		if ( ! is_string( $name ) || ! preg_match( '/^wp-chrono-[A-Za-z0-9]{16}$/', $name ) ) {
			$name = 'wp-chrono-' . wp_generate_password( 16, false );
			update_option( self::OPTION_DIR, $name, false );
		}

		$dir = trailingslashit( WP_CONTENT_DIR ) . $name . '/';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'wp_chrono_storage', __( 'Unable to create the WP Chrono storage directory in wp-content.', 'wp-chrono' ) );
		}

		foreach ( self::guard_files() as $file => $contents ) {
			if ( ! file_exists( $dir . $file ) && false === file_put_contents( $dir . $file, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- wp-content/wp-chrono-<random>/, not the plugin folder.
				return new WP_Error( 'wp_chrono_storage', __( 'Unable to write protection files to the WP Chrono storage directory.', 'wp-chrono' ) );
			}
		}

		return $dir;
	}

	/**
	 * Unguessable file name for an export, e.g. snapshot-20261025-101500-<32 chars>.sql.gz
	 */
	public static function filename( string $kind, string $extension ): string {
		return sprintf(
			'%s-%s-%s.%s',
			sanitize_key( $kind ),
			gmdate( 'Ymd-His' ),
			wp_generate_password( 32, false ),
			ltrim( $extension, '.' )
		);
	}

	public static function is_protected(): bool {
		$name = get_option( self::OPTION_DIR );
		if ( ! is_string( $name ) || '' === $name ) {
			return false;
		}
		$dir = trailingslashit( WP_CONTENT_DIR ) . $name . '/';
		foreach ( array_keys( self::guard_files() ) as $file ) {
			if ( ! file_exists( $dir . $file ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Removes the directory and everything in it. Used by uninstall.
	 */
	public static function destroy(): void {
		$name = get_option( self::OPTION_DIR );
		if ( ! is_string( $name ) || ! preg_match( '/^wp-chrono-[A-Za-z0-9]{16}$/', $name ) ) {
			return;
		}
		$dir = trailingslashit( WP_CONTENT_DIR ) . $name;
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * @return array<string,string>
	 */
	private static function guard_files(): array {
		return array(
			'.htaccess'  => "# Apache 2.4\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n# Apache 2.2\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t\t<security>\n\t\t\t<requestFiltering>\n\t\t\t\t<fileExtensions allowUnlisted=\"false\" />\n\t\t\t</requestFiltering>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
	}
}
