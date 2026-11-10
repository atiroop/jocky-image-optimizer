<?php
/**
 * Helpers for production plugin package scripts.
 *
 * @package Jocky\ImageOptimizer\Scripts
 */

declare(strict_types=1);

/** Reads and verifies the version used by the plugin header and runtime.
 *
 * @param string $root Repository root path.
 * @return string Matching version from the plugin header and runtime constant.
 * @throws RuntimeException When the plugin entry point is missing or versions differ.
 */
function jio_package_version( string $root ): string {
	$plugin_file = $root . DIRECTORY_SEPARATOR . 'jocky-image-optimizer.php';
	// This is a local source file; network URLs are never passed to this helper.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local plugin header when building the standalone release ZIP.
	$contents    = is_readable( $plugin_file ) ? file_get_contents( $plugin_file ) : false;
	if ( ! is_string( $contents ) ) {
		throw new RuntimeException( 'Unable to read the plugin entry point.' );
	}
	$header_matches   = preg_match( '/^\s*\* Version:\s*([0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.]+)?)\s*$/m', $contents, $header );
	$constant_matches = preg_match( "/define\(\s*'JIO_VERSION'\s*,\s*'([0-9]+\\.[0-9]+\\.[0-9]+(?:-[A-Za-z0-9.]+)?)'\s*\)/", $contents, $constant );
	if ( 1 !== $header_matches || 1 !== $constant_matches || $header[1] !== $constant[1] ) {
		throw new RuntimeException( 'Plugin header version and JIO_VERSION must be present and match.' );
	}
	return $header[1];
}
