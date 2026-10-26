<?php
/**
 * Plugin / theme file archives for full update rollback.
 *
 * Before an update overwrites a plugin or theme, its folder is zipped into
 * `{storage}/rollbacks/snap-{id}-{type}-{slug}-{version}.zip` and recorded in
 * the auto-snapshot's meta (`rollback.files`), so "Undo update" can put back
 * the old files together with the old database.
 *
 * Restoring is split in two so it can be paired with the database swap:
 * - stage():  verify the checksum, extract into `wp-content/upgrade/`, then
 *             move the result next to its target as `.wpc-new-{job}-{slug}`
 *             (same directory → same filesystem → rename() is atomic);
 * - commit(): two renames per folder: live → `.wpc-old-…`, staged → live.
 *             A failed extraction therefore never leaves a half-written
 *             plugin folder behind.
 * Dot-prefixed folders are skipped by get_plugins() and theme discovery, so
 * staged copies are invisible to WordPress while they wait.
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Snapshot;

use WP_Error;
use WPChrono\Core\Storage;
use WPChrono\Services\Reset\Files;

defined( 'ABSPATH' ) || exit;

final class FileArchive {

	const DIR = 'rollbacks';


	/**
	 * Plugins / themes an upgrader package is about to replace.
	 *
	 * @param array<string,mixed> $hook_extra From upgrader_pre_install / upgrader_post_install.
	 * @return array<int,array{type:string,slug:string,file:string,path:string,single:bool}>
	 */
	public static function targets( array $hook_extra ): array {
		$targets = array();
		foreach ( array_merge( (array) ( $hook_extra['plugin'] ?? array() ), (array) ( $hook_extra['plugins'] ?? array() ) ) as $file ) {
			$file = wp_normalize_path( (string) $file );
			if ( '' === $file || false !== strpos( $file, '..' ) || WP_CHRONO_BASENAME === $file ) {
				continue; // WP Chrono never rolls itself back mid-request.
			}
			$dir                = dirname( $file );
			$single             = '.' === $dir;
			$slug               = $single ? $file : $dir;
			$targets[ "p:{$slug}" ] = array(
				'type'   => 'plugin',
				'slug'   => $slug,
				'file'   => $file,
				'path'   => WP_PLUGIN_DIR . '/' . $slug,
				'single' => $single,
			);
		}
		foreach ( array_merge( (array) ( $hook_extra['theme'] ?? array() ), (array) ( $hook_extra['themes'] ?? array() ) ) as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug || false !== strpos( $slug, '/' ) || false !== strpos( $slug, '..' ) ) {
				continue;
			}
			$targets[ "t:{$slug}" ] = array(
				'type'   => 'theme',
				'slug'   => $slug,
				'file'   => $slug,
				'path'   => get_theme_root( $slug ) . '/' . $slug,
				'single' => false,
			);
		}
		return array_values( $targets );
	}

	/**
	 * Version currently on disk, read straight from the file header so stale
	 * plugin / theme caches can't report the wrong one.
	 *
	 * @param array{type:string,file:string,path:string} $target
	 */
	public static function version( array $target ): string {
		$header = 'theme' === $target['type'] ? $target['path'] . '/style.css' : WP_PLUGIN_DIR . '/' . $target['file'];
		if ( ! is_file( $header ) ) {
			return '';
		}
		$data = get_file_data( $header, array( 'Version' => 'Version' ) );
		return (string) $data['Version'];
	}

	/**
	 * Zips one plugin / theme into sealed storage.
	 *
	 * @param array{type:string,slug:string,file:string,path:string,single:bool} $target
	 * @return array<string,mixed>|WP_Error Manifest entry.
	 */
	public static function archive( int $snapshot_id, array $target ) {
		$path = $target['path'];
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'wp_chrono_rollback_missing', "{$target['slug']} is not installed." );
		}
		if ( is_link( $path ) ) {
			return new WP_Error( 'wp_chrono_rollback_symlink', "{$target['slug']} is a symlink (development checkout); not archived." );
		}
		$bytes = Files::size( $path );
		$max   = (int) apply_filters( 'wp_chrono_rollback_max_bytes', \WPChrono\Services\Settings::get()['rollback_max_mb'] * MB_IN_BYTES, $target );
		if ( $bytes > $max ) {
			return new WP_Error( 'wp_chrono_rollback_too_big', sprintf( '%s is %s, above the %s rollback limit; not archived.', $target['slug'], size_format( $bytes ), size_format( $max ) ) );
		}

		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$version = self::version( $target );
		$name    = sprintf( 'snap-%d-%s-%s-%s.zip', $snapshot_id, $target['type'], self::safe( $target['slug'] ), self::safe( '' === $version ? 'unknown' : $version ) );
		$zip     = $dir . $name;

		$ok = self::zip( $path, $target['single'], $zip );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$header = 'theme' === $target['type'] ? $path . '/style.css' : WP_PLUGIN_DIR . '/' . $target['file'];
		$label  = is_file( $header ) ? (string) get_file_data( $header, array( 'n' => 'theme' === $target['type'] ? 'Theme Name' : 'Plugin Name' ) )['n'] : '';

		return array(
			'type'        => $target['type'],
			'slug'        => $target['slug'],
			'file'        => $target['file'],
			'name'        => $label,
			'single'      => $target['single'],
			'target'      => wp_normalize_path( $path ),
			'old_version' => $version,
			'new_version' => null,
			'zip'         => $name,
			'sha256'      => hash_file( 'sha256', $zip ),
			'bytes'       => (int) filesize( $zip ),
			'created_at'  => gmdate( 'c' ),
		);
	}

	/**
	 * Verifies, extracts and parks every archive next to its target. Nothing
	 * live is touched; on any failure everything staged so far is removed.
	 *
	 * @param array<int,array<string,mixed>> $entries Manifest entries.
	 * @return array<int,array<string,mixed>>|WP_Error Staged items for commit()/discard().
	 */
	public static function stage( array $entries, string $job ) {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$work = trailingslashit( WP_CONTENT_DIR ) . 'upgrade/wpc-rollback-' . $job . '/';
		if ( ! wp_mkdir_p( $work ) ) {
			return new WP_Error( 'wp_chrono_rollback_io', 'Could not create wp-content/upgrade for staging the rollback.' );
		}

		$staged = array();
		foreach ( array_values( $entries ) as $i => $entry ) {
			$item = self::stage_one( $entry, $dir, $work . $i . '/', $job );
			if ( is_wp_error( $item ) ) {
				self::discard( $staged, $job );
				return $item;
			}
			$staged[] = $item;
		}
		self::rmtree( $work );
		return $staged;
	}

	/**
	 * Swaps staged folders into place. Each folder swap is two renames inside
	 * the same directory; if the second fails the live folder is put back.
	 *
	 * @param array<int,array<string,mixed>> $staged
	 * @return array{restored:int,log:string[]}
	 */
	public static function commit( array $staged ): array {
		$log      = array();
		$restored = 0;
		foreach ( $staged as $item ) {
			$target = $item['target'];
			$parked = false;
			if ( file_exists( $target ) || is_link( $target ) ) {
				if ( ! @rename( $target, $item['old'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename
					$log[] = "Could not move the current {$item['slug']} aside; it was left unchanged.";
					self::rmtree( $item['new'] );
					continue;
				}
				$parked = true;
			}
			if ( ! @rename( $item['new'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename
				if ( $parked ) {
					@rename( $item['old'], $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename
				}
				self::rmtree( $item['new'] );
				$log[] = "Could not swap in the archived {$item['slug']}; the current version was kept.";
				continue;
			}
			self::rmtree( $item['old'] );
			$restored++;
			$log[] = sprintf(
				'Reverted %s %s%s.',
				$item['type'],
				$item['slug'],
				'' !== (string) $item['old_version'] ? sprintf( ' to v%s', $item['old_version'] ) : ''
			);
			if ( ! empty( $item['installed_to'] ) ) {
				$stray = self::remove_stray_install( $item );
				if ( null !== $stray ) {
					$log[] = "Removed {$stray}/, the folder the update had moved this single-file plugin into.";
				}
			}
		}
		$cleared = self::flush_caches();
		return array( 'restored' => $restored, 'log' => $log, 'opcache_cleared' => $cleared );
	}

	/**
	 * Removes staged copies without touching live folders.
	 *
	 * @param array<int,array<string,mixed>> $staged
	 */
	public static function discard( array $staged, string $job ): void {
		foreach ( $staged as $item ) {
			self::rmtree( (string) $item['new'] );
		}
		self::rmtree( trailingslashit( WP_CONTENT_DIR ) . 'upgrade/wpc-rollback-' . $job );
	}

	/**
	 * Deletes archive files of a snapshot's manifest.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 */
	public static function delete( array $entries ): void {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return;
		}
		foreach ( $entries as $entry ) {
			$name = basename( (string) ( $entry['zip'] ?? '' ) );
			if ( '' !== $name && is_file( $dir . $name ) ) {
				@unlink( $dir . $name ); // phpcs:ignore
			}
		}
	}

	/**
	 * Absolute path of an archive, or '' when it is missing.
	 *
	 * @param array<string,mixed> $entry
	 */
	public static function path( array $entry ): string {
		$dir  = self::dir();
		$name = basename( (string) ( $entry['zip'] ?? '' ) );
		return ( ! is_wp_error( $dir ) && '' !== $name && is_file( $dir . $name ) ) ? $dir . $name : '';
	}

	/**
	 * Total bytes of every rollback archive on disk.
	 */
	public static function total_bytes(): int {
		$dir = self::dir();
		return is_wp_error( $dir ) ? 0 : Files::size( $dir );
	}

	/**
	 * @return string|WP_Error `{storage}/rollbacks/` with trailing slash.
	 */
	public static function dir() {
		$base = Storage::ensure();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$dir = $base . self::DIR . '/';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'wp_chrono_rollback_io', 'Could not create the rollback archive folder.' );
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	/**
	 * Puts the site in WordPress maintenance mode for the table + folder swap.
	 * wp_maintenance() runs before plugins load, so no request can run the
	 * updated code against the restored database (or write its data back)
	 * between the two swaps. Returns false if a .maintenance file already
	 * existed — it is then left for its owner to remove.
	 */
	public static function maintenance_on(): bool {
		$file = ABSPATH . '.maintenance';
		if ( file_exists( $file ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === @file_put_contents( $file, '<?php $upgrading = ' . time() . ';' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		// Let requests that loaded the old code just before this finish first.
		usleep( 500000 );
		return true;
	}

	/**
	 * @param bool $settle Folders were swapped but the web server's opcode
	 *                     cache could not be cleared from here (WP-CLI,
	 *                     restricted opcache_reset): PHP-FPM keeps running the
	 *                     replaced code until OPcache re-checks the files, so
	 *                     stay offline until it has.
	 */
	public static function maintenance_off( bool $created, bool $settle = false ): void {
		if ( ! $created ) {
			return;
		}
		if ( $settle ) {
			/**
			 * Seconds to stay in maintenance mode after a folder swap that could
			 * not clear the web server's OPcache (default: opcache.revalidate_freq + 1).
			 *
			 * @param int $seconds
			 */
			$seconds = (int) apply_filters( 'wp_chrono_opcache_settle', max( 0, (int) ini_get( 'opcache.revalidate_freq' ) ) + 1 );
			if ( $seconds > 0 ) {
				sleep( min( 30, $seconds ) );
			}
		}
		@unlink( ABSPATH . '.maintenance' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * Clears WordPress' plugin / theme caches and PHP's opcode cache so the
	 * reverted code is what runs on the next request.
	 *
	 * @return bool Whether the web server's opcode cache is known to be clean:
	 *              only from a web request, where opcache_reset() reaches the
	 *              same cache visitors use (or no cache is on).
	 */
	public static function flush_caches(): bool {
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache();
		}
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache();
		}
		$enabled = filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN );
		$reset   = $enabled && function_exists( 'opcache_reset' ) && @opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- may be restricted by opcache.restrict_api.
		return 'cli' !== PHP_SAPI && ( $reset || ! $enabled );
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $entry
	 * @return array<string,mixed>|WP_Error
	 */
	private static function stage_one( array $entry, string $dir, string $work, string $job ) {
		$slug   = (string) ( $entry['slug'] ?? '' );
		$single = ! empty( $entry['single'] );
		$target = wp_normalize_path( (string) ( $entry['target'] ?? '' ) );
		$zip    = $dir . basename( (string) ( $entry['zip'] ?? '' ) );

		if ( '' === $slug || basename( $target ) !== basename( $slug ) || ! is_dir( dirname( $target ) ) ) {
			return new WP_Error( 'wp_chrono_rollback_manifest', "The rollback manifest for {$slug} is invalid." );
		}
		if ( ! is_file( $zip ) ) {
			return new WP_Error( 'wp_chrono_rollback_missing', "The archive of {$slug} is missing from storage." );
		}
		if ( ! hash_equals( (string) ( $entry['sha256'] ?? '' ), (string) hash_file( 'sha256', $zip ) ) ) {
			return new WP_Error( 'wp_chrono_rollback_checksum', "The archive of {$slug} failed its SHA-256 check; nothing was changed." );
		}

		$root = basename( $slug );
		$ok   = self::unzip( $zip, $work, $root, $single );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$extracted = $work . $root;
		$expected  = $single ? $extracted : ( 'theme' === $entry['type'] ? $extracted . '/style.css' : $extracted . '/' . basename( (string) $entry['file'] ) );
		if ( ! file_exists( $expected ) ) {
			return new WP_Error( 'wp_chrono_rollback_incomplete', "The archive of {$slug} is incomplete; nothing was changed." );
		}

		$parent = dirname( $target );
		$new    = $parent . '/.wpc-new-' . $job . '-' . basename( $slug );
		self::rmtree( $new );
		if ( ! self::move( $extracted, $new ) ) {
			return new WP_Error( 'wp_chrono_rollback_io', "Could not stage {$slug} next to its folder; nothing was changed." );
		}

		return array(
			'type'         => (string) $entry['type'],
			'slug'         => $slug,
			'name'         => (string) ( $entry['name'] ?? '' ),
			'target'       => $target,
			'new'          => $new,
			'old'          => $parent . '/.wpc-old-' . $job . '-' . basename( $slug ),
			'old_version'  => (string) ( $entry['old_version'] ?? '' ),
			'installed_to' => ! empty( $entry['single'] ) ? (string) ( $entry['installed_to'] ?? '' ) : '',
		);
	}

	/**
	 * Version of the plugin inside a folder, matched by Plugin Name ('' if none).
	 */
	public static function folder_plugin_version( string $dir, string $name ): string {
		$main = self::folder_plugin_file( $dir, $name );
		return '' === $main ? '' : (string) get_file_data( $main, array( 'v' => 'Version' ) )['v'];
	}

	/**
	 * A top-level PHP file in $dir whose Plugin Name header equals $name.
	 */
	private static function folder_plugin_file( string $dir, string $name ): string {
		if ( '' === $name || ! is_dir( $dir ) ) {
			return '';
		}
		foreach ( (array) glob( $dir . '/*.php' ) as $file ) {
			if ( trim( (string) get_file_data( $file, array( 'n' => 'Plugin Name' ) )['n'] ) === trim( $name ) ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * After a single-file plugin is put back: removes the folder WordPress
	 * installed the update into, but only when it sits directly in the
	 * plugins directory, isn't the restored file, and holds the same plugin.
	 *
	 * @param array<string,mixed> $item Staged item.
	 */
	private static function remove_stray_install( array $item ): ?string {
		$dir = untrailingslashit( wp_normalize_path( (string) $item['installed_to'] ) );
		if ( '' === $dir || $dir === $item['target'] || wp_normalize_path( dirname( $dir ) ) !== untrailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ) || is_link( $dir ) ) {
			return null;
		}
		if ( '' === self::folder_plugin_file( $dir, (string) $item['name'] ) ) {
			return null;
		}
		$parked = dirname( $dir ) . '/.wpc-old-stray-' . basename( $dir );
		if ( ! @rename( $dir, $parked ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename
			return null;
		}
		self::rmtree( $parked );
		return basename( $dir );
	}

	/**
	 * Zips a folder (entries prefixed with its name, the layout WordPress
	 * packages use) or a single file. Symlinks are skipped.
	 *
	 * @return true|WP_Error
	 */
	public static function zip( string $path, bool $single, string $zip_file ) {
		$parent = dirname( $path );
		$files  = array();
		if ( $single ) {
			$files[ basename( $path ) ] = $path;
		} else {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
			$files[ basename( $path ) . '/' ] = null;
			foreach ( $iterator as $item ) {
				if ( $item->isLink() ) {
					continue;
				}
				$rel = basename( $path ) . '/' . str_replace( '\\', '/', substr( $item->getPathname(), strlen( $path ) + 1 ) );
				$files[ $item->isDir() ? $rel . '/' : $rel ] = $item->isDir() ? null : $item->getPathname();
			}
		}

		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				return new WP_Error( 'wp_chrono_rollback_zip', 'Could not create the rollback archive.' );
			}
			foreach ( $files as $name => $source ) {
				null === $source ? $zip->addEmptyDir( rtrim( $name, '/' ) ) : $zip->addFile( $source, $name );
			}
			return $zip->close() ? true : new WP_Error( 'wp_chrono_rollback_zip', 'Could not write the rollback archive.' );
		}

		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$pcl  = new \PclZip( $zip_file );
		$list = $pcl->create( array( $path ), PCLZIP_OPT_REMOVE_PATH, $parent );
		return 0 === $list ? new WP_Error( 'wp_chrono_rollback_zip', 'Could not create the rollback archive: ' . $pcl->errorInfo( true ) ) : true;
	}

	/**
	 * Extracts an archive, refusing any entry outside its expected root.
	 *
	 * @return true|WP_Error
	 */
	private static function unzip( string $zip_file, string $to, string $root, bool $single ) {
		if ( ! wp_mkdir_p( $to ) ) {
			return new WP_Error( 'wp_chrono_rollback_io', 'Could not create the staging folder.' );
		}
		$safe = static function ( string $name ) use ( $root, $single ): bool {
			$name = str_replace( '\\', '/', $name );
			if ( '' === $name || '/' === $name[0] || preg_match( '#(^|/)\.\.(/|$)#', $name ) || preg_match( '/^[A-Za-z]:/', $name ) ) {
				return false;
			}
			return $single ? $name === $root : ( $name === $root . '/' || 0 === strpos( $name, $root . '/' ) );
		};

		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $zip_file ) ) {
				return new WP_Error( 'wp_chrono_rollback_zip', 'Could not open the rollback archive.' );
			}
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				if ( ! $safe( (string) $zip->getNameIndex( $i ) ) ) {
					$zip->close();
					return new WP_Error( 'wp_chrono_rollback_zip', 'The rollback archive contains unexpected paths; nothing was changed.' );
				}
			}
			$ok = $zip->extractTo( $to );
			$zip->close();
			return $ok ? true : new WP_Error( 'wp_chrono_rollback_zip', 'Extracting the rollback archive failed; nothing was changed.' );
		}

		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$pcl  = new \PclZip( $zip_file );
		$list = $pcl->listContent();
		if ( ! is_array( $list ) ) {
			return new WP_Error( 'wp_chrono_rollback_zip', 'Could not open the rollback archive.' );
		}
		foreach ( $list as $file ) {
			if ( ! $safe( (string) $file['filename'] ) ) {
				return new WP_Error( 'wp_chrono_rollback_zip', 'The rollback archive contains unexpected paths; nothing was changed.' );
			}
		}
		return 0 === $pcl->extract( PCLZIP_OPT_PATH, $to ) ? new WP_Error( 'wp_chrono_rollback_zip', 'Extracting the rollback archive failed.' ) : true;
	}

	/**
	 * rename(), falling back to copy + delete when source and destination are
	 * on different filesystems (rename() can't move directories across them).
	 */
	private static function move( string $from, string $to ): bool {
		if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename
			return true;
		}
		if ( is_file( $from ) ) {
			return @copy( $from, $to ) && @unlink( $from ); // phpcs:ignore
		}
		if ( ! wp_mkdir_p( $to ) ) {
			return false;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $from, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $item ) {
			$dest = $to . '/' . substr( $item->getPathname(), strlen( $from ) + 1 );
			if ( $item->isDir() ? ! wp_mkdir_p( $dest ) : ! @copy( $item->getPathname(), $dest ) ) { // phpcs:ignore
				self::rmtree( $to );
				return false;
			}
		}
		self::rmtree( $from );
		return true;
	}

	/**
	 * Recursive delete that never follows symlinks.
	 */
	public static function rmtree( string $path ): void {
		if ( '' === $path || ( ! file_exists( $path ) && ! is_link( $path ) ) ) {
			return;
		}
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			( $item->isDir() && ! $item->isLink() ) ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); // phpcs:ignore
		}
		@rmdir( $path ); // phpcs:ignore
	}

	private static function safe( string $value ): string {
		return trim( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $value ), '-' );
	}
}
