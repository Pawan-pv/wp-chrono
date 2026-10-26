<?php
/**
 * Filesystem helpers for the plugin and theme cleaners.
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Reset;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Files {

	/**
	 * Total size of a file or directory tree. Symlinks are not followed.
	 */
	public static function size( string $path ): int {
		if ( is_file( $path ) ) {
			return (int) filesize( $path );
		}
		if ( ! is_dir( $path ) ) {
			return 0;
		}
		$bytes = 0;
		try {
			$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $items as $item ) {
				if ( $item->isFile() && ! $item->isLink() ) {
					$bytes += (int) $item->getSize();
				}
			}
		} catch ( \UnexpectedValueException $e ) {
			// Unreadable subdirectory: report what could be measured.
		}
		return $bytes;
	}

	/**
	 * Initialises WP_Filesystem without prompting. Fails when the host needs
	 * FTP/SSH credentials, which a REST request cannot ask the user for.
	 *
	 * @return true|WP_Error
	 */
	public static function init_filesystem() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return new WP_Error(
				'wp_chrono_filesystem',
				__( 'WordPress needs FTP/SSH credentials to delete files on this host. Define FS_METHOD and the FTP constants in wp-config.php.', 'wp-chrono' )
			);
		}
		return true;
	}
}
