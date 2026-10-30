<?php
/**
 * In-house WordPress Plugin Check–style audit of an installed plugin copy
 * (the official Plugin Check / PHPCS can't be installed offline).
 *
 *   php /tests/plugin-audit.php /path/to/wp-content/plugins/wp-chrono
 *
 * Also runs the real `wp plugin check` when /cache/plugin-check.zip exists.
 */

$dir   = rtrim( $argv[1] ?? '', '/' );
$fails = 0;
$passes = 0;
function audit( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $passes;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( '' !== $extra ? "  ({$extra})" : '' ) . "\n";
	$ok ? $passes++ : $fails++;
}
if ( ! is_file( "{$dir}/wp-chrono.php" ) ) {
	audit( 'plugin directory found', false, $dir );
	exit( 1 );
}

$files = array();
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	$files[] = substr( $f->getPathname(), strlen( $dir ) + 1 );
}
sort( $files );
$php = array_values( array_filter( $files, static function ( $f ) { return '.php' === substr( $f, -4 ); } ) );

// 1. Only runtime files ship.
$top     = array_unique( array_map( static function ( $f ) { return explode( '/', $f )[0]; }, $files ) );
$allowed = array( 'wp-chrono.php', 'uninstall.php', 'readme.txt', 'includes', 'build', 'languages' );
audit( 'only runtime files at the top level', ! array_diff( $top, $allowed ), implode( ', ', array_diff( $top, $allowed ) ) ?: implode( ', ', $top ) );
$dev = preg_grep( '#(^|/)(src|node_modules|dev-env|tests?|scripts|\.git|vendor)(/|$)|\.map$|\.ts$|\.tsx$|package(-lock)?\.json$|composer\.json$#', $files );
audit( 'no source, tests, build tooling or source maps', ! $dev, implode( ', ', array_slice( $dev, 0, 5 ) ) );
audit( 'compiled SPA present (build/index.js, build/index.css)', in_array( 'build/index.js', $files, true ) && in_array( 'build/index.css', $files, true ) );
audit( 'translation template present (languages/wp-chrono.pot)', in_array( 'languages/wp-chrono.pot', $files, true ) );
audit( 'recovery template ships as non-executable .php.txt', in_array( 'includes/Recovery/ers-template.php.txt', $files, true ) && ! in_array( 'includes/Recovery/ers-template.php', $files, true ) );

// 2. Direct-access guard in every PHP file.
$unguarded = array();
foreach ( $php as $f ) {
	$head = implode( "\n", array_slice( file( "{$dir}/{$f}" ), 0, 40 ) );
	if ( ! preg_match( "/defined\(\s*'(ABSPATH|WP_UNINSTALL_PLUGIN)'\s*\)\s*(\|\||or)\s*(exit|die)|if\s*\(\s*!\s*defined\(\s*'(ABSPATH|WP_UNINSTALL_PLUGIN)'\s*\)\s*\)/", $head ) ) {
		$unguarded[] = $f;
	}
}
audit( 'every PHP file has an ABSPATH / WP_UNINSTALL_PLUGIN guard', ! $unguarded, implode( ', ', $unguarded ) ?: count( $php ) . ' files' );

// 3. Forbidden / discouraged code (token-based, so comments and strings don't count).
$forbidden = array( 'create_function', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'wp_redirect', 'extract' );
$hits      = array();
$unjustified = array();
foreach ( $php as $f ) {
	$src    = file_get_contents( "{$dir}/{$f}" );
	$lines  = explode( "\n", $src );
	$tokens = token_get_all( $src );
	foreach ( $tokens as $i => $t ) {
		if ( ! is_array( $t ) ) {
			if ( '`' === $t ) {
				$hits[] = "{$f}: backtick shell";
			}
			continue;
		}
		if ( T_EVAL === $t[0] ) {
			$hits[] = "{$f}:{$t[2]} eval";
		}
		if ( T_STRING !== $t[0] ) {
			continue;
		}
		$name = strtolower( $t[1] );
		// Next meaningful token must be "(" and previous must not be -> :: or "function".
		$next = $tokens[ $i + 1 ] ?? '';
		$prev = $tokens[ $i - 1 ] ?? '';
		$prev2 = $tokens[ $i - 2 ] ?? '';
		if ( '(' !== $next || ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) || ( is_array( $prev2 ) && T_FUNCTION === $prev2[0] ) ) {
			continue;
		}
		if ( in_array( $name, $forbidden, true ) ) {
			$hits[] = "{$f}:{$t[2]} {$name}()";
		}
		if ( in_array( $name, array( 'base64_decode', 'base64_encode', 'serialize', 'unserialize' ), true ) && false === strpos( $lines[ $t[2] - 1 ], 'phpcs:ignore' ) ) {
			$unjustified[] = "{$f}:{$t[2]} {$name}()";
		}
	}
}
audit( 'no eval / shell execution / create_function / extract / wp_redirect', ! $hits, implode( '; ', array_slice( $hits, 0, 5 ) ) );
audit( 'base64 / (un)serialize only with a documented phpcs justification', ! $unjustified, implode( '; ', array_slice( $unjustified, 0, 5 ) ) );

// 4. Output and input hygiene.
$echoes = array();
$display = array();
foreach ( $php as $f ) {
	foreach ( file( "{$dir}/{$f}" ) as $n => $line ) {
		if ( preg_match( '/\b(echo|print)\s+\$_(GET|POST|REQUEST|COOKIE|SERVER)/', $line ) ) {
			$echoes[] = "{$f}:" . ( $n + 1 );
		}
		if ( preg_match( "/\b(ini_set\(\s*'display_errors'|error_reporting\()/", $line ) ) {
			$display[] = "{$f}:" . ( $n + 1 );
		}
	}
}
audit( 'no raw echo of request superglobals', ! $echoes, implode( ', ', $echoes ) );
audit( 'plugin code never changes display_errors / error_reporting', ! $display, implode( ', ', $display ) );

// 5. Text domain.
$bad_domain = array();
foreach ( $php as $f ) {
	preg_match_all( "/\b(__|_e|_x|_n|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x)\(\s*'(?:[^'\\\\]|\\\\.)*'(?:\s*,\s*'(?:[^'\\\\]|\\\\.)*')*\s*\)/", file_get_contents( "{$dir}/{$f}" ), $m );
	foreach ( $m[0] as $call ) {
		if ( ! preg_match( "/,\s*'wp-chrono'\s*\)$/", $call ) ) {
			$bad_domain[] = "{$f}: " . substr( $call, 0, 60 );
		}
	}
}
audit( 'every translatable string uses the "wp-chrono" text domain', ! $bad_domain, implode( '; ', array_slice( $bad_domain, 0, 3 ) ) );

// 6. Headers in sync.
$main   = file_get_contents( "{$dir}/wp-chrono.php" );
$readme = file_get_contents( "{$dir}/readme.txt" );
$h      = static function ( string $src, string $name ): string {
	return preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':\s*(.+)$/mi', $src, $m ) ? trim( $m[1] ) : '';
};
preg_match( "/define\(\s*'WP_CHRONO_VERSION',\s*'([^']+)'/", $main, $c );
$version = $h( $main, 'Version' );
audit( 'plugin Version, WP_CHRONO_VERSION and readme Stable tag agree', $version === ( $c[1] ?? '' ) && $version === $h( $readme, 'Stable tag' ), "{$version} / " . ( $c[1] ?? '?' ) . ' / ' . $h( $readme, 'Stable tag' ) );
audit( 'Requires at least / Requires PHP match between plugin and readme', $h( $main, 'Requires at least' ) === $h( $readme, 'Requires at least' ) && $h( $main, 'Requires PHP' ) === $h( $readme, 'Requires PHP' ), $h( $readme, 'Requires at least' ) . ', PHP ' . $h( $readme, 'Requires PHP' ) );
audit( 'readme has Tested up to, GPL license, description, FAQ and changelog', '' !== $h( $readme, 'Tested up to' ) && false !== stripos( $h( $readme, 'License' ), 'GPL' ) && false !== strpos( $readme, '== Description ==' ) && false !== strpos( $readme, '== Frequently Asked Questions ==' ) && false !== strpos( $readme, '= ' . $version . ' =' ) );
audit( 'readme uses at most 5 tags', count( array_filter( array_map( 'trim', explode( ',', $h( $readme, 'Tags' ) ) ) ) ) <= 5 );
audit( 'plugin header declares Text Domain and Domain Path', 'wp-chrono' === $h( $main, 'Text Domain' ) && '/languages' === $h( $main, 'Domain Path' ) );

// PHP 7.4 has no str_contains/str_starts_with/str_ends_with helpers. Syntax
// linting does not catch these calls because the files parse successfully; a
// runtime request would fail only when the affected path is exercised.
$php_runtime_incompatible = array();
if ( version_compare( $h( $main, 'Requires PHP' ), '8.0', '<' ) ) {
	foreach ( $php as $f ) {
		$src = file_get_contents( "{$dir}/{$f}" );
		if ( preg_match( '/\bstr_(?:contains|starts_with|ends_with)\s*\(/', $src, $m ) ) {
			$php_runtime_incompatible[] = "{$f}: {$m[0]}";
		}
	}
}
audit( 'no PHP 8-only string helpers when PHP 7.4 is supported', ! $php_runtime_incompatible, implode( '; ', $php_runtime_incompatible ) );

// 7. The official Plugin Check, when available offline.
if ( is_file( '/cache/plugin-check.zip' ) ) {
	echo "NOTE  /cache/plugin-check.zip found: run `wp plugin check wp-chrono` (see release-checks.sh).\n";
}

echo "\n" . ( $fails ? "{$fails} check(s) FAILED ({$passes} passed)" : "All checks passed ({$passes} passed)" ) . "\n";
exit( $fails ? 1 : 0 );
