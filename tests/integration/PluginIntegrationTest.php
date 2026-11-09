<?php

declare(strict_types=1);

use Jocky\ImageOptimizer\Admin\AdminPages;
use Jocky\ImageOptimizer\CapabilityDetector;
use Jocky\ImageOptimizer\Plugin;
use Jocky\ImageOptimizer\Settings;

/** Tests the packaged plugin in the real WordPress test runtime. */
final class PluginIntegrationTest extends WP_UnitTestCase {

	private const PLUGIN = 'jocky-image-optimizer/jocky-image-optimizer.php';

	private static bool $plugin_booted = false;

	private static string $media_fixture = '';

	private static string $media_fixture_hash = '';

	protected function setUp(): void {
		parent::setUp();

		if ( is_plugin_active( self::PLUGIN ) ) {
			deactivate_plugins( self::PLUGIN );
		}
		delete_option( Plugin::OPTION_NAME );

		$activation = activate_plugin( self::PLUGIN, '', false, true );
		$this->assertNotWPError( $activation );
		if ( false === get_option( Plugin::OPTION_NAME, false ) ) {
			Plugin::activate();
		}

		if ( ! self::$plugin_booted ) {
			do_action( 'plugins_loaded' );
			self::$plugin_booted = true;
		}
	}

	protected function tearDown(): void {
		deactivate_plugins( self::PLUGIN );
		delete_option( Plugin::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$uploads = wp_upload_dir();
		wp_mkdir_p( $uploads['basedir'] );
		self::$media_fixture = trailingslashit( $uploads['basedir'] ) . 'jio-integration-sentinel.png';
		file_put_contents(
			self::$media_fixture,
			base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVQIHWP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC' )
		);
		self::$media_fixture_hash = hash_file( 'sha256', self::$media_fixture );
	}

	public static function tearDownAfterClass(): void {
		if ( is_file( self::$media_fixture ) ) {
			unlink( self::$media_fixture );
		}
		parent::tearDownAfterClass();
	}

	public function test_activation_reactivation_and_deactivation_preserve_settings_and_uploads(): void {
		$this->assertSame(
			realpath( WP_PLUGIN_DIR . '/jocky-image-optimizer/src/Plugin.php' ),
			realpath( ( new ReflectionClass( Plugin::class ) )->getFileName() )
		);
		$this->assertSame( Settings::defaults(), get_option( Plugin::OPTION_NAME ) );
		update_option(
			Plugin::OPTION_NAME,
			array(
				'output_format' => 'webp',
				'image_quality' => 68,
			)
		);
		deactivate_plugins( self::PLUGIN );
		$this->assertNotWPError( activate_plugin( self::PLUGIN, '', false, true ) );

		$this->assertSame( 'webp', get_option( Plugin::OPTION_NAME )['output_format'] );
		$this->assertSame( self::$media_fixture_hash, hash_file( 'sha256', self::$media_fixture ) );
		wp_cache_delete( Plugin::OPTION_NAME, 'options' );
		$this->assertSame( 'webp', get_option( Plugin::OPTION_NAME )['output_format'] );
		deactivate_plugins( self::PLUGIN );
		$this->assertSame( 'webp', get_option( Plugin::OPTION_NAME )['output_format'] );
		$this->assertSame( self::$media_fixture_hash, hash_file( 'sha256', self::$media_fixture ) );
	}

	public function test_admin_menu_settings_nonce_and_authorized_page_rendering(): void {
		$this->boot_admin_as( 'administrator' );
		global $menu, $submenu;
		$top_level_slugs = array_column( $menu, 2 );
		$this->assertContains( 'jocky-image-optimizer', $top_level_slugs );
		$this->assertSame( 'manage_options', $this->submenu_capability( $submenu['jocky-image-optimizer'], 'jocky-image-optimizer-settings' ) );
		$this->assertTrue( current_user_can( 'manage_options' ) );
		$this->assertSame( Settings::defaults(), get_registered_settings()[ Plugin::OPTION_NAME ]['default'] );

		ob_start();
		do_action( get_plugin_page_hookname( 'jocky-image-optimizer-settings', 'jocky-image-optimizer' ) );
		$page = (string) ob_get_clean();
		$this->assertStringContainsString( 'Image Optimizer Settings', $page );
		$this->assertStringContainsString( 'name="_wpnonce"', $page );
		$this->assertStringContainsString( 'jio_settings[image_quality]', $page );
		$this->assertSame( 1, preg_match( '/name="_wpnonce" value="([^"]+)"/', $page, $nonce_match ) );
		$this->assertNotFalse( wp_verify_nonce( $nonce_match[1], 'jio_settings_group-options' ) );
		$this->assertSame( self::$media_fixture_hash, hash_file( 'sha256', self::$media_fixture ) );
	}

	public function test_subscriber_is_denied_plugin_settings_page(): void {
		$this->boot_admin_as( 'subscriber' );
		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->expectException( WPDieException::class );
		(new AdminPages( new CapabilityDetector() ))->render_settings();
	}

	public function test_registered_settings_sanitize_persist_and_reject_xss(): void {
		$this->boot_admin_as( 'administrator' );
		$sanitized = sanitize_option(
			Plugin::OPTION_NAME,
			array(
				'output_format' => '<script>alert(1)</script>',
				'image_quality' => '92',
				'auto_optimize' => 'false',
			)
		);
		update_option( Plugin::OPTION_NAME, $sanitized );
		$saved = get_option( Plugin::OPTION_NAME );
		$this->assertSame( 'original', $saved['output_format'] );
		$this->assertSame( 92, $saved['image_quality'] );
		$this->assertFalse( $saved['auto_optimize'] );

		ob_start();
		(new AdminPages( new CapabilityDetector() ))->render_settings();
		$page = (string) ob_get_clean();
		$this->assertStringNotContainsString( '<script>', $page );
		$this->assertStringNotContainsString( 'alert(1)', $page );
		$this->assertSame( $saved, get_option( Plugin::OPTION_NAME ) );
	}

	public function test_diagnostics_escape_paths_and_do_not_execute_binaries(): void {
		$previous_path = getenv( 'PATH' );
		$test_dir      = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jio-<img src=x onerror=alert(1)>-' . uniqid( '', true );
		$marker        = $test_dir . DIRECTORY_SEPARATOR . 'executed';
		wp_mkdir_p( $test_dir );
		$binary = $test_dir . DIRECTORY_SEPARATOR . 'pngquant';
		file_put_contents( $binary, "#!/bin/sh\ntouch '" . $marker . "'\n" );
		chmod( $binary, 0755 );

		try {
			putenv( 'PATH=' . $test_dir );
			$detected = ( new CapabilityDetector() )->detect();
			$this->assertTrue( $detected['pngquant']['available'] );
			$this->assertFileDoesNotExist( $marker );

			$this->boot_admin_as( 'administrator' );
			ob_start();
			(new AdminPages( new CapabilityDetector() ))->render_dashboard();
			$page = (string) ob_get_clean();
			$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $page );
			$this->assertStringNotContainsString( '<img src=x onerror=alert(1)>', $page );
			$this->assertFileDoesNotExist( $marker );

			putenv( 'PATH=' );
			$this->assertFalse( ( new CapabilityDetector() )->detect()['pngquant']['available'] );
		} finally {
			if ( false !== $previous_path ) {
				putenv( 'PATH=' . $previous_path );
			} else {
				putenv( 'PATH' );
			}
			if ( is_file( $binary ) ) {
				unlink( $binary );
			}
			if ( is_file( $marker ) ) {
				unlink( $marker );
			}
			if ( is_dir( $test_dir ) ) {
				rmdir( $test_dir );
			}
		}
	}

	public function test_production_zip_is_installable_without_development_dependencies(): void {
		$package = getenv( 'JIO_PACKAGE_PATH' );
		$this->assertIsString( $package );
		$this->assertFileExists( $package );

		$archive = new ZipArchive();
		$this->assertTrue( true === $archive->open( $package ) );
		$entries = array();
		for ( $index = 0; $index < $archive->numFiles; ++$index ) {
			$entry = $archive->getNameIndex( $index );
			$this->assertStringStartsWith( 'jocky-image-optimizer/', $entry );
			$entries[] = $entry;
		}
		$this->assertContains( 'jocky-image-optimizer/jocky-image-optimizer.php', $entries );
		$this->assertContains( 'jocky-image-optimizer/vendor/autoload.php', $entries );
		$this->assertContains( 'jocky-image-optimizer/vendor/composer/autoload_psr4.php', $entries );
		$this->assertNotContains( 'jocky-image-optimizer/vendor/phpunit/phpunit/phpunit', $entries );
		$installed_json = $archive->getFromName( 'jocky-image-optimizer/vendor/composer/installed.json' );
		$this->assertIsString( $installed_json );
		$installed = json_decode( $installed_json, true );
		$this->assertFalse( $installed['dev'] );
		$this->assertSame( array(), $installed['packages'] );
		$archive->close();
	}

	private function boot_admin_as( string $role ): void {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		set_current_screen( 'dashboard' );
		(new Plugin())->boot();
		do_action( 'admin_init' );
		do_action( 'admin_menu' );
	}

	private function submenu_capability( array $submenu_items, string $slug ): ?string {
		foreach ( $submenu_items as $item ) {
			if ( $slug === $item[2] ) {
				return $item[1];
			}
		}
		return null;
	}
}
