<?php
/**
 * Helpers for production plugin package scripts.
 *
 * @package Jocky\ImageOptimizer\Scripts
 */

declare(strict_types=1);

/** Reads the authoritative version and rejects inconsistent release metadata.
 *
 * @param string $root Repository root path.
 * @return string Valid SemVer version from VERSION.
 * @throws RuntimeException When release metadata is missing, invalid, or inconsistent.
 */
function jio_package_version( string $root ): string {
	$version_path = $root . DIRECTORY_SEPARATOR . 'VERSION';
	$version      = is_readable( $version_path ) ? trim( (string) file_get_contents( $version_path ) ) : '';
	$semver       = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-((?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/';
	if ( 1 !== preg_match( $semver, $version ) ) {
		throw new RuntimeException( 'VERSION must contain one valid semantic version.' );
	}

	$plugin_file = $root . DIRECTORY_SEPARATOR . 'jocky-image-optimizer.php';
	$readme_file = $root . DIRECTORY_SEPARATOR . 'README.md';
	$changelog   = $root . DIRECTORY_SEPARATOR . 'CHANGELOG.md';
	$plugin      = is_readable( $plugin_file ) ? file_get_contents( $plugin_file ) : false;
	$readme      = is_readable( $readme_file ) ? file_get_contents( $readme_file ) : false;
	$changes     = is_readable( $changelog ) ? file_get_contents( $changelog ) : false;
	if ( ! is_string( $plugin ) || ! is_string( $readme ) || ! is_string( $changes ) ) {
		throw new RuntimeException( 'Plugin, README, and changelog version metadata must be readable.' );
	}

	$header_count = preg_match_all( '/^\s*\* Version:\s*([^\r\n]+)\s*$/m', $plugin, $headers );
	$constant_count = preg_match_all( "/define\(\s*'JIO_VERSION'\s*,\s*'([^']+)'\s*\)/", $plugin, $constants );
	$title_matches = preg_match( '/^# Jocky Image Optimizer (\S+)\s*$/m', $readme, $title );
	$changelog_matches = preg_match( '/^##\s+' . preg_quote( $version, '/' ) . '\s+—/m', $changes );
	if ( 1 !== $header_count || 1 !== $constant_count || 1 !== $title_matches || 1 !== $changelog_matches || $version !== trim( $headers[1][0] ) || $version !== trim( $constants[1][0] ) || $version !== $title[1] ) {
		throw new RuntimeException( 'VERSION, plugin header, JIO_VERSION, README title, and current changelog release must match.' );
	}

	return $version;
}
