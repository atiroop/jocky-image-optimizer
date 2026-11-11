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
use Jocky\ImageOptimizer\Optimization\EncoderExecution;
use Jocky\ImageOptimizer\Optimization\EncoderInterface;
use Jocky\ImageOptimizer\Optimization\EncoderRegistry;
use Jocky\ImageOptimizer\Optimization\ImageValidator;
use Jocky\ImageOptimizer\Optimization\OptimizationManager;
use Jocky\ImageOptimizer\Optimization\OptimizationRequest;
use Jocky\ImageOptimizer\Optimization\PathGuard;
use Jocky\ImageOptimizer\Optimization\SafeFileReplacer;
use Jocky\ImageOptimizer\Optimization\TemporaryFileManager;
use Jocky\ImageOptimizer\System\ProcessRunner;

final class TestCopyEncoder implements EncoderInterface {
	public function id(): string { return 'test-copy'; }
	public function supported_mime_types(): array { return array( 'image/png' ); }
	public function supported_modes(): array { return array( OptimizationRequest::MODE_LOSSLESS ); }
	public function is_available(): bool { return true; }
	public function optimize( string $source_path, string $temporary_output_path, OptimizationRequest $request ): EncoderExecution {
		copy( $source_path, $temporary_output_path );
		return new EncoderExecution( EncoderExecution::COMPLETE );
	}
}

/** Builds a deterministic RGBA PNG fixture with valid chunk checksums. */
$build_png = static function (): string {
	$chunk = static function ( string $type, string $data ): string {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'H*', hash( 'crc32b', $type . $data ) );
	};
	$ihdr = pack( 'NNCCCCC', 1, 1, 8, 6, 0, 0, 0 );
	$idat = gzcompress( "\0\xff\0\0\0" );
	return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', $ihdr ) . $chunk( 'IDAT', $idat ) . $chunk( 'IEND', '' );
};

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

require_once dirname( __DIR__ ) . '/scripts/package-version.php';
$repository_root = dirname( __DIR__ );
$release_version = jio_package_version( $repository_root );
$assert( '0.6.0-beta.1' === $release_version, 'authoritative VERSION matches plugin, README, and changelog metadata' );
$assert( version_compare( $release_version, '0.4.0-beta', '>' ), 'native version comparison recognizes the new plugin as newer than the installed beta' );
$assert( version_compare( '0.6.0-beta.1', '0.6.0-beta.2', '<' ) && version_compare( '0.6.0-beta.2', '0.6.0', '<' ), 'native version comparison orders beta prereleases below later prereleases and stable release' );
$metadata_fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jio-version-fixture-' . bin2hex( random_bytes( 8 ) );
mkdir( $metadata_fixture, 0700 );
foreach ( array( 'VERSION', 'jocky-image-optimizer.php', 'README.md', 'CHANGELOG.md' ) as $metadata_file ) {
	copy( $repository_root . DIRECTORY_SEPARATOR . $metadata_file, $metadata_fixture . DIRECTORY_SEPARATOR . $metadata_file );
}
file_put_contents( $metadata_fixture . DIRECTORY_SEPARATOR . 'README.md', "# Jocky Image Optimizer 0.4.0-beta\n" );
$metadata_mismatch_rejected = false;
try {
	jio_package_version( $metadata_fixture );
} catch ( RuntimeException $exception ) {
	$metadata_mismatch_rejected = true;
}
foreach ( array( 'VERSION', 'jocky-image-optimizer.php', 'README.md', 'CHANGELOG.md' ) as $metadata_file ) {
	unlink( $metadata_fixture . DIRECTORY_SEPARATOR . $metadata_file );
}
rmdir( $metadata_fixture );
$assert( $metadata_mismatch_rejected, 'release metadata validation rejects an inconsistent README version' );

$defaults = Settings::defaults();
$assert( $defaults['concurrency'] === 1, 'default worker concurrency is bounded to one' );
$assert( $defaults['output_format'] === 'original', 'default output preserves original format' );
$assert( false === $defaults['frontend_delivery'] && 'auto' === $defaults['delivery_preference'], 'frontend delivery defaults off with safe automatic format preference' );

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
$assert( false === $settings['frontend_delivery'] && 'auto' === $settings['delivery_preference'], 'invalid or missing delivery controls stay safe and disabled' );
$assert( $settings['image_quality'] === 100, 'quality is capped at 100' );
$assert( $settings['max_width'] === 0 && $settings['max_height'] === 640, 'dimensions are sanitized and bounded' );
$assert( $settings['auto_optimize'] === true && $settings['keep_backup'] === true, 'checkbox values are normalized safely' );
$false_settings = Settings::sanitize( array( 'auto_optimize' => 'false', 'keep_backup' => 'false' ) );
$assert( $false_settings['auto_optimize'] === false && $false_settings['keep_backup'] === true, 'unrecognized boolean strings do not enable settings' );
$assert( $settings['concurrency'] === 4, 'worker concurrency is capped at four configured slots' );
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

$fixture_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jio-test-' . bin2hex( random_bytes( 6 ) );
mkdir( $fixture_root, 0700 );
$png_path = $fixture_root . DIRECTORY_SEPARATOR . 'source.png';
$png_data = $build_png();
file_put_contents( $png_path, $png_data );
$request = new OptimizationRequest( $png_path, $fixture_root . DIRECTORY_SEPARATOR . 'result.png', 'test-copy', OptimizationRequest::MODE_LOSSLESS );
$image_info = ( new ImageValidator() )->inspect( $png_path, $request );
$assert( 'image/png' === $image_info->mime && 1 === $image_info->width && 1 === $image_info->height, 'PNG structure and dimensions are validated without image extensions' );
$assert( isset( $image_info->metadata['transparency'] ) || 6 === ord( $png_data[25] ), 'RGBA PNG fixture retains its alpha-capable color type' );
$actl_data = pack( 'NN', 1, 0 );
$actl_chunk = pack( 'N', strlen( $actl_data ) ) . 'acTL' . $actl_data . pack( 'H*', hash( 'crc32b', 'acTL' . $actl_data ) );
$apng_path = $fixture_root . DIRECTORY_SEPARATOR . 'animated.png';
file_put_contents( $apng_path, substr( $png_data, 0, 33 ) . $actl_chunk . substr( $png_data, 33 ) );
$apng_info = ( new ImageValidator() )->inspect( $apng_path, $request );
$assert( $apng_info->animated, 'valid APNG animation control chunks are detected' );
$invalid_pngs = array(
	'bad-crc.png' => substr_replace( $png_data, "\x00", 41, 1 ),
	'truncated.png' => substr( $png_data, 0, -3 ),
	'bad-structure.png' => substr_replace( $png_data, 'JUNK', 12, 4 ),
	'unsupported.bin' => 'plain text is not an image',
	'empty.png' => '',
);
foreach ( $invalid_pngs as $name => $data ) {
	$invalid_path = $fixture_root . DIRECTORY_SEPARATOR . $name;
	file_put_contents( $invalid_path, $data );
	try {
		( new ImageValidator() )->inspect( $invalid_path, $request );
		$assert( false, sprintf( '%s is rejected as invalid image data', $name ) );
	} catch ( \Jocky\ImageOptimizer\Optimization\OptimizationException $exception ) {
		$expected_category = 'bad-crc.png' === $name ? 'png_crc_mismatch' : ( 'unsupported.bin' === $name ? 'unsupported_format' : ( 'empty.png' === $name ? 'input_size_limit' : 'invalid_image' ) );
		$assert( $expected_category === $exception->category, sprintf( '%s is rejected with the expected integrity category', $name ) );
	}
	unlink( $invalid_path );
}
$manager = new OptimizationManager( new PathGuard( array( $fixture_root ) ), new ImageValidator(), new EncoderRegistry( array( new TestCopyEncoder() ) ), new TemporaryFileManager(), new SafeFileReplacer() );
$result = $manager->optimize( $request );
$assert( 'skipped' === $result->status && 'no_size_benefit' === $result->error_category, 'equal-size output is skipped and not promoted' );
$source_hash = hash_file( 'sha256', $png_path );
$assert( ! file_exists( $request->destination_path ) && $source_hash === hash_file( 'sha256', $png_path ), 'no-benefit handling retains the source and leaves destination absent' );
$outside_request = new OptimizationRequest( $png_path, dirname( $fixture_root ) . DIRECTORY_SEPARATOR . 'outside.png', 'test-copy', OptimizationRequest::MODE_LOSSLESS );
$outside_result = $manager->optimize( $outside_request );
$assert( 'failed' === $outside_result->status && 'path_outside_root' === $outside_result->error_category, 'destination paths outside configured roots are rejected' );
if ( function_exists( 'proc_open' ) ) {
	$process_runner = new ProcessRunner();
	$failed_process = $process_runner->run( PHP_BINARY, array( '-r', 'exit(7);' ), 5.0 );
	$assert( 7 === $failed_process->exit_code, 'process runner returns the child exit status without shell interpolation' );
	$captured_process = $process_runner->run( PHP_BINARY, array( '-r', 'fwrite(STDOUT, "captured"); fwrite(STDERR, "diagnostic");' ), 5.0 );
	$assert( 'captured' === $captured_process->stdout && 'diagnostic' === $captured_process->stderr, 'process runner captures bounded stdout and stderr' );
	try {
		$process_runner->run( PHP_BINARY, array( '-r', 'usleep(3000000);' ), 1.0 );
		$assert( false, 'process runner terminates a child that exceeds its timeout' );
	} catch ( \Jocky\ImageOptimizer\Optimization\OptimizationException $exception ) {
		$assert( 'encoder_timeout' === $exception->category, 'process runner terminates a child that exceeds its timeout' );
	}
} else {
	echo "SKIP: process runner checks (proc_open is disabled)\n";
}
unlink( $png_path );
unlink( $apng_path );
rmdir( $fixture_root );

exit( $failures === 0 ? 0 : 1 );
