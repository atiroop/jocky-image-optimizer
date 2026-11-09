<?php

declare(strict_types=1);

$root    = dirname( __DIR__ );
$build   = $root . DIRECTORY_SEPARATOR . 'build';
$plugin_slug = 'jocky-image-optimizer';
$zip_path    = $build . DIRECTORY_SEPARATOR . $plugin_slug . '-0.1.0-dev.zip';

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
	if ( preg_match( '~^(?:\.git|build|tests|\.github)(?:/|$)|(?:^|/)\.gitignore$|(?:^|/)composer\.lock$~', $relative ) ) {
		continue;
	}
	if ( $item->isDir() ) {
		continue;
	}
	if ( ! $zip->addFile( $absolute, $plugin_slug . '/' . $relative ) ) {
		$zip->close();
		fwrite( STDERR, "Unable to add a plugin file to the ZIP archive.\n" );
		exit( 1 );
	}
}
$zip->close();
echo $zip_path . PHP_EOL;
