<?php
/**
 * Blueprints: reusable "dev baselines" — a set of plugins, themes and core
 * settings that a site can be reset to in one click.
 *
 * - capture():  saves the current site (installed plugins, active theme and
 *               its parent, a curated list of baseline wp_options). With
 *               `bundle`, every plugin / theme is also zipped into sealed
 *               storage, so the blueprint works offline and for premium
 *               plugins that wordpress.org can't serve.
 * - import() / export(): portable JSON (optionally embedding the zips).
 * - apply_step(): one resumable step of "Reset to Dev Baseline": remove
 *               plugins not in the blueprint, install + activate each plugin,
 *               install + switch the theme, apply options. The client runs a
 *               full reset first (keeping the admin signed in), then loops.
 *
 * Package sources, in order of preference: a stored zip (`package`), a
 * custom `.zip` URL, then wordpress.org by slug.
 *
 * Blueprints live in the autoload=off option `wp_chrono_blueprints`, which a
 * full reset keeps (every `wp_chrono_*` option survives a reset).
 *
 * @package WPChrono
 */

namespace WPChrono\Services;

use WP_Error;
use WPChrono\Core\Storage;
use WPChrono\Services\Blueprint\QuietSkin;
use WPChrono\Services\Snapshot\FileArchive;

defined( 'ABSPATH' ) || exit;

final class BlueprintService {

	const OPTION = 'wp_chrono_blueprints';

	const PACKAGE_DIR = 'packages';

	const PACKAGE_PATTERN = '/^[a-z0-9._-]+-[a-z0-9]{8}\.zip$/i';

	/** Core settings a dev baseline typically pins. */
	const BASELINE_OPTIONS = array(
		'permalink_structure',
		'timezone_string',
		'gmt_offset',
		'date_format',
		'time_format',
		'start_of_week',
		'WPLANG',
		'blog_public',
		'posts_per_page',
		'default_comment_status',
		'default_ping_status',
		'default_pingback_flag',
		'comment_moderation',
		'comment_registration',
		'uploads_use_yearmonth_folders',
		'thumbnail_size_w',
		'thumbnail_size_h',
		'medium_size_w',
		'medium_size_h',
		'large_size_w',
		'large_size_h',
		'show_on_front',
		'users_can_register',
		'default_role',
	);

	// ---------------------------------------------------------------------
	// Storage
	// ---------------------------------------------------------------------

	/**
	 * @return array<string,array<string,mixed>> Keyed by ID, newest first.
	 */
	public function all(): array {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		uasort(
			$all,
			static function ( $a, $b ) {
				return strcmp( (string) $b['created_at'], (string) $a['created_at'] );
			}
		);
		return $all;
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public function get( string $id ) {
		$all = $this->all();
		return $all[ $id ] ?? new WP_Error( 'wp_chrono_blueprint_missing', 'Blueprint not found.', array( 'status' => 404 ) );
	}

	/**
	 * @param array<string,mixed> $blueprint
	 */
	private function put( array $blueprint ): array {
		$all                     = $this->all();
		$blueprint['updated_at'] = gmdate( 'c' );
		$all[ $blueprint['id'] ] = $blueprint;
		update_option( self::OPTION, $all, false );
		return $blueprint;
	}

	/**
	 * Deletes a blueprint and any stored packages no other blueprint uses.
	 *
	 * @return true|WP_Error
	 */
	public function delete( string $id ) {
		$all = $this->all();
		if ( ! isset( $all[ $id ] ) ) {
			return new WP_Error( 'wp_chrono_blueprint_missing', 'Blueprint not found.', array( 'status' => 404 ) );
		}
		$mine = self::packages_of( $all[ $id ] );
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		$still = array();
		foreach ( $all as $other ) {
			$still = array_merge( $still, self::packages_of( $other ) );
		}
		foreach ( array_diff( $mine, $still ) as $package ) {
			$path = self::package_path( $package );
			if ( '' !== $path ) {
				@unlink( $path ); // phpcs:ignore
			}
		}
		return true;
	}

	/**
	 * Public shape for the REST API / UI.
	 *
	 * @param array<string,mixed> $bp
	 * @return array<string,mixed>
	 */
	public static function summary( array $bp ): array {
		$items = array_merge( (array) $bp['plugins'], (array) $bp['themes'] );
		$bytes = 0;
		foreach ( $items as $i => $item ) {
			$path               = self::package_path( (string) ( $item['package'] ?? '' ) );
			$items[ $i ]['has_package'] = '' !== $path;
			$bytes             += '' !== $path ? (int) filesize( $path ) : 0;
		}
		return array_merge(
			$bp,
			array(
				'plugin_count'  => count( (array) $bp['plugins'] ),
				'theme_count'   => count( (array) $bp['themes'] ),
				'option_count'  => count( (array) $bp['options'] ),
				'package_bytes' => $bytes,
				'plugins'       => array_slice( $items, 0, count( (array) $bp['plugins'] ) ),
				'themes'        => array_slice( $items, count( (array) $bp['plugins'] ) ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Capture / import / export
	// ---------------------------------------------------------------------

	/**
	 * Saves the current site as a blueprint.
	 *
	 * @param array{description?:string,bundle?:bool,include_inactive?:bool} $opts
	 * @return array<string,mixed>|WP_Error
	 */
	public function capture( string $name, array $opts = array() ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$bundle   = ! empty( $opts['bundle'] );
		$inactive = ! array_key_exists( 'include_inactive', $opts ) || ! empty( $opts['include_inactive'] );
		$wporg    = self::wporg_slugs();

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( WP_CHRONO_BASENAME === $file ) {
				continue;
			}
			$active = is_plugin_active( $file );
			if ( ! $active && ! $inactive ) {
				continue;
			}
			$single    = '.' === dirname( $file );
			$slug      = $single ? basename( $file, '.php' ) : dirname( $file );
			$item      = array(
				'type'    => 'plugin',
				'slug'    => $slug,
				'file'    => $file,
				'name'    => (string) $data['Name'],
				'version' => (string) $data['Version'],
				'active'  => $active,
				'source'  => 'wporg',
				'url'     => '',
				'package' => '',
				'wporg'   => isset( $wporg[ $file ] ),
			);
			if ( $bundle ) {
				$package = self::store_folder( WP_PLUGIN_DIR . '/' . ( $single ? $file : $slug ), $single, $slug, $item['version'] );
				if ( is_wp_error( $package ) ) {
					return $package;
				}
				$item['source']  = 'package';
				$item['package'] = $package;
			}
			$plugins[] = $item;
		}

		$themes     = array();
		$stylesheet = get_stylesheet();
		$template   = get_template();
		foreach ( array_unique( array( $template, $stylesheet ) ) as $slug ) {
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				continue;
			}
			$item = array(
				'type'    => 'theme',
				'slug'    => $slug,
				'file'    => $slug,
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
				'active'  => $slug === $stylesheet,
				'source'  => 'wporg',
				'url'     => '',
				'package' => '',
			);
			if ( $bundle ) {
				$package = self::store_folder( $theme->get_stylesheet_directory(), false, $slug, $item['version'] );
				if ( is_wp_error( $package ) ) {
					return $package;
				}
				$item['source']  = 'package';
				$item['package'] = $package;
			}
			$themes[] = $item;
		}

		$options = array();
		foreach ( self::BASELINE_OPTIONS as $option ) {
			$missing = new \stdClass();
			$value   = get_option( $option, $missing );
			if ( $value !== $missing ) {
				$options[ $option ] = $value;
			}
		}

		return $this->put(
			array(
				'id'            => strtolower( wp_generate_password( 8, false ) ),
				'name'          => self::clean_name( $name ),
				'description'   => sanitize_textarea_field( (string) ( $opts['description'] ?? '' ) ),
				'created_at'    => gmdate( 'c' ),
				'source_site'   => home_url(),
				'plugins'       => $plugins,
				'themes'        => $themes,
				'options'       => $options,
				'remove_others' => true,
			)
		);
	}

	/**
	 * Portable JSON document. With `$embed`, stored zips are included
	 * (base64) so premium / custom plugins travel with the blueprint.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function export( string $id, bool $embed = false ) {
		$bp = $this->get( $id );
		if ( is_wp_error( $bp ) ) {
			return $bp;
		}
		$items = array();
		foreach ( array( 'plugins', 'themes' ) as $kind ) {
			foreach ( (array) $bp[ $kind ] as $item ) {
				$path = self::package_path( (string) $item['package'] );
				$out  = array_intersect_key( $item, array_flip( array( 'type', 'slug', 'file', 'name', 'version', 'active', 'source', 'url' ) ) );
				if ( '' !== $path ) {
					$out['sha256'] = hash_file( 'sha256', $path );
					if ( $embed ) {
						$out['package_data'] = base64_encode( (string) file_get_contents( $path ) ); // phpcs:ignore
					} else {
						$out['source'] = '' !== (string) $item['url'] ? 'url' : 'wporg'; // The zip stays behind.
					}
				}
				$items[ $kind ][] = $out;
			}
		}
		return array(
			'wpchrono_blueprint' => 1,
			'name'               => $bp['name'],
			'description'        => $bp['description'],
			'exported_at'        => gmdate( 'c' ),
			'source_site'        => $bp['source_site'] ?? '',
			'remove_others'      => (bool) $bp['remove_others'],
			'plugins'            => $items['plugins'] ?? array(),
			'themes'             => $items['themes'] ?? array(),
			'options'            => (object) $bp['options'],
		);
	}

	/**
	 * Validates and stores a blueprint JSON document.
	 *
	 * @param array<string,mixed>|string $doc
	 * @return array<string,mixed>|WP_Error
	 */
	public function import( $doc ) {
		if ( is_string( $doc ) ) {
			$doc = json_decode( $doc, true );
		}
		if ( ! is_array( $doc ) || 1 !== (int) ( $doc['wpchrono_blueprint'] ?? 0 ) ) {
			return new WP_Error( 'wp_chrono_blueprint_invalid', 'This is not a WP Chrono blueprint file.', array( 'status' => 400 ) );
		}
		$out = array( 'plugins' => array(), 'themes' => array() );
		foreach ( array( 'plugins' => 'plugin', 'themes' => 'theme' ) as $kind => $type ) {
			foreach ( (array) ( $doc[ $kind ] ?? array() ) as $raw ) {
				$item = self::clean_item( (array) $raw, $type );
				if ( is_wp_error( $item ) ) {
					return $item;
				}
				if ( ! empty( $raw['package_data'] ) ) {
					$bytes = base64_decode( (string) $raw['package_data'], true ); // phpcs:ignore
					if ( false === $bytes || ( ! empty( $raw['sha256'] ) && ! hash_equals( (string) $raw['sha256'], hash( 'sha256', $bytes ) ) ) ) {
						return new WP_Error( 'wp_chrono_blueprint_package', "The embedded package for {$item['slug']} is corrupt.", array( 'status' => 400 ) );
					}
					$stored = self::store_bytes( $bytes, $item['slug'], $item['version'] );
					if ( is_wp_error( $stored ) ) {
						return $stored;
					}
					$item['source']  = 'package';
					$item['package'] = $stored;
				}
				$out[ $kind ][] = $item;
			}
		}
		$options = array();
		foreach ( (array) ( $doc['options'] ?? array() ) as $key => $value ) {
			if ( in_array( $key, self::BASELINE_OPTIONS, true ) && ( is_scalar( $value ) || is_null( $value ) ) ) {
				$options[ $key ] = $value;
			}
		}
		return $this->put(
			array(
				'id'            => strtolower( wp_generate_password( 8, false ) ),
				'name'          => self::clean_name( (string) ( $doc['name'] ?? 'Imported blueprint' ) ),
				'description'   => sanitize_textarea_field( (string) ( $doc['description'] ?? '' ) ),
				'created_at'    => gmdate( 'c' ),
				'source_site'   => esc_url_raw( (string) ( $doc['source_site'] ?? '' ) ),
				'plugins'       => $out['plugins'],
				'themes'        => $out['themes'],
				'options'       => $options,
				'remove_others' => ! isset( $doc['remove_others'] ) || (bool) $doc['remove_others'],
			)
		);
	}

	/**
	 * Adds an uploaded plugin / theme zip to a blueprint (replacing an item
	 * with the same slug).
	 *
	 * @return array<string,mixed>|WP_Error Updated blueprint.
	 */
	public function add_package( string $id, string $zip_path ) {
		$bp = $this->get( $id );
		if ( is_wp_error( $bp ) ) {
			return $bp;
		}
		$info = self::inspect_zip( $zip_path );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$stored = self::store_bytes( (string) file_get_contents( $zip_path ), $info['slug'], $info['version'] ); // phpcs:ignore
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$kind = 'theme' === $info['type'] ? 'themes' : 'plugins';
		$item = array_merge( $info, array( 'active' => true, 'source' => 'package', 'url' => '', 'package' => $stored ) );
		$list = array_values(
			array_filter(
				(array) $bp[ $kind ],
				static function ( $existing ) use ( $item ) {
					return $existing['slug'] !== $item['slug'];
				}
			)
		);
		if ( 'themes' === $kind ) {
			foreach ( $list as $i => $theme ) {
				$list[ $i ]['active'] = false; // The uploaded theme becomes the active one.
			}
		}
		$list[]      = $item;
		$bp[ $kind ] = $list;
		return $this->put( $bp );
	}

	/**
	 * @return array<string,mixed>|WP_Error Updated blueprint.
	 */
	public function remove_item( string $id, string $type, string $slug ) {
		$bp = $this->get( $id );
		if ( is_wp_error( $bp ) ) {
			return $bp;
		}
		$kind        = 'theme' === $type ? 'themes' : 'plugins';
		$bp[ $kind ] = array_values(
			array_filter(
				(array) $bp[ $kind ],
				static function ( $item ) use ( $slug ) {
					return $item['slug'] !== $slug;
				}
			)
		);
		return $this->put( $bp );
	}

	// ---------------------------------------------------------------------
	// Apply ("Reset to Dev Baseline")
	// ---------------------------------------------------------------------

	/**
	 * The ordered step list for a blueprint.
	 *
	 * @param array<string,mixed> $bp
	 * @return array<int,array{kind:string,index:int,label:string}>
	 */
	public static function steps( array $bp ): array {
		$steps = array();
		if ( ! empty( $bp['remove_others'] ) ) {
			$steps[] = array( 'kind' => 'cleanup', 'index' => 0, 'label' => 'Remove plugins that are not in the blueprint' );
		}
		foreach ( array_values( (array) $bp['plugins'] ) as $i => $plugin ) {
			$steps[] = array( 'kind' => 'plugin', 'index' => $i, 'label' => sprintf( 'Install %s %s', $plugin['name'] ?: $plugin['slug'], $plugin['version'] ) );
		}
		foreach ( array_values( (array) $bp['themes'] ) as $i => $theme ) {
			$steps[] = array( 'kind' => 'theme', 'index' => $i, 'label' => sprintf( 'Install theme %s %s', $theme['name'] ?: $theme['slug'], $theme['version'] ) );
		}
		$steps[] = array( 'kind' => 'options', 'index' => 0, 'label' => sprintf( 'Apply %d baseline settings', count( (array) $bp['options'] ) ) );
		return $steps;
	}

	/**
	 * Runs one step. Errors inside a step (e.g. wordpress.org unreachable for
	 * one plugin) are logged and the queue continues; only a missing
	 * blueprint or step aborts.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function apply_step( string $id, int $step ) {
		$bp = $this->get( $id );
		if ( is_wp_error( $bp ) ) {
			return $bp;
		}
		$steps = self::steps( $bp );
		if ( ! isset( $steps[ $step ] ) ) {
			return new WP_Error( 'wp_chrono_blueprint_step', 'Unknown blueprint step.', array( 'status' => 400 ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$started = microtime( true );
		$current = $steps[ $step ];
		$log     = array();
		$ok      = true;

		switch ( $current['kind'] ) {
			case 'cleanup':
				list( $ok, $log ) = $this->remove_others( $bp );
				break;
			case 'plugin':
				list( $ok, $log ) = $this->install_item( $bp['plugins'][ $current['index'] ] );
				break;
			case 'theme':
				list( $ok, $log ) = $this->install_item( $bp['themes'][ $current['index'] ] );
				break;
			case 'options':
				foreach ( (array) $bp['options'] as $name => $value ) {
					update_option( $name, $value );
				}
				if ( isset( $bp['options']['permalink_structure'] ) ) {
					flush_rewrite_rules( false );
				}
				$log[] = sprintf( 'Applied %d baseline settings.', count( (array) $bp['options'] ) );
				break;
		}

		$done = $step + 1 >= count( $steps );
		if ( $done ) {
			do_action( 'wp_chrono_blueprint_applied', $bp );
		}
		return array(
			'done'        => $done,
			'step'        => $step,
			'next'        => $done ? null : $step + 1,
			'total'       => count( $steps ),
			'label'       => $current['label'],
			'ok'          => $ok,
			'log'         => $log,
			'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
		);
	}

	/**
	 * @param array<string,mixed> $bp
	 * @return array{0:bool,1:string[]}
	 */
	private function remove_others( array $bp ): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$keep = array( WP_CHRONO_BASENAME => true );
		foreach ( (array) $bp['plugins'] as $plugin ) {
			$keep[ $plugin['file'] ] = true;
		}
		$remove = array();
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( ! isset( $keep[ $file ] ) && ! is_plugin_active( $file ) ) {
				$remove[] = $file;
			}
		}
		if ( ! $remove ) {
			return array( true, array( 'No extra plugins to remove.' ) );
		}
		$fs = Reset\Files::init_filesystem();
		if ( is_wp_error( $fs ) ) {
			return array( false, array( $fs->get_error_message() ) );
		}
		$result = delete_plugins( $remove );
		if ( is_wp_error( $result ) ) {
			return array( false, array( 'Could not remove plugins: ' . $result->get_error_message() ) );
		}
		wp_clean_plugins_cache();
		return array( true, array( sprintf( 'Removed %d plugin(s) not in the blueprint: %s.', count( $remove ), implode( ', ', $remove ) ) ) );
	}

	/**
	 * Installs (or keeps, when the same version is already on disk) and
	 * activates one plugin / theme.
	 *
	 * @param array<string,mixed> $item
	 * @return array{0:bool,1:string[]}
	 */
	private function install_item( array $item ): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$theme = 'theme' === $item['type'];
		$label = ( $theme ? 'theme ' : '' ) . $item['slug'];
		$log   = array();

		$installed = $theme ? (string) wp_get_theme( $item['slug'] )->get( 'Version' ) : self::installed_plugin_version( (string) $item['file'] );
		if ( '' !== $installed && '' !== (string) $item['version'] && version_compare( $installed, (string) $item['version'], '==' ) ) {
			$log[] = "{$label} {$installed} is already installed.";
		} else {
			$package = self::package_for( $item );
			if ( is_wp_error( $package ) ) {
				return array( false, array( "Skipped {$label}: " . $package->get_error_message() ) );
			}
			$fs = Reset\Files::init_filesystem();
			if ( is_wp_error( $fs ) ) {
				return array( false, array( $fs->get_error_message() ) );
			}
			$skin     = new QuietSkin();
			$upgrader = $theme ? new \Theme_Upgrader( $skin ) : new \Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $package['package'], array( 'overwrite_package' => true, 'clear_update_cache' => false ) );
			if ( true !== $result ) {
				$why = is_wp_error( $result ) ? $result->get_error_message() : implode( ' ', array_slice( $skin->messages, -2 ) );
				return array( false, array( "Could not install {$label} from {$package['label']}: {$why}" ) );
			}
			$theme ? wp_clean_themes_cache() : wp_clean_plugins_cache();
			$log[] = sprintf( 'Installed %s %s from %s.', $label, $item['version'], $package['label'] );
		}

		if ( ! empty( $item['active'] ) ) {
			if ( $theme ) {
				if ( ! wp_get_theme( $item['slug'] )->exists() ) {
					return array( false, array_merge( $log, array( "Theme {$item['slug']} is not available to activate." ) ) );
				}
				switch_theme( $item['slug'] );
				$log[] = "Activated theme {$item['slug']}.";
			} else {
				try {
					$activated = activate_plugin( (string) $item['file'] );
				} catch ( \Throwable $e ) {
					$activated = new WP_Error( 'wp_chrono_activate', $e->getMessage() );
				}
				if ( is_wp_error( $activated ) && 'unexpected_output' !== $activated->get_error_code() ) {
					return array( false, array_merge( $log, array( "Could not activate {$item['file']}: " . $activated->get_error_message() ) ) );
				}
				$log[] = "Activated {$item['file']}.";
			}
		}
		return array( true, $log );
	}

	/**
	 * Where to install an item from.
	 *
	 * @param array<string,mixed> $item
	 * @return array{package:string,label:string}|WP_Error
	 */
	private static function package_for( array $item ) {
		$path = self::package_path( (string) ( $item['package'] ?? '' ) );
		if ( '' !== $path ) {
			return array( 'package' => $path, 'label' => 'its stored zip' );
		}
		if ( '' !== (string) ( $item['url'] ?? '' ) ) {
			return array( 'package' => (string) $item['url'], 'label' => wp_parse_url( (string) $item['url'], PHP_URL_HOST ) ?: 'URL' );
		}
		$api = 'theme' === $item['type']
			? themes_api( 'theme_information', array( 'slug' => $item['slug'], 'fields' => array( 'sections' => false ) ) )
			: plugins_api( 'plugin_information', array( 'slug' => $item['slug'], 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
			return new WP_Error( 'wp_chrono_blueprint_source', 'not available from wordpress.org (offline, premium or custom plugin?) and no zip is stored. Re-capture with "bundle local copies" or upload its zip.' );
		}
		return array( 'package' => (string) $api->download_link, 'label' => 'wordpress.org' );
	}

	// ---------------------------------------------------------------------
	// Packages
	// ---------------------------------------------------------------------

	/**
	 * @return string|WP_Error `{storage}/packages/` with trailing slash.
	 */
	public static function package_dir() {
		$base = Storage::ensure();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$dir = $base . self::PACKAGE_DIR . '/';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'wp_chrono_blueprint_io', 'Could not create the package folder.' );
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
		}
		return $dir;
	}

	public static function package_path( string $name ): string {
		if ( '' === $name || ! preg_match( self::PACKAGE_PATTERN, $name ) ) {
			return '';
		}
		$dir = self::package_dir();
		return ( ! is_wp_error( $dir ) && is_file( $dir . $name ) ) ? $dir . $name : '';
	}

	/**
	 * @return string|WP_Error Stored file name.
	 */
	private static function store_folder( string $path, bool $single, string $slug, string $version ) {
		$dir = self::package_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$name = self::package_name( $slug, $version );
		$ok   = FileArchive::zip( $path, $single, $dir . $name );
		return is_wp_error( $ok ) ? $ok : $name;
	}

	/**
	 * @return string|WP_Error Stored file name.
	 */
	private static function store_bytes( string $bytes, string $slug, string $version ) {
		$dir = self::package_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$name = self::package_name( $slug, $version );
		if ( false === file_put_contents( $dir . $name, $bytes ) ) { // phpcs:ignore
			return new WP_Error( 'wp_chrono_blueprint_io', 'Could not store the package.' );
		}
		return $name;
	}

	private static function package_name( string $slug, string $version ): string {
		$safe = static function ( string $v ): string {
			return trim( (string) preg_replace( '/[^a-z0-9._-]+/i', '-', $v ), '-' );
		};
		return strtolower( $safe( $slug ) . '-' . $safe( '' === $version ? '0' : $version ) . '-' . wp_generate_password( 8, false ) ) . '.zip';
	}

	/**
	 * Identifies a plugin / theme zip from its headers, without extracting it.
	 *
	 * @return array{type:string,slug:string,file:string,name:string,version:string}|WP_Error
	 */
	public static function inspect_zip( string $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'wp_chrono_blueprint_zip', 'PHP\'s zip extension is required to read packages.', array( 'status' => 500 ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'wp_chrono_blueprint_zip', 'That file is not a valid zip archive.', array( 'status' => 400 ) );
		}
		$found = null;
		for ( $i = 0; $i < $zip->numFiles && ! $found; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( preg_match( '#(^|/)\.\.(/|$)#', $name ) || '/' === ( $name[0] ?? '' ) ) {
				$zip->close();
				return new WP_Error( 'wp_chrono_blueprint_zip', 'The zip contains unsafe paths.', array( 'status' => 400 ) );
			}
			if ( substr_count( $name, '/' ) > 1 ) {
				continue;
			}
			$base = basename( $name );
			$head = (string) $zip->getFromIndex( $i, 8192 );
			if ( '.php' === substr( $name, -4 ) && preg_match( '/^[ \t\/*#@]*Plugin Name:(.*)$/mi', $head, $n ) ) {
				$single = false === strpos( $name, '/' );
				$found  = array(
					'type' => 'plugin',
					'slug' => $single ? basename( $base, '.php' ) : dirname( $name ),
					'file' => $name,
					'name' => trim( $n[1] ),
				);
			} elseif ( 'style.css' === $base && false !== strpos( $name, '/' ) && preg_match( '/^[ \t\/*#@]*Theme Name:(.*)$/mi', $head, $n ) ) {
				$found = array(
					'type' => 'theme',
					'slug' => dirname( $name ),
					'file' => dirname( $name ),
					'name' => trim( $n[1] ),
				);
			}
			if ( $found ) {
				$found['version'] = preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', $head, $v ) ? trim( $v[1] ) : '';
			}
		}
		$zip->close();
		return $found ?: new WP_Error( 'wp_chrono_blueprint_zip', 'No plugin or theme header was found in that zip.', array( 'status' => 400 ) );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Plugin basenames wordpress.org knows about (from the update cache).
	 *
	 * @return array<string,bool>
	 */
	private static function wporg_slugs(): array {
		$cache = get_site_transient( 'update_plugins' );
		$known = array();
		foreach ( array( 'response', 'no_update' ) as $bucket ) {
			foreach ( (array) ( $cache->$bucket ?? array() ) as $file => $info ) {
				if ( isset( $info->id ) && false !== strpos( (string) $info->id, 'w.org' ) ) {
					$known[ $file ] = true;
				}
			}
		}
		return $known;
	}

	private static function installed_plugin_version( string $file ): string {
		$path = WP_PLUGIN_DIR . '/' . $file;
		return is_file( $path ) ? (string) get_file_data( $path, array( 'Version' => 'Version' ) )['Version'] : '';
	}

	/**
	 * @param array<string,mixed> $bp
	 * @return string[]
	 */
	private static function packages_of( array $bp ): array {
		return array_values( array_filter( array_column( array_merge( (array) $bp['plugins'], (array) $bp['themes'] ), 'package' ) ) );
	}

	private static function clean_name( string $name ): string {
		$name = mb_substr( trim( sanitize_text_field( $name ) ), 0, 120 );
		return '' === $name ? 'Untitled blueprint' : $name;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>|WP_Error
	 */
	private static function clean_item( array $raw, string $type ) {
		$slug = sanitize_file_name( (string) ( $raw['slug'] ?? '' ) );
		if ( '' === $slug || false !== strpos( $slug, '..' ) ) {
			return new WP_Error( 'wp_chrono_blueprint_invalid', 'A blueprint item has no valid slug.', array( 'status' => 400 ) );
		}
		$file = 'theme' === $type ? $slug : wp_normalize_path( (string) ( $raw['file'] ?? "{$slug}/{$slug}.php" ) );
		if ( 'plugin' === $type && ( false !== strpos( $file, '..' ) || ! preg_match( '#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)?\.php$#', $file ) ) ) {
			return new WP_Error( 'wp_chrono_blueprint_invalid', "Invalid plugin file for {$slug}.", array( 'status' => 400 ) );
		}
		$url = esc_url_raw( (string) ( $raw['url'] ?? '' ), array( 'https', 'http' ) );
		return array(
			'type'    => $type,
			'slug'    => $slug,
			'file'    => $file,
			'name'    => sanitize_text_field( (string) ( $raw['name'] ?? $slug ) ),
			'version' => sanitize_text_field( (string) ( $raw['version'] ?? '' ) ),
			'active'  => ! empty( $raw['active'] ),
			'source'  => '' !== $url ? 'url' : 'wporg',
			'url'     => $url,
			'package' => '',
		);
	}
}
