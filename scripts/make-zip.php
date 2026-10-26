<?php
/**
 * Release helper (never shipped): zips a directory with forward-slash entry
 * names under a top-level folder, the layout WordPress expects.
 *
 *   php scripts/make-zip.php <source-dir> <out.zip> [root-folder=wp-chrono]
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

list( , $src, $out ) = array_pad( $argv, 3, '' );
$root = $argv[3] ?? 'wp-chrono';
$src  = rtrim( str_replace( '\\', '/', $src ), '/' );
if ( '' === $src || ! is_dir( $src ) || '' === $out ) {
	fwrite( STDERR, "usage: php make-zip.php <source-dir> <out.zip> [root]\n" );
	exit( 1 );
}
@unlink( $out );
$zip = new ZipArchive();
if ( true !== $zip->open( $out, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "cannot create {$out}\n" );
	exit( 1 );
}
$zip->addEmptyDir( $root );
$files = 0;
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
foreach ( $it as $item ) {
	$rel = $root . '/' . str_replace( '\\', '/', substr( $item->getPathname(), strlen( $src ) + 1 ) );
	if ( $item->isDir() ) {
		$zip->addEmptyDir( $rel );
	} else {
		$zip->addFile( $item->getPathname(), $rel );
		$files++;
	}
}
$zip->close();
echo "{$files} files -> {$out}\n";
