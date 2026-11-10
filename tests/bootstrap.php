<?php
/**
 * Bootstrap for tests against the WordPress core PHPUnit environment.
 *
 * @package Jocky\ImageOptimizer\Tests
 */

declare(strict_types=1);

// WordPress's test bootstrap prints status text before admin header checks run.
ob_start();

// The disposable WordPress integration suite opts in only to exercise guarded deletion code.
if ( ! defined( 'JIO_ENABLE_DESTRUCTIVE_RECOVERY' ) ) {
	define( 'JIO_ENABLE_DESTRUCTIVE_RECOVERY', true );
}
if ( ! defined( 'WP_ENVIRONMENT_TYPE' ) ) {
	define( 'WP_ENVIRONMENT_TYPE', 'local' );
}

$tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! is_string( $tests_dir ) || '' === $tests_dir ) {
	$tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR . 'wordpress-tests-lib';
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
$phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( is_string( $phpunit_polyfills_path ) && '' !== $phpunit_polyfills_path && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $phpunit_polyfills_path );
}
$wordpress_core  = getenv( 'WP_CORE_DIR' );
if ( ! is_string( $wordpress_core ) || '' === $wordpress_core ) {
	$wordpress_core = dirname( $tests_dir ) . DIRECTORY_SEPARATOR . 'wordpress';
}
$package_loader = new Composer\Autoload\ClassLoader();
$package_loader->addPsr4( 'Jocky\\ImageOptimizer\\', $wordpress_core . '/wp-content/plugins/jocky-image-optimizer/src' );
$package_loader->register( true );

if ( ! is_file( $tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library is missing. Run bin/install-wp-tests.sh first.\n" );
	exit( 1 );
}

require_once $tests_dir . '/includes/functions.php';
require $tests_dir . '/includes/bootstrap.php';

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
