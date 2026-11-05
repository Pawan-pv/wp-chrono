<?php
/**
 * Minimal stand-ins for the WordPress functions the unit-tested code calls.
 * Where behaviour matters to a test (is_serialized, wp_normalize_path,
 * sanitize_key) the implementation follows WordPress core.
 *
 * @package WPChrono
 */

// phpcs:disable

class WP_Error {
	/** @var array<string,string[]> */
	public $errors = array();
	/** @var array<string,mixed> */
	public $error_data = array();

	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( '' === $code ) {
			return;
		}
		$this->errors[ $code ][] = $message;
		if ( '' !== $data ) {
			$this->error_data[ $code ] = $data;
		}
	}

	public function get_error_code() {
		$codes = array_keys( $this->errors );
		return $codes ? $codes[0] : '';
	}

	public function get_error_message( $code = '' ) {
		$code = '' === $code ? $this->get_error_code() : $code;
		return $this->errors[ $code ][0] ?? '';
	}

	public function get_error_data( $code = '' ) {
		$code = '' === $code ? $this->get_error_code() : $code;
		return $this->error_data[ $code ] ?? null;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// ---------------------------------------------------------------------
// Hooks: a tiny registry so tests can add trusted test keys etc.
// ---------------------------------------------------------------------

$GLOBALS['wpc_test_filters'] = array();

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['wpc_test_filters'][ $hook ][] = array( $callback, $args );
	return true;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_filter( $hook, $callback, $priority, $args );
}

function remove_all_filters( $hook ) {
	unset( $GLOBALS['wpc_test_filters'][ $hook ] );
	return true;
}

function apply_filters( $hook, $value, ...$rest ) {
	foreach ( $GLOBALS['wpc_test_filters'][ $hook ] ?? array() as $entry ) {
		$value = call_user_func_array( $entry[0], array_slice( array_merge( array( $value ), $rest ), 0, max( 1, (int) $entry[1] ) ) );
	}
	return $value;
}

function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['wpc_test_filters'][ $hook ] ?? array() as $entry ) {
		call_user_func_array( $entry[0], $args );
	}
}

// ---------------------------------------------------------------------
// Options / site
// ---------------------------------------------------------------------

$GLOBALS['wpc_test_options'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wpc_test_options'] ) ? $GLOBALS['wpc_test_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['wpc_test_options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['wpc_test_options'][ $name ] );
	return true;
}

function home_url( $path = '' ) {
	return 'https://example.test' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
}

function is_multisite() {
	return ! empty( $GLOBALS['wpc_test_multisite'] );
}

function wp_timezone() {
	return new DateTimeZone( $GLOBALS['wpc_test_timezone'] ?? 'UTC' );
}

function wp_salt( $scheme = 'auth' ) {
	return $GLOBALS['wpc_test_salt'] ?? 'unit-test-salt-' . $scheme;
}

function get_theme_root( $stylesheet = '' ) {
	return WP_CONTENT_DIR . '/themes';
}

// ---------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	$text = strip_tags( $text );
	return trim( $remove_breaks ? preg_replace( '/[\r\n\t ]+/', ' ', $text ) : $text );
}

function sanitize_text_field( $str ) {
	$str = wp_strip_all_tags( (string) $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( $str );
}

function esc_url_raw( $url, $protocols = null ) {
	$url    = trim( (string) $url );
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	if ( '' === $url || ( null !== $protocols && ! in_array( $scheme, (array) $protocols, true ) ) ) {
		return '';
	}
	return preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', $url );
}

function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$out   = '';
	for ( $i = 0; $i < $length; $i++ ) {
		$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
	}
	return $out;
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function trailingslashit( $value ) {
	return untrailingslashit( $value ) . '/';
}

/** WordPress core's wp_normalize_path(). */
function wp_normalize_path( $path ) {
	$wrapper = '';
	if ( preg_match( '|^[a-zA-Z][a-zA-Z0-9+.-]*://|', $path ) ) {
		list( $wrapper, $path ) = explode( '://', $path, 2 );
		$wrapper               .= '://';
	}
	$path = str_replace( '\\', '/', $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );
	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}
	return $wrapper . $path;
}

/** WordPress core's is_serialized() (strict mode). */
function is_serialized( $data, $strict = true ) {
	if ( ! is_string( $data ) ) {
		return false;
	}
	$data = trim( $data );
	if ( 'N;' === $data ) {
		return true;
	}
	if ( strlen( $data ) < 4 || ':' !== $data[1] ) {
		return false;
	}
	if ( $strict ) {
		$lastc = substr( $data, -1 );
		if ( ';' !== $lastc && '}' !== $lastc ) {
			return false;
		}
	} else {
		$semicolon = strpos( $data, ';' );
		$brace     = strpos( $data, '}' );
		if ( false === $semicolon && false === $brace ) {
			return false;
		}
	}
	$token = $data[0];
	switch ( $token ) {
		case 's':
			if ( $strict ) {
				if ( '"' !== substr( $data, -2, 1 ) ) {
					return false;
				}
			} elseif ( false === strpos( $data, '"' ) ) {
				return false;
			}
			// Or else fall through.
		case 'a':
		case 'O':
		case 'E':
			return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
		case 'b':
		case 'i':
		case 'd':
			$end = $strict ? '$' : '';
			return (bool) preg_match( "/^{$token}:[0-9.E+-]+;$end/", $data );
	}
	return false;
}

// ---------------------------------------------------------------------
// $wpdb: query building only (prepare / esc_like); no database.
// ---------------------------------------------------------------------

class WPC_Test_WPDB {
	public $prefix      = 'wp_';
	public $base_prefix = 'wp_';
	public $dbname      = 'wordpress';
	/** @var string[] What get_col() returns (information_schema listings). */
	public $tables_in_db = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[sdi]/',
			function ( $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ];
				switch ( $m[0] ) {
					case '%d':
						return (string) (int) $value;
					case '%i':
						return '`' . str_replace( '`', '``', (string) $value ) . '`';
					default:
						return "'" . addslashes( (string) $value ) . "'";
				}
			},
			$query
		);
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function get_col( $query = null ) {
		return $this->tables_in_db;
	}

	public function tables( $scope = 'all', $prefix = true ) {
		$names = array( 'posts', 'comments', 'links', 'options', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'commentmeta', 'users', 'usermeta' );
		return array_combine( $names, array_map( function ( $t ) { return $this->prefix . $t; }, $names ) );
	}
}

$GLOBALS['wpdb'] = new WPC_Test_WPDB();
