<?php
/**
 * Standalone foundation checks using small WordPress API stubs.
 *
 * @package Jocky\ImageOptimizer\Tests
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/vendor/autoload.php';

use Jocky\ImageOptimizer\CapabilityDetector;
use Jocky\ImageOptimizer\Admin\AdminPages;
use Jocky\ImageOptimizer\Plugin;
use Jocky\ImageOptimizer\Settings;

$testOptions = array();
$test_hooks = array();
$test_menu_pages = array();
$test_registered_settings = array();

function add_action( string $hook, $callback ): void {
	global $test_hooks;
	$test_hooks[ $hook ] = $callback;
}
function __( string $text, string $domain = 'default' ): string {
	return $text;
}
function esc_html__( string $text, string $domain = 'default' ): string {
	return $text;
}
function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback, $icon_url = '', $position = null ): void {
	global $test_menu_pages;
	$test_menu_pages[ $menu_slug ] = $capability;
}
function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '' ): void {
	global $test_menu_pages;
	$test_menu_pages[ $menu_slug ] = $capability;
}
function register_setting( $group, $name, $args = array() ): void {
	global $test_registered_settings;
	$test_registered_settings[ $name ] = $args;
}

function get_option( string $name, $default = false ) {
	global $testOptions;
	return $testOptions[ $name ] ?? $default;
}
function add_option( string $name, $value = '', string $deprecated = '', $autoload = 'yes' ): bool {
	global $testOptions;
	if ( array_key_exists( $name, $testOptions ) ) {
		return false;
	}
	$testOptions[ $name ] = $value;
	return true;
}

$failures = 0;
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		++$failures;
	} else {
		echo "PASS: {$message}\n";
	}
};

$defaults = Settings::defaults();
$assert( $defaults['concurrency'] === 1, 'default worker concurrency is bounded to one' );
$assert( $defaults['output_format'] === 'original', 'default output preserves original format' );

$settings = Settings::sanitize(
	array(
		'output_format' => 'unknown',
		'image_quality' => 500,
		'max_width'     => -5,
		'max_height'    => '640',
		'auto_optimize' => '1',
		'concurrency'   => 10,
	)
);
$assert( $settings['output_format'] === 'original', 'invalid output format uses safe default' );
$assert( $settings['image_quality'] === 100, 'quality is capped at 100' );
$assert( $settings['max_width'] === 0 && $settings['max_height'] === 640, 'dimensions are sanitized and bounded' );
$assert( $settings['auto_optimize'] === true && $settings['keep_backup'] === true, 'checkbox values are normalized safely' );
$false_settings = Settings::sanitize( array( 'auto_optimize' => 'false', 'keep_backup' => 'false' ) );
$assert( $false_settings['auto_optimize'] === false && $false_settings['keep_backup'] === true, 'unrecognized boolean strings do not enable settings' );
$assert( $settings['concurrency'] === 1, 'concurrency cannot exceed one in Milestone 1' );
$assert( count( ( new CapabilityDetector() )->detect() ) >= 8, 'capability detector reports configured runtime checks' );

Plugin::activate();
$assert( isset( $testOptions[ Plugin::OPTION_NAME ] ) && $testOptions[ Plugin::OPTION_NAME ] === Settings::defaults(), 'activation stores defaults when settings are absent' );
$testOptions[ Plugin::OPTION_NAME ] = array( 'existing' => 'preserve' );
Plugin::activate();
$assert( $testOptions[ Plugin::OPTION_NAME ] === array( 'existing' => 'preserve' ), 'activation preserves existing settings' );
Plugin::deactivate();
$assert( $testOptions[ Plugin::OPTION_NAME ] === array( 'existing' => 'preserve' ), 'deactivation leaves settings and media state untouched' );

$admin = new AdminPages( new CapabilityDetector() );
$admin->register();
$assert( isset( $test_hooks['admin_menu'], $test_hooks['admin_init'] ), 'admin hooks register for menu and settings initialization' );
call_user_func( $test_hooks['admin_menu'] );
call_user_func( $test_hooks['admin_init'] );
$assert( isset( $test_menu_pages['jocky-image-optimizer'], $test_menu_pages['jocky-image-optimizer-settings'] ), 'Dashboard and Settings menu pages are registered' );
$assert( $test_menu_pages['jocky-image-optimizer-settings'] === 'manage_options', 'Settings page requires manage_options capability' );
$sanitizer = $test_registered_settings[ Plugin::OPTION_NAME ]['sanitize_callback'];
$testOptions[ Plugin::OPTION_NAME ] = call_user_func( $sanitizer, array( 'output_format' => 'webp', 'image_quality' => 74 ) );
$assert( $testOptions[ Plugin::OPTION_NAME ]['output_format'] === 'webp' && $testOptions[ Plugin::OPTION_NAME ]['image_quality'] === 74, 'registered Settings API sanitizer persists valid settings' );

exit( $failures === 0 ? 0 : 1 );
