<?php

$metadata_file = $argv[1] ?? '';
$expected_version = $argv[2] ?? '';
$wordpress_version = $argv[3] ?? '';
$php_version = $argv[4] ?? '';
$archive_path = $argv[5] ?? '';
$metadata = is_file( $metadata_file ) ? json_decode( (string) file_get_contents( $metadata_file ) ) : null;
$archive_url = is_object( $metadata ) && isset( $metadata->versions->{$expected_version} ) ? (string) $metadata->versions->{$expected_version} : '';
$expected_url = 'https://downloads.wordpress.org/plugin/elementor.' . rawurlencode( $expected_version ) . '.zip';

if ( $archive_url !== $expected_url || ! class_exists( ZipArchive::class ) ) {
	fwrite( STDERR, "WordPress.org does not list the pinned Elementor release at the expected official archive URL.\n" );
	exit( 1 );
}

$archive = new ZipArchive();
if ( true !== $archive->open( $archive_path, ZipArchive::CHECKCONS ) ) {
	fwrite( STDERR, "The pinned Elementor release archive is unreadable.\n" );
	exit( 1 );
}
$plugin_header = $archive->getFromName( 'elementor/elementor.php' );
$archive->close();
if ( ! is_string( $plugin_header ) || ! preg_match( '/^\s*\* Requires at least:\s*(\S+)/mi', $plugin_header, $wp_match ) || ! preg_match( '/^\s*\* Requires PHP:\s*(\S+)/mi', $plugin_header, $php_match ) ) {
	fwrite( STDERR, "The pinned Elementor archive does not contain readable WordPress/PHP compatibility headers.\n" );
	exit( 1 );
}
$minimum_wordpress = $wp_match[1];
$minimum_php = $php_match[1];
if ( version_compare( $wordpress_version, $minimum_wordpress, '<' ) || version_compare( $php_version, $minimum_php, '<' ) ) {
	fwrite( STDERR, "The pinned Elementor release's declared requirements exceed the disposable WordPress/PHP runtime.\n" );
	exit( 1 );
}

printf(
	"Verified WordPress.org Elementor %s archive; declared minimum WordPress %s, PHP %s; tested runtime WordPress %s, PHP %s.\n",
	$expected_version,
	$minimum_wordpress,
	$minimum_php,
	$wordpress_version,
	$php_version
);
