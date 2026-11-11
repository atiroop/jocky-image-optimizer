<?php

declare(strict_types=1);

$root = dirname( __DIR__ );
require_once __DIR__ . DIRECTORY_SEPARATOR . 'package-version.php';
try {
	$version = jio_package_version( $root );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . PHP_EOL );
	exit( 1 );
}
$archive_path = $argv[1] ?? $root . '/build/jocky-image-optimizer-' . $version . '.zip';
$expected_archive = 'jocky-image-optimizer-' . $version . '.zip';
if ( basename( $archive_path ) !== $expected_archive ) {
	fwrite( STDERR, "ZIP filename must be {$expected_archive}.\n" );
	exit( 1 );
}
$required     = array(
	'jocky-image-optimizer/VERSION',
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
if ( true !== $archive->open( $archive_path, ZipArchive::CHECKCONS ) ) {
	fwrite( STDERR, "Unable to open plugin ZIP: {$archive_path}\n" );
	exit( 1 );
}

$entries = array();
$top_level_directories = array();
for ( $index = 0; $index < $archive->numFiles; ++$index ) {
	$entry = $archive->getNameIndex( $index );
	if ( ! is_string( $entry ) || 0 !== strpos( $entry, 'jocky-image-optimizer/' ) || false !== strpos( $entry, '../' ) || false !== strpos( $entry, '\\' ) ) {
		$archive->close();
		fwrite( STDERR, "ZIP contains a path outside the expected plugin directory.\n" );
		exit( 1 );
	}
	$top_level_directories[ explode( '/', $entry )[0] ] = true;
	if ( false === str_ends_with( $entry, '/' ) ) {
		$stat    = $archive->statIndex( $index );
		$payload = $archive->getFromIndex( $index );
		if ( ! is_array( $stat ) || ! is_string( $payload ) || strlen( $payload ) !== (int) $stat['size'] ) {
			$archive->close();
			fwrite( STDERR, "ZIP contains an unreadable or truncated file.\n" );
			exit( 1 );
		}
	}
	$entries[] = $entry;
}
if ( 1 !== count( $top_level_directories ) || ! isset( $top_level_directories['jocky-image-optimizer'] ) || 0 === $archive->numFiles ) {
	$archive->close();
	fwrite( STDERR, "ZIP must contain exactly one non-empty jocky-image-optimizer top-level directory.\n" );
	exit( 1 );
}

foreach ( $required as $entry ) {
	if ( ! in_array( $entry, $entries, true ) ) {
		$archive->close();
		fwrite( STDERR, "ZIP is missing required entry: {$entry}\n" );
		exit( 1 );
	}
}

foreach ( $entries as $entry ) {
	if ( preg_match( '~^jocky-image-optimizer/(?:vendor/(?:phpunit|squizlabs|wp-coding-standards|phpcsstandards|dealerdirect)/|tests/|bin/|scripts/|\.github/|composer\.(?:json|lock)$|php(?:cs|unit)\.xml\.dist$|(?:AGENTS|SPEC|ROADMAP|ARCHITECTURE|VALIDATION|CHANGELOG)\.md$|(?:\.git|\.env|\.codex|\.gitattributes)(?:/|$))~', $entry ) || preg_match( '~(?:^|/)(?:\.env(?:\.[^/]*)?|id_rsa(?:\.pub)?|id_ed25519(?:\.pub)?|credentials(?:\.[^/]*)?|secrets?(?:\.[^/]*)?)$|\.(?:pem|p12|pfx|key)$~i', $entry ) ) {
		$archive->close();
		fwrite( STDERR, "ZIP contains a development-only file: {$entry}\n" );
		exit( 1 );
	}
}

$installed_json = $archive->getFromName( 'jocky-image-optimizer/vendor/composer/installed.json' );
$runtime_autoload = $archive->getFromName( 'jocky-image-optimizer/vendor/autoload.php' );
$runtime_autoload_real = $archive->getFromName( 'jocky-image-optimizer/vendor/composer/autoload_real.php' );
$runtime_autoload_static = $archive->getFromName( 'jocky-image-optimizer/vendor/composer/autoload_static.php' );
$plugin_entry            = $archive->getFromName( 'jocky-image-optimizer/jocky-image-optimizer.php' );
$packaged_version        = $archive->getFromName( 'jocky-image-optimizer/VERSION' );
$packaged_readme         = $archive->getFromName( 'jocky-image-optimizer/README.md' );
$archive->close();
$runtime_loader_files = array( $runtime_autoload, $runtime_autoload_real, $runtime_autoload_static );
foreach ( $runtime_loader_files as $loader_file ) {
	if ( ! is_string( $loader_file ) || false === strpos( $loader_file, 'JioPlugin' ) ) {
		fwrite( STDERR, "ZIP Composer autoloader does not use the isolated runtime suffix.\n" );
		exit( 1 );
	}
}
$installed = is_string( $installed_json ) ? json_decode( $installed_json, true ) : null;
if ( ! is_array( $installed ) || ! array_key_exists( 'dev', $installed ) || true === $installed['dev'] ) {
	fwrite( STDERR, "ZIP Composer metadata does not identify a production-only install.\n" );
	exit( 1 );
}

if ( ! is_string( $plugin_entry ) || ! is_string( $packaged_version ) || ! is_string( $packaged_readme ) || trim( $packaged_version ) !== $version || 1 !== preg_match( '/^\s*\* Version:\s*([^\r\n]+)\s*$/m', $plugin_entry, $header ) || $version !== trim( $header[1] ) || 1 !== preg_match_all( "/define\(\s*'JIO_VERSION'\s*,\s*'([^']+)'\s*\)/", $plugin_entry, $constants ) || 1 !== preg_match( '/^# Jocky Image Optimizer (\S+)\s*$/m', $packaged_readme, $title ) || $version !== $constants[1][0] || $version !== $title[1] ) {
	fwrite( STDERR, "ZIP plugin version does not match the package version.\n" );
	exit( 1 );
}

echo "Plugin ZIP structure and production dependencies are valid.\n";
