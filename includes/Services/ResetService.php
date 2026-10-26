<?php
/**
 * Full and partial site resets.
 *
 * Partial resets delegate to resumable Cleaner classes (see Reset\Cleaner).
 * The full reset is one request by necessity — wp_install() can't be split —
 * but dropping tables is metadata-only, so it stays well under typical limits.
 *
 * @package WPChrono
 */

namespace WPChrono\Services;

use WP_Error;
use WPChrono\Core\Schema;
use WPChrono\Services\Reset\Cleaner;

defined( 'ABSPATH' ) || exit;

final class ResetService {

	/** Seconds of work per REST tick before handing control back to the client. */
	const TICK_BUDGET = 5.0;

	const CONFIRM_WORD = 'RESET';

	/**
	 * Options carried over a full reset, in addition to DB-stored salts
	 * (Session::SALT_OPTIONS) and every `wp_chrono_*` option.
	 */
	const RETAINED_OPTIONS = array( 'blogname', 'siteurl', 'home', 'WPLANG', 'blog_public' );

	/**
	 * @return array<string,class-string<Cleaner>>
	 */
	public static function targets(): array {
		return array(
			'transients'       => Reset\TransientsCleaner::class,
			'woocommerce'      => Reset\WooCommerceCleaner::class,
			'uploads'          => Reset\UploadsCleaner::class,
			'inactive_plugins' => Reset\InactivePluginsCleaner::class,
			'inactive_themes'  => Reset\InactiveThemesCleaner::class,
			'custom_tables'    => Reset\CustomTablesCleaner::class,
			'orphaned_meta'    => Reset\OrphanedMetaCleaner::class,
			'options'          => Reset\OptionsCleaner::class,
		);
	}

	/**
	 * @return Cleaner|WP_Error
	 */
	public function cleaner( string $target ) {
		$targets = self::targets();
		if ( ! isset( $targets[ $target ] ) ) {
			return new WP_Error( 'wp_chrono_unknown_target', sprintf( 'Unknown reset target "%s".', $target ), array( 'status' => 404 ) );
		}
		return new $targets[ $target ]();
	}

	/**
	 * Dry-run summary for one target.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function preview( string $target ) {
		$cleaner = $this->cleaner( $target );
		if ( is_wp_error( $cleaner ) ) {
			return $cleaner;
		}

		$available = $cleaner->availability();
		$base      = array(
			'target'    => $target,
			'available' => true === $available,
			'reason'    => true === $available ? null : $available,
		);

		if ( true !== $available ) {
			return $base + array( 'items' => 0, 'bytes' => 0, 'approximate' => false, 'details' => array() );
		}
		return $base + $cleaner->preview();
	}

	/**
	 * Runs (or dry-runs) one tick of a partial reset.
	 *
	 * @param array<string,mixed> $options Cleaner options plus `cursor`.
	 * @param float               $budget  Seconds to work before returning; INF for CLI.
	 * @return array<string,mixed>|WP_Error
	 */
	public function partial_reset( string $target, bool $dry_run = false, array $options = array(), float $budget = self::TICK_BUDGET ) {
		if ( $dry_run ) {
			return $this->preview( $target );
		}

		$cleaner = $this->cleaner( $target );
		if ( is_wp_error( $cleaner ) ) {
			return $cleaner;
		}
		$available = $cleaner->availability();
		if ( true !== $available ) {
			return new WP_Error( 'wp_chrono_unavailable', $available, array( 'status' => 409 ) );
		}

		$started = microtime( true );
		$result  = $cleaner->run( $options, $started + $budget );

		if ( $result['done'] ) {
			do_action( 'wp_chrono_after_reset', $target, $result );
		}

		return array( 'target' => $target ) + $result + array( 'duration_ms' => self::ms_since( $started ) );
	}

	/**
	 * Records the pre-reset state and gives the snapshot engine a chance to
	 * take a safety snapshot through the `wp_chrono_safety_snapshot` filter.
	 *
	 * @return array<string,mixed>
	 */
	public function preflight( string $target, bool $want_snapshot ): array {
		$tables = Database::site_tables();
		$state  = array(
			'target'     => $target,
			'tables'     => count( $tables ),
			'rows'       => array_sum( array_column( $tables, 'rows' ) ),
			'bytes'      => array_sum( array_column( $tables, 'bytes' ) ),
			'checked_at' => gmdate( 'c' ),
		);
		$log    = array(
			sprintf( 'Pre-flight check: %d tables, %s, ~%s rows.', $state['tables'], size_format( $state['bytes'], 1 ), number_format_i18n( $state['rows'] ) ),
		);

		$snapshot       = null;
		$snapshot_error = null;
		if ( $want_snapshot ) {
			/**
			 * Lets the snapshot engine take a safety snapshot before a reset.
			 *
			 * @param array|null $snapshot Null when no engine handled it.
			 * @param string     $target   Reset target, or 'full'.
			 * @param array      $state    Pre-reset table summary.
			 */
			$snapshot = apply_filters( 'wp_chrono_safety_snapshot', null, $target, $state );
			if ( null === $snapshot ) {
				$log[] = 'Safety snapshot requested, but no snapshot engine handled it. Continuing without one.';
			} elseif ( is_wp_error( $snapshot ) ) {
				$snapshot_error = $snapshot->get_error_message();
				$log[]          = 'Safety snapshot failed: ' . $snapshot_error;
				$snapshot       = null;
			} else {
				$log[] = 'ready' === $snapshot['status']
					? sprintf( 'Safety snapshot #%d created (%d tables).', $snapshot['id'], $snapshot['table_count'] )
					: sprintf( 'Safety snapshot #%d started (%d tables, copied in batches).', $snapshot['id'], $snapshot['table_count'] );
			}
		}

		update_option( 'wp_chrono_last_preflight', $state, false );
		do_action( 'wp_chrono_before_reset', $target, $state, $snapshot );

		return array(
			'state'          => $state,
			'snapshot'       => $snapshot,
			'snapshot_error' => $snapshot_error,
			'log'            => $log,
		);
	}

	/**
	 * Drops every site table and reinstalls WordPress, keeping the acting admin
	 * signed in with the same ID, password hash and sessions.
	 *
	 * @param array{keep_theme?:bool,keep_plugins?:bool,keep_users?:bool} $opts
	 * @return array<string,mixed>|WP_Error
	 */
	public function full_reset( array $opts = array() ) {
		global $wpdb;

		if ( is_multisite() ) {
			return new WP_Error( 'wp_chrono_multisite', __( 'Full reset is not supported on multisite networks.', 'wp-chrono' ), array( 'status' => 409 ) );
		}

		$keep_theme   = ! empty( $opts['keep_theme'] );
		$keep_plugins = ! empty( $opts['keep_plugins'] );
		$keep_users   = ! empty( $opts['keep_users'] );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$started = microtime( true );
		$steps   = array();
		$step    = static function ( string $label ) use ( &$steps, $started ) {
			$now     = microtime( true );
			$steps[] = array( 'label' => $label, 'ms' => (int) round( ( $now - $started ) * 1000 ) );
		};

		// 1. Capture everything that must survive.
		$user = Session::acting_admin();
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		$user_row       = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID = %d", $user->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$session_tokens = get_user_meta( $user->ID, 'session_tokens', true );
		$options        = $this->capture_options( $keep_users );
		$stylesheet     = get_stylesheet();
		$active_plugins = array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( WP_CHRONO_BASENAME ) ) );
		$step( sprintf( 'Captured admin "%s", %d options, theme and %d active plugins', $user_row['user_login'], count( $options ), count( $active_plugins ) ) );

		// 2. Drop site tables, keeping WP Chrono's tables and every snapshot copy.
		$keep = Schema::internal_tables();
		if ( $keep_users ) {
			$keep[] = $wpdb->users;
			$keep[] = $wpdb->usermeta;
		}
		$tables = array_diff( array_keys( Database::site_tables() ), $keep );
		Database::without_foreign_keys(
			static function () use ( $tables, $wpdb ) {
				foreach ( $tables as $table ) {
					$wpdb->query( 'DROP TABLE IF EXISTS ' . Database::quote_identifier( $table ) ); // phpcs:ignore WordPress.DB
				}
			}
		);
		wp_cache_flush();
		$step( sprintf( 'Dropped %d tables', count( $tables ) ) );

		// 3. Reinstall. Mail is suppressed so the "new site" notification isn't sent.
		$no_mail = '__return_false';
		add_filter( 'pre_wp_mail', $no_mail );
		$installed = @wp_install( // phpcs:ignore WordPress.PHP.NoSilencedErrors
			(string) ( $options['blogname'] ?? 'WordPress' ),
			$user_row['user_login'],
			$user_row['user_email'],
			(int) ( $options['blog_public'] ?? 1 ),
			'',
			wp_generate_password( 32 ),
			(string) ( $options['WPLANG'] ?? '' )
		);
		remove_filter( 'pre_wp_mail', $no_mail );
		$step( 'Ran wp_install()' );

		// 4. Restore the admin's identity: same ID, password hash and sessions.
		$user_id = $this->restore_user( (int) $installed['user_id'], $user_row, $keep_users );
		if ( is_array( $session_tokens ) && $session_tokens ) {
			update_user_meta( $user_id, 'session_tokens', $session_tokens );
		}
		delete_user_meta( $user_id, 'default_password_nag' );
		delete_user_meta( $user_id, $wpdb->get_blog_prefix() . 'default_password_nag' );
		clean_user_cache( $user_id );
		$step( 'Restored admin account, password hash and sessions' );

		// 5. Restore options, theme and plugins.
		Session::restore_options( $options );
		update_option( 'active_plugins', array( WP_CHRONO_BASENAME ) );
		wp_cache_flush();

		if ( $keep_theme && $stylesheet !== get_stylesheet() && wp_get_theme( $stylesheet )->exists() ) {
			switch_theme( $stylesheet );
		}

		$plugin_errors = array();
		if ( $keep_plugins ) {
			foreach ( $active_plugins as $plugin ) {
				try {
					// Not silent: activation hooks must run so plugins recreate their tables.
					$result = activate_plugin( $plugin );
					if ( is_wp_error( $result ) && 'unexpected_output' !== $result->get_error_code() ) {
						$plugin_errors[] = $plugin . ': ' . $result->get_error_message();
					}
				} catch ( \Throwable $e ) {
					$plugin_errors[] = $plugin . ': ' . $e->getMessage();
				}
			}
		}
		$step(
			sprintf(
				'Restored settings%s%s',
				$keep_theme ? ', theme' : '',
				$keep_plugins ? sprintf( ' and %d plugins', count( $active_plugins ) - count( $plugin_errors ) ) : ''
			)
		);

		// 6. Keep the browser session alive and hand fresh nonces back to the SPA.
		$nonces = Session::refresh( $user_id );
		if ( $nonces ) {
			$step( 'Refreshed auth cookie and nonces' );
		}

		$result = array(
			'duration_ms'    => self::ms_since( $started ),
			'tables_dropped' => count( $tables ),
			'user_id'        => $user_id,
			'steps'          => $steps,
			'plugin_errors'  => $plugin_errors,
			'nonces'         => $nonces,
			'redirect'       => admin_url( 'admin.php?page=wp-chrono' ),
		);

		do_action( 'wp_chrono_after_reset', 'full', $result );

		return $result;
	}

	/**
	 * @return array<string,mixed> Options that exist now, keyed by name.
	 */
	private function capture_options( bool $keep_users ): array {
		global $wpdb;
		$names = array_merge( self::RETAINED_OPTIONS, Session::SALT_OPTIONS );
		if ( $keep_users ) {
			// Custom roles live in options; without them retained users lose their capabilities.
			$names[] = $wpdb->get_blog_prefix() . 'user_roles';
		}
		return Session::capture_options( $names, array( 'wp_chrono_' ) );
	}

	/**
	 * Moves the freshly installed admin back to its original ID and copies the
	 * original row (password hash included) over the new one.
	 *
	 * @param array<string,string> $row Original wp_users row.
	 */
	private function restore_user( int $new_id, array $row, bool $users_kept ): int {
		global $wpdb;
		$old_id = (int) $row['ID'];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( ! $users_kept && $new_id !== $old_id ) {
			$wpdb->update( $wpdb->users, array( 'ID' => $old_id ), array( 'ID' => $new_id ) );
			$wpdb->update( $wpdb->usermeta, array( 'user_id' => $old_id ), array( 'user_id' => $new_id ) );
			$wpdb->update( $wpdb->posts, array( 'post_author' => $old_id ), array( 'post_author' => $new_id ) );
		}

		$wpdb->update(
			$wpdb->users,
			array(
				'user_pass'           => $row['user_pass'],
				'user_nicename'       => $row['user_nicename'],
				'user_url'            => $row['user_url'],
				'user_registered'     => $row['user_registered'],
				'display_name'        => $row['display_name'],
				'user_activation_key' => '',
			),
			array( 'ID' => $old_id )
		);
		// phpcs:enable

		return $old_id;
	}

	private static function ms_since( float $started ): int {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}
}
