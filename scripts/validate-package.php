<?php

declare(strict_types=1);

$archive_path = $argv[1] ?? dirname( __DIR__ ) . '/build/jocky-image-optimizer-0.1.0-dev.zip';
$required     = array(
	'jocky-image-optimizer/jocky-image-optimizer.php',
	'jocky-image-optimizer/vendor/autoload.php',
	'jocky-image-optimizer/vendor/composer/autoload_psr4.php',
	'jocky-image-optimizer/vendor/composer/installed.json',
);

if ( ! class_exists( ZipArchive::class ) ) {
	fwrite( STDERR, "PHP ZipArchive extension is required.\n" );
	exit( 1 );
}

$archive = new ZipArchive();
if ( true !== $archive->open( $archive_path ) ) {
	fwrite( STDERR, "Unable to open plugin ZIP: {$archive_path}\n" );
	exit( 1 );
}

$entries = array();
for ( $index = 0; $index < $archive->numFiles; ++$index ) {
	$entry = $archive->getNameIndex( $index );
	if ( ! is_string( $entry ) || 0 !== strpos( $entry, 'jocky-image-optimizer/' ) || false !== strpos( $entry, '../' ) ) {
		$archive->close();
		fwrite( STDERR, "ZIP contains a path outside the expected plugin directory.\n" );
		exit( 1 );
	}
	$entries[] = $entry;
}

foreach ( $required as $entry ) {
	if ( ! in_array( $entry, $entries, true ) ) {
		$archive->close();
		fwrite( STDERR, "ZIP is missing required entry: {$entry}\n" );
		exit( 1 );
	}
}

foreach ( $entries as $entry ) {
	if ( preg_match( '~^jocky-image-optimizer/(?:vendor/(?:phpunit|squizlabs|wp-coding-standards|phpcsstandards|dealerdirect)/|tests/|bin/|scripts/|\.github/|composer\.(?:json|lock)$|php(?:cs|unit)\.xml\.dist$)~', $entry ) ) {
		$archive->close();
		fwrite( STDERR, "ZIP contains a development-only file: {$entry}\n" );
		exit( 1 );
	}
}

$installed_json = $archive->getFromName( 'jocky-image-optimizer/vendor/composer/installed.json' );
$archive->close();
$installed = is_string( $installed_json ) ? json_decode( $installed_json, true ) : null;
if ( ! is_array( $installed ) || ! array_key_exists( 'dev', $installed ) || true === $installed['dev'] ) {
	fwrite( STDERR, "ZIP Composer metadata does not identify a production-only install.\n" );
	exit( 1 );
}

echo "Plugin ZIP structure and production dependencies are valid.\n";
