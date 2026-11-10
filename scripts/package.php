<?php

declare(strict_types=1);

$root    = dirname( __DIR__ );
$build   = $root . DIRECTORY_SEPARATOR . 'build';
$plugin_slug = 'jocky-image-optimizer';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'package-version.php';
try {
	$version = jio_package_version( $root );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . PHP_EOL );
	exit( 1 );
}
$zip_path = $build . DIRECTORY_SEPARATOR . $plugin_slug . '-' . $version . '.zip';

if ( ! class_exists( ZipArchive::class ) ) {
	fwrite( STDERR, "PHP ZipArchive extension is required.\n" );
	exit( 1 );
}

if ( ! is_file( $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php' ) ) {
	fwrite( STDERR, "Composer autoloader is missing. Run composer install first.\n" );
	exit( 1 );
}

$installed_file = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';
$installed_data = is_file( $installed_file ) ? json_decode( (string) file_get_contents( $installed_file ), true ) : array();
if ( ! is_array( $installed_data ) || ! empty( $installed_data['dev'] ) ) {
	fwrite( STDERR, "Development Composer dependencies are installed. Run composer install --no-dev before packaging.\n" );
	exit( 1 );
}

if ( ! is_dir( $build ) && ! mkdir( $build, 0775, true ) && ! is_dir( $build ) ) {
	fwrite( STDERR, "Unable to create build directory.\n" );
	exit( 1 );
}

$zip = new ZipArchive();
if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
	fwrite( STDERR, "Unable to create ZIP archive.\n" );
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);
foreach ( $iterator as $item ) {
	$absolute = $item->getPathname();
	$relative = substr( $absolute, strlen( $root ) + 1 );
	$relative = str_replace( DIRECTORY_SEPARATOR, '/', $relative );
	if ( preg_match( '~^(?:\.git|build|tests|\.github|bin|scripts|AGENTS\.md|SPEC\.md|ROADMAP\.md|ARCHITECTURE\.md|VALIDATION\.md)(?:/|$)|(?:^|/)(?:\.gitignore|\.gitattributes|composer\.(?:json|lock)|phpcs\.xml\.dist|phpunit\.xml\.dist)$~', $relative ) ) {
		continue;
	}
	if ( $item->isDir() ) {
		continue;
	}
	$archive_name = $plugin_slug . '/' . $relative;
	if ( in_array( $relative, array( 'vendor/autoload.php', 'vendor/composer/autoload_real.php', 'vendor/composer/autoload_static.php' ), true ) ) {
		$contents = file_get_contents( $absolute );
		if ( ! is_string( $contents ) ) {
			$zip->close();
			fwrite( STDERR, "Unable to read Composer autoloader file: {$relative}\n" );
			exit( 1 );
		}
		$contents = preg_replace( '/ComposerAutoloaderInit[A-Za-z0-9_]+/', 'ComposerAutoloaderInitJioPlugin', $contents );
		$contents = preg_replace( '/ComposerStaticInit[A-Za-z0-9_]+/', 'ComposerStaticInitJioPlugin', $contents );
		$added    = is_string( $contents ) && $zip->addFromString( $archive_name, $contents );
	} else {
		$added = $zip->addFile( $absolute, $archive_name );
	}
	if ( ! $added ) {
		$zip->close();
		fwrite( STDERR, "Unable to add a plugin file to the ZIP archive.\n" );
		exit( 1 );
	}
}
$zip->close();
echo $zip_path . PHP_EOL;
