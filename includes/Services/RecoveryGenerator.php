<?php
/**
 * Generates the standalone Emergency Recovery Script.
 *
 * The script is written to the WordPress root as
 * `wp-chrono-recovery-{16 random chars}.php`, with a bcrypt hash of the
 * recovery password, a per-script HMAC secret and a few path hints baked in.
 * The plain password is returned once and never stored.
 *
 * @package WPChrono
 */

namespace WPChrono\Services;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class RecoveryGenerator {

	const OPTION = 'wp_chrono_recovery';

	const FILE_PATTERN = '/^wp-chrono-recovery-[a-z0-9]{16}\.php$/';

	const MIN_PASSWORD = 12;

	/**
	 * @return array{exists:bool,url:?string,file:?string,created_at:?string,writable:bool}
	 */
	public function status(): array {
		$info = get_option( self::OPTION );
		$file = is_array( $info ) ? (string) ( $info['file'] ?? '' ) : '';
		$ok   = '' !== $file && preg_match( self::FILE_PATTERN, $file ) && is_file( ABSPATH . $file );
		return array(
			'exists'     => (bool) $ok,
			'url'        => $ok ? site_url( '/' . $file ) : null,
			'file'       => $ok ? $file : null,
			'created_at' => $ok ? (string) ( $info['created_at'] ?? '' ) : null,
			'writable'   => wp_is_writable( ABSPATH ),
		);
	}

	/**
	 * Writes a fresh script (replacing any previous one).
	 *
	 * @return array{url:string,file:string,password:string,created_at:string,generated_password:bool}|WP_Error
	 */
	public function generate( string $password = '' ) {
		$generated = '' === $password;
		if ( $generated ) {
			$password = wp_generate_password( 24, false );
		} elseif ( strlen( $password ) < self::MIN_PASSWORD ) {
			return new WP_Error( 'wp_chrono_weak_password', sprintf( 'The recovery password must be at least %d characters.', self::MIN_PASSWORD ), array( 'status' => 400 ) );
		}

		$template = file_get_contents( WP_CHRONO_DIR . 'includes/Recovery/ers-template.php.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $template ) {
			return new WP_Error( 'wp_chrono_recovery_template', 'The recovery script template is missing.' );
		}

		global $wpdb;
		$created = gmdate( 'Y-m-d H:i' ) . ' UTC';
		$values  = array(
			'__WPC_PASSWORD_HASH__'    => password_hash( $password, PASSWORD_BCRYPT, array( 'cost' => 11 ) ),
			'__WPC_SECRET__'           => bin2hex( random_bytes( 32 ) ),
			'__WPC_CONFIG_HINT__'      => $this->config_path(),
			'__WPC_PREFIX_HINT__'      => $wpdb->prefix,
			'__WPC_CONTENT_DIR_HINT__' => untrailingslashit( WP_CONTENT_DIR ),
			'__WPC_GENERATED_AT__'     => $created,
		);
		$script = str_replace( '/*__WPC_ARM__*/', "define( 'WPC_ERS_ARMED', true );", $template );
		foreach ( $values as $placeholder => $value ) {
			$script = str_replace( "'{$placeholder}'", var_export( (string) $value, true ), $script ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		if ( false !== strpos( $script, "'__WPC_" ) ) {
			return new WP_Error( 'wp_chrono_recovery_template', 'The recovery script template has unfilled placeholders.' );
		}

		$this->delete();
		$file = 'wp-chrono-recovery-' . strtolower( wp_generate_password( 16, false ) ) . '.php';
		if ( false === file_put_contents( ABSPATH . $file, $script ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'wp_chrono_recovery_write', 'Could not write the recovery script to the WordPress root. Check that it is writable.', array( 'status' => 500 ) );
		}
		@chmod( ABSPATH . $file, 0644 ); // phpcs:ignore

		update_option( self::OPTION, array( 'file' => $file, 'created_at' => $created ), false );

		return array(
			'url'                => site_url( '/' . $file ),
			'file'               => $file,
			'password'           => $password,
			'generated_password' => $generated,
			'created_at'         => $created,
		);
	}

	public function delete(): bool {
		$info = get_option( self::OPTION );
		$file = is_array( $info ) ? (string) ( $info['file'] ?? '' ) : '';
		delete_option( self::OPTION );
		if ( '' === $file || ! preg_match( self::FILE_PATTERN, $file ) || ! is_file( ABSPATH . $file ) ) {
			return false;
		}
		return unlink( ABSPATH . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Same lookup WordPress uses: ABSPATH, or one level up when that directory
	 * isn't itself a WordPress install.
	 */
	private function config_path(): string {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$up = dirname( ABSPATH ) . '/wp-config.php';
		return ( file_exists( $up ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) ? $up : '';
	}
}
