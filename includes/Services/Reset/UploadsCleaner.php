<?php
/**
 * Deletes everything inside wp-content/uploads.
 *
 * Kept: every `.htaccess` / `web.config` (plugins such as WooCommerce use them
 * to protect private downloads), the root `index.php`, and every top-level
 * backup folder written by a known backup or security plugin (UpdraftPlus,
 * All-in-One WP Migration, WPvivid, Duplicator, BackWPup, BlogVault, …) plus
 * WP Chrono's own storage. A competitor's 1★ review describes a reset that
 * "emptied the backup folders too"; this cleaner never descends into them.
 * Symlinks are unlinked, never followed. Media library entries in the
 * database are left alone.
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Reset;

defined( 'ABSPATH' ) || exit;

final class UploadsCleaner extends Cleaner {

	/** Seconds a preview may spend walking the tree before reporting an estimate. */
	const PREVIEW_BUDGET = 1.5;

	const KEEP_FILES = array( '.htaccess', 'web.config' );

	/**
	 * Top-level folders in uploads that are never touched. A trailing `*`
	 * matches any suffix (e.g. `backwpup-1a2b3c-backups`). Case-insensitive.
	 * Extend with the `wp_chrono_protected_upload_dirs` filter.
	 */
	const PROTECTED_DIRS = array(
		'wp-chrono*',          // WP Chrono storage.
		'updraft',             // UpdraftPlus.
		'ai1wm-backups',       // All-in-One WP Migration.
		'wpvividbackups',      // WPvivid.
		'wpvivid*',
		'backups-dup-pro',     // Duplicator Pro.
		'backups-dup-lite',    // Duplicator.
		'backwpup*',           // BackWPup.
		'blogvault*',          // BlogVault.
		'bv-*',
		'itsec*',              // Solid Security (iThemes).
		'sucuri',              // Sucuri.
		'backupbuddy_backups', // BackupBuddy.
		'pb_backupbuddy',
		'wp-staging',          // WP Staging backups.
		'jetpack-backup*',
		'wpo-backups',
		'everest-backup*',
		'backup-guard',        // JetBackup / Backup Guard.
		'snapshots',           // Snapshot Pro (WPMU DEV).
		'wp-migrate-db',
	);

	public function availability() {
		if ( is_multisite() ) {
			return 'Not available on multisite: the main site\'s uploads folder contains every subsite\'s files.';
		}
		return null === $this->base_dir() ? 'The uploads directory could not be located safely.' : true;
	}

	public function preview(): array {
		$base = $this->base_dir();
		if ( null === $base ) {
			return self::summary( 0, 0 );
		}

		$deadline = microtime( true ) + self::PREVIEW_BUDGET;
		$files    = 0;
		$bytes    = 0;
		$partial  = false;
		try {
			foreach ( $this->walk( $base ) as $item ) {
				if ( $item->isFile() && ! $item->isLink() && ! $this->is_kept( $item, $base ) ) {
					$files++;
					$bytes += (int) $item->getSize();
				}
				if ( self::out_of_time( $deadline ) ) {
					$partial = true;
					break;
				}
			}
		} catch ( \UnexpectedValueException $e ) {
			$partial = true; // Unreadable subdirectory.
		}

		$details   = array( $base );
		$protected = $this->protected_found( $base );
		if ( $protected ) {
			$details[] = sprintf(
				'Protected: %d backup %s kept untouched (%s)',
				count( $protected ),
				1 === count( $protected ) ? 'folder' : 'folders',
				implode( ', ', $protected )
			);
		}
		return self::summary( $files, $bytes, $details, $partial ) + array( 'protected' => $protected );
	}

	/**
	 * Names of protected backup folders that exist in uploads right now.
	 *
	 * @return string[]
	 */
	public function protected_found( ?string $base = null ): array {
		$base = $base ?? $this->base_dir();
		if ( null === $base ) {
			return array();
		}
		$found = array();
		foreach ( (array) @scandir( $base ) as $entry ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( '' !== $entry && '.' !== $entry[0] && is_dir( $base . '/' . $entry ) && ! is_link( $base . '/' . $entry ) && self::is_protected_dir( $entry ) ) {
				$found[] = $entry;
			}
		}
		sort( $found );
		return $found;
	}

	public static function is_protected_dir( string $name ): bool {
		$name     = strtolower( $name );
		$patterns = (array) apply_filters( 'wp_chrono_protected_upload_dirs', self::PROTECTED_DIRS );
		foreach ( $patterns as $pattern ) {
			$pattern = strtolower( (string) $pattern );
			if ( '*' === substr( $pattern, -1 ) ? 0 === strpos( $name, substr( $pattern, 0, -1 ) ) : $name === $pattern ) {
				return true;
			}
		}
		return false;
	}

	public function run( array $options, float $deadline ): array {
		$base = $this->base_dir();
		if ( null === $base ) {
			return self::result( true, 0, array( 'Uploads directory not found; nothing to do.' ) );
		}

		$deleted = 0;
		$failed  = 0;
		$log     = array();
		try {
			foreach ( $this->walk( $base ) as $item ) {
				$path = $item->getPathname();
				if ( $item->isLink() ) {
					@unlink( $path ); // phpcs:ignore
					$deleted++;
				} elseif ( $item->isDir() ) {
					@rmdir( $path ); // phpcs:ignore -- fails harmlessly when a kept file remains inside.
				} elseif ( ! $this->is_kept( $item, $base ) ) {
					if ( @unlink( $path ) ) { // phpcs:ignore
						$deleted++;
					} else {
						$failed++;
					}
				}

				if ( self::out_of_time( $deadline ) ) {
					return self::result( false, $deleted, array( sprintf( 'Deleted %d files so far…', $deleted ) ) );
				}
			}
		} catch ( \UnexpectedValueException $e ) {
			// An unreadable directory would fail the same way on every tick, so stop here.
			$log[] = 'Error: ' . $e->getMessage();
		}

		array_unshift( $log, sprintf( 'Deleted %d files from %s.', $deleted, $base ) );
		$protected = $this->protected_found( $base );
		if ( $protected ) {
			$log[] = sprintf( 'Kept %d protected backup folder(s): %s.', count( $protected ), implode( ', ', $protected ) );
		}
		if ( $failed ) {
			$log[] = sprintf( '%d files could not be deleted (check file permissions).', $failed );
		}
		return self::result( true, $deleted, $log );
	}

	/**
	 * The uploads base dir, or null if it is missing or suspiciously broad
	 * (filesystem root, ABSPATH or wp-content itself).
	 */
	private function base_dir(): ?string {
		$uploads = wp_get_upload_dir();
		$base    = realpath( $uploads['basedir'] );
		if ( false === $base || ! is_dir( $base ) ) {
			return null;
		}
		if ( dirname( $base ) === $base ) {
			return null; // Filesystem root.
		}
		foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $dir ) {
			$real = realpath( $dir );
			if ( false !== $real && untrailingslashit( $real ) === untrailingslashit( $base ) ) {
				return null;
			}
		}
		return $base;
	}

	/**
	 * Children-first walk that never descends into protected top-level
	 * backup folders (see PROTECTED_DIRS).
	 *
	 * @return \RecursiveIteratorIterator<\RecursiveCallbackFilterIterator>
	 */
	private function walk( string $base ): \RecursiveIteratorIterator {
		$dir    = new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS );
		$filter = new \RecursiveCallbackFilterIterator(
			$dir,
			static function ( \SplFileInfo $item ) use ( $base ) {
				return ! ( $item->isDir() && ! $item->isLink() && $item->getPath() === $base && self::is_protected_dir( $item->getFilename() ) );
			}
		);
		return new \RecursiveIteratorIterator( $filter, \RecursiveIteratorIterator::CHILD_FIRST );
	}

	private function is_kept( \SplFileInfo $item, string $base ): bool {
		if ( in_array( $item->getFilename(), self::KEEP_FILES, true ) ) {
			return true;
		}
		return 'index.php' === $item->getFilename() && $item->getPath() === $base;
	}
}
