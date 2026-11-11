<?php

declare(strict_types=1);

use Jocky\ImageOptimizer\Admin\AdminPages;
use Jocky\ImageOptimizer\CapabilityDetector;
use Jocky\ImageOptimizer\Delivery\FrontendDelivery;
use Jocky\ImageOptimizer\Media\AttachmentTargetProvider;
use Jocky\ImageOptimizer\Media\MediaIntegration;
use Jocky\ImageOptimizer\Queue\AttachmentJobFactory;
use Jocky\ImageOptimizer\Queue\BulkScanner;
use Jocky\ImageOptimizer\Queue\JobRepository;
use Jocky\ImageOptimizer\Queue\QueueSchema;
use Jocky\ImageOptimizer\Queue\QueueState;
use Jocky\ImageOptimizer\Queue\QueueWorker;
use Jocky\ImageOptimizer\Recovery\AttachmentOperationLock;
use Jocky\ImageOptimizer\Recovery\RecoveryService;
use Jocky\ImageOptimizer\Recovery\RecoveryWorker;
use Jocky\ImageOptimizer\Plugin;
use Jocky\ImageOptimizer\Settings;

/** Tests the packaged plugin in the real WordPress test runtime. */
final class PluginIntegrationTest extends WP_UnitTestCase {

	private const PLUGIN = 'jocky-image-optimizer/jocky-image-optimizer.php';

	private static string $media_fixture = '';

	private static string $media_fixture_hash = '';

	private JobRepository $jobs;

	private array $test_media_directories = array();

	private array $test_attachment_ids = array();

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

		// WordPress PHPUnit resets hooks between tests, so bootstrap each test fixture again.
		( new Plugin() )->boot();
		QueueSchema::maybe_install();
		$this->jobs = new JobRepository();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . QueueSchema::jobs_table() );
		$wpdb->query( 'DELETE FROM ' . QueueSchema::derivatives_table() );
		$wpdb->query( 'DELETE FROM ' . QueueSchema::events_table() );
		$wpdb->query( 'DELETE FROM ' . QueueSchema::cleanup_table() );
		$wpdb->query( 'UPDATE ' . QueueSchema::slots_table() . ' SET lock_token = NULL, locked_at = NULL' );
		wp_clear_scheduled_hook( QueueWorker::CRON_HOOK );
		wp_clear_scheduled_hook( BulkScanner::CRON_HOOK );
		wp_clear_scheduled_hook( RecoveryWorker::CRON_HOOK );
		delete_option( BulkScanner::STATE );
		QueueState::resume();
		$this->test_media_directories = array();
		$this->test_attachment_ids    = array();
	}

	protected function tearDown(): void {
		foreach ( $this->test_attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
		foreach ( $this->test_media_directories as $directory ) {
			foreach ( glob( trailingslashit( $directory ) . '*.jio.*' ) ?: array() as $derivative ) {
				if ( is_file( $derivative ) && ! is_link( $derivative ) ) {
					unlink( $derivative );
				}
			}
			if ( is_dir( $directory ) && ! is_link( $directory ) ) {
				rmdir( $directory );
			}
		}
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . QueueSchema::jobs_table() );
		$wpdb->query( 'DELETE FROM ' . QueueSchema::derivatives_table() );
		$wpdb->query( 'DELETE FROM ' . QueueSchema::events_table() );
		$wpdb->query( 'DELETE FROM ' . QueueSchema::cleanup_table() );
		$wpdb->query( 'UPDATE ' . QueueSchema::slots_table() . ' SET lock_token = NULL, locked_at = NULL' );
		wp_clear_scheduled_hook( QueueWorker::CRON_HOOK );
		wp_clear_scheduled_hook( BulkScanner::CRON_HOOK );
		wp_clear_scheduled_hook( RecoveryWorker::CRON_HOOK );
		delete_option( BulkScanner::STATE );
		QueueState::resume();
		deactivate_plugins( self::PLUGIN );
		delete_option( Plugin::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		QueueSchema::install();
		$uploads = wp_upload_dir();
		wp_mkdir_p( $uploads['basedir'] );
		self::$media_fixture = trailingslashit( $uploads['basedir'] ) . 'jio-integration-sentinel.png';
		file_put_contents( self::$media_fixture, self::png_fixture_data() );
		self::$media_fixture_hash = hash_file( 'sha256', self::$media_fixture );
	}

	public function test_schema_seed_recovery_and_queue_leases_preserve_wordpress_test_transaction(): void {
		global $wpdb;
		$jobs_table  = QueueSchema::jobs_table();
		$slots_table = QueueSchema::slots_table();
		$this->assertSame( $jobs_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $jobs_table ) ) );
		$this->assertSame( $slots_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $slots_table ) ) );
		$this->assertSame( QueueSchema::derivatives_table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', QueueSchema::derivatives_table() ) ) );
		$this->assertSame( QueueSchema::events_table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', QueueSchema::events_table() ) ) );
		$this->assertSame( QueueSchema::cleanup_table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', QueueSchema::cleanup_table() ) ) );
		$this->assertSame( QueueSchema::VERSION, (int) get_option( QueueSchema::OPTION ) );
		$this->assertSame( 'InnoDB', $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $jobs_table ) ) );
		$this->assertSame( 'InnoDB', $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $slots_table ) ) );
		$this->assertSame( 'InnoDB', $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', QueueSchema::derivatives_table() ) ) );

		$wpdb->query( "DELETE FROM {$slots_table} WHERE slot_id IN (0,1,2,3,4)" );
		QueueSchema::maybe_install();
		$slot_ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT slot_id FROM {$slots_table} ORDER BY slot_id ASC" ) );
		$this->assertSame( array( 0, 1, 2, 3, 4 ), $slot_ids, 'current schemas reseed missing lock rows' );
		$this->assertNull( $wpdb->get_var( "SELECT lock_token FROM {$slots_table} WHERE slot_id = 0" ), 'scanner slot is available after seeding' );
		$this->assertNull( $wpdb->get_var( "SELECT lock_token FROM {$slots_table} WHERE slot_id = 1" ), 'first worker slot is available after seeding' );
		$this->assertSame( '', $wpdb->last_error, 'queue schema and seed queries report no database error' );

		$commit_count = $this->database_commit_count();
		$repository   = new JobRepository();
		$worker_token = bin2hex( random_bytes( 32 ) );
		$this->assertTrue( $repository->acquire_slot( $worker_token, 1 ) );
		$this->assertTrue( $repository->renew_slot( $worker_token ), 'same-second renewal confirms existing token ownership' );
		$this->assertSame( $commit_count, $this->database_commit_count(), 'worker leases do not commit WordPress test transactions' );
		$repository->release_slot( $worker_token );
		$this->assertFalse( $repository->renew_slot( $worker_token ) );

		$scanner_token = bin2hex( random_bytes( 32 ) );
		$this->assertTrue( $repository->acquire_scanner_lease( $scanner_token ) );
		$this->assertSame( $commit_count, $this->database_commit_count(), 'scanner lease does not commit WordPress test transactions' );
		$repository->release_scanner_lease( $scanner_token );
		$this->assertTrue( $repository->acquire_scanner_lease( bin2hex( random_bytes( 32 ) ) ), 'scanner lease is released for the next page' );
	}

	public function test_schema_version_mismatch_reapplies_idempotent_migration(): void {
		global $wpdb;
		[ $attachment_id ] = $this->create_png_attachment( 'migration-preserves-data', false );
		$this->set_output_format( 'webp' );
		$this->jobs->enqueue_many( ( new AttachmentJobFactory() )->jobs_for_attachment( $attachment_id ) );
		$job_count = $this->jobs->pending_count();
		update_post_meta(
			$attachment_id,
			'_jio_derivative_legacy',
			array(
				'derivative_relative_path' => '2026/11/source.jio.webp',
				'output_format'            => 'webp',
			)
		);
		$registry = new \Jocky\ImageOptimizer\Recovery\DerivativeRepository();
		$registry_id = $registry->record(
			array(
				'attachment_id' => $attachment_id,
				'variant_key' => 'full',
				'source_relative_path' => 'migration-preserves-data.png',
				'source_mime' => 'image/png',
				'source_bytes' => 20,
				'source_sha256' => str_repeat( 'a', 64 ),
				'source_fingerprint' => str_repeat( 'b', 64 ),
				'metadata_hash' => str_repeat( 'c', 64 ),
				'derivative_relative_path' => 'migration-preserves-data.jio.webp',
				'output_format' => 'image/webp',
				'derivative_sha256' => str_repeat( 'd', 64 ),
				'derivative_identity' => '1:2:3:4',
				'input_bytes' => 20,
				'output_bytes' => 10,
				'encoder_id' => 'test',
				'encoder_parameters' => '{}',
			)
		);
		$this->assertGreaterThan( 0, $registry_id );
		update_option( QueueSchema::OPTION, QueueSchema::VERSION - 1 );
		QueueSchema::maybe_install();
		$this->assertSame( QueueSchema::VERSION, (int) get_option( QueueSchema::OPTION ) );
		$this->assertSame( $job_count, $this->jobs->pending_count(), 'migration preserves pre-existing queue records' );
		$this->assertSame( '2026/11/source.jio.webp', get_post_meta( $attachment_id, '_jio_derivative_legacy', true )['derivative_relative_path'], 'migration preserves legacy derivative ownership metadata for safe indexing' );
		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( '1:2:3:4', $registry->get( $registry_id )['derivative_identity'], 'additive schema migration preserves existing derivative rows and metadata' );
	}

	public function test_bulk_scanner_normalizes_legacy_partial_progress_state(): void {
		$scanner = new BulkScanner( $this->jobs, new QueueWorker( $this->jobs ) );
		update_option( BulkScanner::STATE, array( 'status' => 'scanning', 'last_id' => '12' ) );
		$state = $scanner->state();
		$this->assertSame( 'scanning', $state['status'] );
		$this->assertSame( 12, $state['last_id'] );
		$this->assertArrayHasKey( 'scanned', $state );
		$this->assertArrayHasKey( 'jobs_added', $state );
		$this->assertArrayHasKey( 'total_images', $state );
		$this->assertSame( 0, $state['scanned'] );
		$this->assertSame( 0, $state['jobs_added'] );
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
		$this->jobs->enqueue_many(
			array(
				array(
					'attachment_id'      => 999999,
					'variant_key'        => 'full',
					'output_format'      => 'source',
					'source_fingerprint' => str_repeat( 'a', 64 ),
				),
			)
		);
		update_option(
			Plugin::OPTION_NAME,
			array(
				'output_format' => 'webp',
				'image_quality' => 68,
			)
		);
		deactivate_plugins( self::PLUGIN );
		$this->assertSame( 1, $this->jobs->pending_count(), 'deactivation leaves persistent queue jobs intact' );
		$this->assertNotWPError( activate_plugin( self::PLUGIN, '', false, true ) );

		$this->assertSame( 'webp', get_option( Plugin::OPTION_NAME )['output_format'] );
		$this->assertSame( self::$media_fixture_hash, hash_file( 'sha256', self::$media_fixture ) );
		wp_cache_delete( Plugin::OPTION_NAME, 'options' );
		$this->assertSame( 'webp', get_option( Plugin::OPTION_NAME )['output_format'] );
		deactivate_plugins( self::PLUGIN );
		$this->assertSame( 'webp', get_option( Plugin::OPTION_NAME )['output_format'] );
		$this->assertSame( self::$media_fixture_hash, hash_file( 'sha256', self::$media_fixture ) );
	}

	public function test_beta_zip_activation_initializes_queue_schema_and_safe_defaults(): void {
		global $wpdb;
		$plugin_data = get_plugin_data( JIO_FILE, false, false );
		$this->assertSame( '0.6.0-beta.1', JIO_VERSION );
		$this->assertSame( JIO_VERSION, $plugin_data['Version'], 'WordPress reads the uploaded plugin header version' );
		$this->assertTrue( version_compare( $plugin_data['Version'], '0.4.0-beta', '>' ), 'WordPress native version comparison recognizes the uploaded release as newer' );
		$this->assertTrue( version_compare( '0.6.0-beta.1', '0.6.0-beta.2', '<' ), 'WordPress native comparison orders prerelease identifiers' );
		$this->assertTrue( version_compare( '0.6.0-beta.2', '0.6.0', '<' ), 'WordPress native comparison keeps prereleases below final releases' );
		$this->assertTrue( is_plugin_active( self::PLUGIN ) );
		$this->assertFalse( Settings::sanitize( get_option( Plugin::OPTION_NAME ) )['auto_optimize'] );
		$this->assertSame( QueueSchema::VERSION, (int) get_option( QueueSchema::OPTION ) );
		$this->assertSame( QueueSchema::jobs_table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', QueueSchema::jobs_table() ) ) );
		$this->assertSame( QueueSchema::slots_table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', QueueSchema::slots_table() ) ) );
		$this->assertSame( array( 0, 1, 2, 3, 4 ), array_map( 'intval', (array) $wpdb->get_col( 'SELECT slot_id FROM ' . QueueSchema::slots_table() . ' ORDER BY slot_id ASC' ) ) );
	}

	public function test_media_row_action_and_queue_controls_require_administrator_capability(): void {
		[ $attachment_id ] = $this->create_png_attachment( 'permissions', false );
		$integration       = new MediaIntegration( $this->jobs, new QueueWorker( $this->jobs ) );
		$administrator     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );
		$actions = $integration->attachment_row_action( array(), get_post( $attachment_id ) );
		$this->assertArrayHasKey( 'jio_optimize', $actions );
		$this->assertStringContainsString( '_wpnonce=', $actions['jio_optimize'] );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$this->assertSame( array(), $integration->attachment_row_action( array(), get_post( $attachment_id ) ) );
		$_POST['queue_action'] = 'cancel';
		$this->expectException( WPDieException::class );
		( new AdminPages( new CapabilityDetector(), $this->jobs, new BulkScanner( $this->jobs, new QueueWorker( $this->jobs ) ), new QueueWorker( $this->jobs ) ) )->handle_queue_action();
	}

	public function test_recovery_cleanup_handler_rejects_users_without_admin_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( WPDieException::class );
		( new AdminPages( new CapabilityDetector(), $this->jobs ) )->handle_attachment_cleanup();
	}

	public function test_recovery_cleanup_request_rejects_invalid_nonce_id_and_missing_confirmation_before_filesystem_changes(): void {
		$this->set_output_format( 'webp' );
		[ $attachment_id, $source ] = $this->create_png_attachment( 'admin-request-safety', false );
		$this->assertSame( 1, $this->jobs->enqueue_many( ( new AttachmentJobFactory() )->jobs_for_attachment( $attachment_id ) ) );
		$this->assertSame( 1, ( new QueueWorker( $this->jobs ) )->run_batch( 1 )['completed'] );
		$derivative = preg_replace( '/\\.png$/', '.jio.webp', $source );
		$this->assertFileExists( $derivative );
		$derivative_hash = hash_file( 'sha256', $derivative );
		$original_post   = $_POST;
		$admin_id        = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$handler         = new AdminPages( new CapabilityDetector(), $this->jobs );
		$valid_nonce     = wp_create_nonce( 'jio_recovery_cleanup_' . $attachment_id );
		$requests        = array(
			'missing_nonce' => array( 'attachment_id' => (string) $attachment_id, 'confirm_cleanup' => '1' ),
			'invalid_nonce' => array( 'attachment_id' => (string) $attachment_id, 'confirm_cleanup' => '1', '_wpnonce' => 'invalid' ),
			'no_confirmation' => array( 'attachment_id' => (string) $attachment_id, '_wpnonce' => $valid_nonce ),
			'invalid_attachment' => array( 'attachment_id' => '999999999', 'confirm_cleanup' => '1', '_wpnonce' => wp_create_nonce( 'jio_recovery_cleanup_999999999' ) ),
		);
		try {
			wp_set_current_user( $admin_id );
			foreach ( $requests as $request ) {
				$_POST = $request;
				try {
					$handler->handle_attachment_cleanup();
					$this->fail( 'Invalid destructive request unexpectedly returned.' );
				} catch ( WPDieException $exception ) {
					$this->assertNotEmpty( $exception->getMessage() );
				}
				$this->assertFileExists( $derivative );
				$this->assertSame( $derivative_hash, hash_file( 'sha256', $derivative ) );
			}
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
			$_POST = array( 'attachment_id' => (string) $attachment_id, 'confirm_cleanup' => '1', '_wpnonce' => $valid_nonce );
			try {
				$handler->handle_attachment_cleanup();
				$this->fail( 'A non-administrator request unexpectedly returned.' );
			} catch ( WPDieException $exception ) {
				$this->assertNotEmpty( $exception->getMessage() );
			}
			$this->assertFileExists( $derivative );
		} finally {
			$_POST = $original_post;
			wp_set_current_user( 0 );
		}
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
		( new AdminPages( new CapabilityDetector() ) )->render_settings();
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
		( new AdminPages( new CapabilityDetector() ) )->render_settings();
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
			( new AdminPages( new CapabilityDetector() ) )->render_dashboard();
			$page = (string) ob_get_clean();
			$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $page );
			$this->assertStringNotContainsString( '<img src=x onerror=alert(1)>', $page );
			$this->assertFileDoesNotExist( $marker );

			putenv( 'PATH=' );
			$this->assertFalse( ( new CapabilityDetector() )->detect()['pngquant']['available'] );
			ob_start();
			( new AdminPages( new CapabilityDetector() ) )->render_dashboard();
			$missing_encoder_page = (string) ob_get_clean();
			$this->assertStringContainsString( 'Unavailable', $missing_encoder_page );
			$this->assertStringContainsString( 'Executable not found on PATH', $missing_encoder_page );
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

	public function test_individual_attachment_and_thumbnail_create_real_inactive_derivatives(): void {
		$this->set_output_format( 'webp' );
		[ $attachment_id, $source, $metadata ] = $this->create_png_attachment( 'single', true );
		$source_hash                           = hash_file( 'sha256', $source );
		$attached                              = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$integration                           = new MediaIntegration( $this->jobs, new QueueWorker( $this->jobs ) );
		$this->assertSame( 2, $integration->enqueue_attachment( $attachment_id ) );
		$this->assertSame( 0, $integration->enqueue_attachment( $attachment_id ), 'duplicate requests reuse the fingerprinted queue jobs' );
		$worker      = new QueueWorker( $this->jobs );
		$first_batch = $worker->run_batch( 1 );
		$this->assertSame( 1, $first_batch['completed'], 'one-item worker batch makes bounded progress' );
		$this->assertSame( 1, $this->jobs->pending_count(), 'one thumbnail job remains for the next worker batch' );
		$this->assertSame( 1, $this->jobs->stats()['completed'] );
		$second_batch = $worker->run_batch( 1 );
		$this->assertSame(
			1,
			$second_batch['completed'],
			wp_json_encode(
				array(
					'counts' => $second_batch,
					'jobs'   => $this->jobs->recent(),
				)
			)
		);
		$this->assertSame( 0, $this->jobs->pending_count() );
		$this->assertSame( $source_hash, hash_file( 'sha256', $source ) );
		$this->assertSame( $attached, get_post_meta( $attachment_id, '_wp_attached_file', true ) );
		$this->assertSame( $metadata, wp_get_attachment_metadata( $attachment_id ), 'WordPress thumbnail metadata remains unchanged' );
		$this->assertFileExists( preg_replace( '/\.png$/', '.jio.webp', $source ) );
		$this->assertFileExists( preg_replace( '/\.png$/', '.jio.webp', dirname( $source ) . '/single-thumbnail.png' ) );
		$records = array_filter( get_post_meta( $attachment_id ), static fn ( $key ): bool => str_starts_with( (string) $key, '_jio_derivative_' ), ARRAY_FILTER_USE_KEY );
		$this->assertCount( 2, $records );
		foreach ( array_keys( $records ) as $meta_key ) {
			$record = get_post_meta( $attachment_id, $meta_key, true );
			$this->assertIsArray( $record );
			$this->assertSame( 'image/webp', $record['mime_type'] );
			$this->assertStringNotContainsString( ABSPATH, $record['derivative_relative_path'] );
		}
		$this->assertSame( 2, $this->jobs->stats()['completed'] );
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		ob_start();
		( new AdminPages( new CapabilityDetector(), $this->jobs, new BulkScanner( $this->jobs, $worker ), $worker ) )->render_dashboard();
		$dashboard = (string) ob_get_clean();
		$this->assertStringContainsString( 'Attachments processed', $dashboard );
		$this->assertStringContainsString( 'Measured derivative byte delta', $dashboard );
		$this->assertStringNotContainsString( $source, $dashboard );
	}

	public function test_jpeg_attachment_creates_webp_derivative_and_preserves_original_and_metadata(): void {
		$this->set_output_format( 'webp' );
		[ $attachment_id, $source, $metadata ] = $this->create_jpeg_attachment( 'jpeg-single' );
		$source_hash                           = hash_file( 'sha256', $source );
		$attached                              = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$integration                           = new MediaIntegration( $this->jobs, new QueueWorker( $this->jobs ) );
		$this->assertSame( 1, $integration->enqueue_attachment( $attachment_id ) );
		$counts = ( new QueueWorker( $this->jobs ) )->run_batch( 1 );
		$this->assertSame( 1, $counts['completed'] );
		$this->assertSame( $source_hash, hash_file( 'sha256', $source ) );
		$this->assertSame( $attached, get_post_meta( $attachment_id, '_wp_attached_file', true ) );
		$this->assertSame( $metadata, wp_get_attachment_metadata( $attachment_id ) );
		$this->assertFileExists( preg_replace( '/\.jpg$/', '.jio.webp', $source ) );
		$this->assertSame( 0, $this->jobs->pending_count(), 'bounded worker batch leaves no second job for this JPEG' );
	}

	public function test_frontend_delivery_is_default_off_and_wraps_owned_webp_with_original_fallback(): void {
		$this->set_output_format( 'webp' );
		[ $attachment_id, $source, $metadata ] = $this->create_png_attachment( 'delivery', true );
		$original_html = wp_get_attachment_image( $attachment_id, 'full', false, array( 'alt' => 'safe image', 'class' => 'example', 'loading' => 'lazy', 'decoding' => 'async' ) );
		$this->assertFalse( Settings::sanitize( get_option( Plugin::OPTION_NAME ) )['frontend_delivery'] );
		$this->assertStringNotContainsString( '<picture>', $original_html, 'default-off leaves WordPress markup untouched' );
		$this->assertSame( 2, ( new MediaIntegration( $this->jobs, new QueueWorker( $this->jobs ) ) )->enqueue_attachment( $attachment_id ) );
		$this->assertSame( 2, ( new QueueWorker( $this->jobs ) )->run_batch( 2 )['completed'] );
		$source_hash = hash_file( 'sha256', $source );
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$this->assertSame( $metadata, wp_get_attachment_metadata( $attachment_id ) );
		$this->assertSame( $source_hash, hash_file( 'sha256', $source ) );
		$settings = Settings::sanitize( get_option( Plugin::OPTION_NAME ) );
		$settings['frontend_delivery'] = true;
		update_option( Plugin::OPTION_NAME, $settings );
		$delivery = new FrontendDelivery();
		$delivery_input = wp_get_attachment_image( $attachment_id, 'full', false, array( 'alt' => 'safe image', 'class' => 'example', 'loading' => 'lazy', 'decoding' => 'async' ) );
		$input_tag      = new \WP_HTML_Tag_Processor( $delivery_input );
		$this->assertTrue( $input_tag->next_tag( 'IMG' ) );
		$input_tag->set_attribute( 'sizes', '(max-width: 600px) 100vw, 600px' );
		$delivery_input = $input_tag->get_updated_html();
		$this->assertIsString( $delivery_input );
		$delivery->register();
		$picture = apply_filters( 'wp_get_attachment_image', $delivery_input, $attachment_id, 'full', false, array() );
		$this->assertStringContainsString( '<picture>', $picture );
		$this->assertStringContainsString( 'type="image/webp"', $picture );
		$this->assertStringContainsString( 'src="' . esc_url( wp_get_attachment_image_url( $attachment_id, 'full' ) ) . '"', $picture );
		$this->assertStringContainsString( 'alt="safe image"', $picture );
		$this->assertStringContainsString( 'loading="lazy"', $picture );
		$this->assertStringContainsString( 'decoding="async"', $picture );
		$this->assertStringContainsString( 'class="example"', $picture );
		$this->assertStringContainsString( 'sizes=', $picture );
		$content_picture = $delivery->content_image( $delivery_input, 'the_content', $attachment_id );
		$this->assertStringContainsString( '<picture>', $content_picture, 'core-recognized content image receives the same safe delivery wrapper' );
		$this->assertStringContainsString( 'src="' . esc_url( wp_get_attachment_image_url( $attachment_id, 'full' ) ) . '"', $content_picture );
		$this->assertSame( $attached, get_post_meta( $attachment_id, '_wp_attached_file', true ) );
		$records = ( new \Jocky\ImageOptimizer\Recovery\DerivativeRepository() )->for_attachment( $attachment_id );
		$this->assertNotEmpty( $records[0]['derivative_identity'], 'new records include lightweight current file identity' );
		global $wpdb;
		$wpdb->update( QueueSchema::derivatives_table(), array( 'derivative_identity' => 'stale-identity' ), array( 'id' => (int) $records[0]['id'] ), array( '%s' ), array( '%d' ) );
		$stale_delivery = new FrontendDelivery();
		$this->assertSame( $delivery_input, $stale_delivery->attachment_image( $delivery_input, $attachment_id, 'full', false, array() ), 'changed derivative identity safely falls back to original markup' );
		$settings['frontend_delivery'] = false;
		update_option( Plugin::OPTION_NAME, $settings );
		$disabled = new FrontendDelivery();
		$this->assertSame( $delivery_input, $disabled->attachment_image( $delivery_input, $attachment_id, 'full', false, array() ), 'disabling restores original markup for uncached requests' );
	}

	public function test_frontend_delivery_prefers_avif_then_webp_and_retains_srcset_and_fallback(): void {
		$this->set_output_format( 'both' );
		[ $attachment_id ] = $this->create_png_attachment( 'delivery-mixed', true );
		$this->assertSame( 4, ( new MediaIntegration( $this->jobs, new QueueWorker( $this->jobs ) ) )->enqueue_attachment( $attachment_id ) );
		$this->assertSame( 4, ( new QueueWorker( $this->jobs ) )->run_batch( 4 )['completed'] );
		$settings = Settings::sanitize( get_option( Plugin::OPTION_NAME ) );
		$settings['frontend_delivery'] = false;
		update_option( Plugin::OPTION_NAME, $settings );
		$original = wp_get_attachment_image( $attachment_id, 'full' );
		$settings['frontend_delivery'] = true;
		update_option( Plugin::OPTION_NAME, $settings );
		$delivery = new FrontendDelivery();
		$picture = $delivery->attachment_image( $original, $attachment_id, 'full', false, array() );
		$this->assertStringContainsString( '<source type="image/avif"', $picture );
		$this->assertStringContainsString( '<source type="image/webp"', $picture );
		$this->assertLessThan( strpos( $picture, '<source type="image/webp"' ), strpos( $picture, '<source type="image/avif"' ) );
		$this->assertStringContainsString( '<img', $picture );
		$this->assertStringContainsString( 'srcset=', $picture, 'original responsive candidates remain on the fallback img' );
		$this->assertStringContainsString( 'data-jio-delivery="1"', $picture );
		$this->assertSame( $picture, $delivery->attachment_image( $picture, $attachment_id, 'full', false, array() ), 'JIO-marked picture output is not wrapped a second time' );
		$settings['delivery_preference'] = 'webp_only';
		update_option( Plugin::OPTION_NAME, $settings );
		$webp_delivery = new FrontendDelivery();
		$webp_only = $webp_delivery->attachment_image( $original, $attachment_id, 'full', false, array() );
		$this->assertStringContainsString( 'type="image/webp"', $webp_only );
		$this->assertStringNotContainsString( 'type="image/avif"', $webp_only );
	}

	public function test_recovery_verifies_corruption_and_cleans_only_registered_derivatives(): void {
		global $wpdb;
		$this->set_output_format( 'webp' );
		[ $attachment_id, $source, $metadata ] = $this->create_png_attachment( 'recovery-safe', true );
		$source_hash                           = hash_file( 'sha256', $source );
		$thumbnail                             = dirname( $source ) . '/recovery-safe-thumbnail.png';
		$thumbnail_hash                        = hash_file( 'sha256', $thumbnail );
		$this->assertSame( 2, $this->jobs->enqueue_many( ( new AttachmentJobFactory() )->jobs_for_attachment( $attachment_id ) ) );
		$counts = ( new QueueWorker( $this->jobs ) )->run_batch( 2 );
		$this->assertSame( 2, $counts['completed'] );
		$this->jobs->stats();
		$this->assertSame( '', $wpdb->last_error, 'signed savings aggregation accepts output larger than source' );

		$service  = new RecoveryService();
		$verified = $service->verify_attachment( $attachment_id );
		$this->assertSame( 'valid', $verified['status'] );
		$this->assertCount( 2, $verified['records'] );
		$registry = QueueSchema::derivatives_table();
		$owned    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$registry} WHERE attachment_id = %d", $attachment_id ), ARRAY_A );
		$this->assertCount( 2, $owned );
		foreach ( $owned as $record ) {
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $record['source_sha256'] );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $record['derivative_sha256'] );
			$this->assertStringNotContainsString( ABSPATH, $record['source_relative_path'] );
			$this->assertStringNotContainsString( ABSPATH, $record['derivative_relative_path'] );
		}

		$plan = $service->preview_attachment( $attachment_id );
		$this->assertSame( 'ready', $plan['status'] );
		$this->assertCount( 2, $plan['eligible'] );
		$this->assertSame( 'confirmation_required', $service->cleanup_attachment( $attachment_id, false )['status'] );
		$this->assertFileExists( preg_replace( '/\.png$/', '.jio.webp', $source ) );

		$derivative  = preg_replace( '/\.png$/', '.jio.webp', $source );
		$valid_bytes = file_get_contents( $derivative );
		file_put_contents( $derivative, 'corrupt derivative data' );
		$corrupt = $service->verify_attachment( $attachment_id );
		$this->assertSame( 'derivative_corrupted', $corrupt['status'] );
		$this->assertCount( 1, $service->preview_attachment( $attachment_id )['conflicts'] );
		$this->assertFileExists( $source );
		$this->assertSame( $source_hash, hash_file( 'sha256', $source ) );
		file_put_contents( $derivative, $valid_bytes );

		$wpdb->query( $wpdb->prepare( "UPDATE {$registry} SET lifecycle_status = 'active', validation_status = 'valid' WHERE attachment_id = %d", $attachment_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE " . QueueSchema::jobs_table() . " SET status = 'pending' WHERE attachment_id = %d LIMIT 1", $attachment_id ) );
		$this->assertSame( 'queue_conflict', $service->preview_attachment( $attachment_id )['status'], 'pending optimization work blocks cleanup preview' );
		$wpdb->query( $wpdb->prepare( "UPDATE " . QueueSchema::jobs_table() . " SET status = 'running' WHERE attachment_id = %d LIMIT 1", $attachment_id ) );
		$this->assertSame( 'queue_conflict', $service->preview_attachment( $attachment_id )['status'], 'the queue uses running, not processing, for claimed work' );
		$this->assertSame( 'queue_conflict', $service->cleanup_attachment( $attachment_id, true )['status'] );
		$this->assertFileExists( $source );
		$this->assertFileExists( $derivative );
		$wpdb->query( $wpdb->prepare( "UPDATE " . QueueSchema::jobs_table() . " SET status = 'completed' WHERE attachment_id = %d", $attachment_id ) );

		$lock = new AttachmentOperationLock();
		$this->assertTrue( $lock->acquire( $attachment_id ) );
		$this->assertSame( 'operation_locked', $service->cleanup_attachment( $attachment_id, true )['status'] );
		$lock->release( $attachment_id );

		$first_cleanup = $service->cleanup_attachment( $attachment_id, true );
		$this->assertSame( 'partial', $first_cleanup['status'] );
		$this->assertSame( 1, $first_cleanup['cleaned'] );
		$this->assertTrue( $first_cleanup['has_more'] );
		$second_cleanup = $service->cleanup_attachment( $attachment_id, true, (int) $first_cleanup['next_after_id'] );
		$this->assertSame( 'complete', $second_cleanup['status'] );
		$this->assertSame( 1, $second_cleanup['cleaned'] );
		$repeated_cleanup = $service->cleanup_attachment( $attachment_id, true );
		$this->assertSame( 'complete', $repeated_cleanup['status'] );
		$this->assertSame( 0, $repeated_cleanup['cleaned'], 'repeating a completed cleanup is harmless' );
		$this->assertFileExists( $source );
		$this->assertFileExists( $thumbnail );
		$this->assertSame( $source_hash, hash_file( 'sha256', $source ) );
		$this->assertSame( $thumbnail_hash, hash_file( 'sha256', $thumbnail ) );
		$this->assertSame( $metadata, wp_get_attachment_metadata( $attachment_id ) );
		$this->assertSame( 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$registry} WHERE attachment_id = %d AND lifecycle_status = 'cleaned'", $attachment_id ) ) );
		$this->assertGreaterThanOrEqual( 4, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . QueueSchema::events_table() . ' WHERE attachment_id = %d', $attachment_id ) ) );
		$this->assertNotEmpty( ( new RecoveryService() )->repository()->events( 1, 25 ) );
		$this->assertFileDoesNotExist( $derivative );
		$this->assertSame( '', $wpdb->last_error );
	}

	public function test_recovery_rejects_corrupt_or_missing_source_without_deleting_its_derivative(): void {
		$this->set_output_format( 'webp' );
		[ $attachment_id, $source ] = $this->create_png_attachment( 'source-integrity', false );
		$source_bytes                = file_get_contents( $source );
		$this->assertSame( 1, $this->jobs->enqueue_many( ( new AttachmentJobFactory() )->jobs_for_attachment( $attachment_id ) ) );
		$this->assertSame( 1, ( new QueueWorker( $this->jobs ) )->run_batch( 1 )['completed'] );
		$derivative = preg_replace( '/\.png$/', '.jio.webp', $source );
		$this->assertFileExists( $derivative );
		$service = new RecoveryService();

		file_put_contents( $source, 'corrupted source data' );
		$this->assertSame( 'source_changed', $service->verify_attachment( $attachment_id )['status'] );
		$this->assertSame( 'conflict', $service->cleanup_attachment( $attachment_id, true )['status'] );
		$this->assertFileExists( $derivative, 'source corruption must block cleanup without deleting its derivative' );
		file_put_contents( $source, $source_bytes );

		unlink( $source );
		$this->assertSame( 'source_missing', $service->verify_attachment( $attachment_id )['status'] );
		$this->assertSame( 'conflict', $service->cleanup_attachment( $attachment_id, true )['status'] );
		$this->assertFileExists( $derivative, 'missing source must block cleanup' );
		file_put_contents( $source, $source_bytes );
		$this->assertNotFalse( $source_bytes );
	}

	public function test_cleanup_rechecks_current_wordpress_metadata_before_unlink(): void {
		$this->set_output_format( 'webp' );
		[ $attachment_id, $source ] = $this->create_png_attachment( 'recovery-metadata-race', false );
		$this->assertSame( 1, $this->jobs->enqueue_many( ( new AttachmentJobFactory() )->jobs_for_attachment( $attachment_id ) ) );
		$this->assertSame( 1, ( new QueueWorker( $this->jobs ) )->run_batch( 1 )['completed'] );
		$derivative     = preg_replace( '/\\.png$/', '.jio.webp', $source );
		$source_hash    = hash_file( 'sha256', $source );
		$original_meta  = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$registry       = QueueSchema::derivatives_table();
		$changed_at_edge = false;
		$mutate_metadata = static function ( string $query ) use ( $registry, $attachment_id, &$changed_at_edge ): string {
			if ( ! $changed_at_edge && str_contains( $query, "UPDATE {$registry} SET lifecycle_status = 'removing'" ) ) {
				$changed_at_edge = true;
				update_post_meta( $attachment_id, '_wp_attached_file', 'changed-by-concurrent-wordpress-operation.png' );
			}
			return $query;
		};
		add_filter( 'query', $mutate_metadata, 999 );
		$result = ( new RecoveryService() )->cleanup_attachment( $attachment_id, true );
		remove_filter( 'query', $mutate_metadata, 999 );
		update_post_meta( $attachment_id, '_wp_attached_file', $original_meta );
		$this->assertTrue( $changed_at_edge, 'the test changed core metadata between preview and the deletion boundary' );
		$this->assertSame( 'conflict', $result['status'] );
		$this->assertSame( $source_hash, hash_file( 'sha256', $source ) );
		$this->assertFileExists( $derivative, 'a metadata change at the destructive boundary prevents unlink' );
		$changed_file_at_edge = false;
		$mutate_derivative    = static function ( string $query ) use ( $registry, $derivative, &$changed_file_at_edge ): string {
			if ( ! $changed_file_at_edge && str_contains( $query, "UPDATE {$registry} SET lifecycle_status = 'removing'" ) ) {
				$changed_file_at_edge = true;
				file_put_contents( $derivative, 'replaced after integrity preview' );
			}
			return $query;
		};
		add_filter( 'query', $mutate_derivative, 999 );
		$changed_file_result = ( new RecoveryService() )->cleanup_attachment( $attachment_id, true );
		remove_filter( 'query', $mutate_derivative, 999 );
		$this->assertTrue( $changed_file_at_edge, 'the test replaced the derivative between preview and unlink' );
		$this->assertSame( 'conflict', $changed_file_result['status'] );
		$this->assertFileExists( $derivative, 'a changed derivative is retained for operator review' );
		$this->assertSame( $source_hash, hash_file( 'sha256', $source ) );
	}

	public function test_cleanup_database_failures_keep_the_operation_tracked_and_never_report_false_success(): void {
		global $wpdb;
		$this->set_output_format( 'webp' );
		[ $attachment_id, $source ] = $this->create_png_attachment( 'recovery-db-failure', false );
		$source_hash                = hash_file( 'sha256', $source );
		$this->assertSame( 1, $this->jobs->enqueue_many( ( new AttachmentJobFactory() )->jobs_for_attachment( $attachment_id ) ) );
		$this->assertSame( 1, ( new QueueWorker( $this->jobs ) )->run_batch( 1 )['completed'] );
		$derivative = preg_replace( '/\\.png$/', '.jio.webp', $source );
		$this->assertFileExists( $derivative );
		$registry = QueueSchema::derivatives_table();
		$row      = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$registry} WHERE attachment_id = %d LIMIT 1", $attachment_id ), ARRAY_A );
		$this->assertNotEmpty( $row );

		// A failed registry insert is surfaced as failure and leaves prior ownership intact.
		$fail_insert = static function ( string $query ) use ( $registry ): string {
			if ( str_starts_with( $query, "INSERT INTO {$registry} " ) ) {
				return "UPDATE {$registry} SET missing_column = 1";
			}
			return $query;
		};
		add_filter( 'query', $fail_insert, 999 );
		$this->assertFalse( ( new \Jocky\ImageOptimizer\Recovery\DerivativeRepository() )->record( $row ) );
		remove_filter( 'query', $fail_insert, 999 );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$registry} WHERE attachment_id = %d", $attachment_id ) ) );
		// If the pre-unlink state transition fails, cleanup must not touch the file.
		$fail_removing = static function ( string $query ) use ( $registry ): string {
			if ( str_contains( $query, "UPDATE {$registry} SET lifecycle_status = 'removing'" ) ) {
				return "UPDATE {$registry} SET missing_column = 1";
			}
			return $query;
		};
		add_filter( 'query', $fail_removing, 999 );
		$this->assertSame( 'conflict', ( new RecoveryService() )->cleanup_attachment( $attachment_id, true )['status'] );
		remove_filter( 'query', $fail_removing, 999 );
		$this->assertFileExists( $derivative );
		$this->assertSame( $source_hash, hash_file( 'sha256', $source ) );

		// A post-unlink registry failure is not reported as success; the row becomes a visible conflict.
		$fail_cleaned = static function ( string $query ) use ( $registry ): string {
			if ( str_contains( $query, "SET validation_status = 'cleaned', lifecycle_status = 'cleaned'" ) ) {
				return "UPDATE {$registry} SET missing_column = 1";
			}
			return $query;
		};
		add_filter( 'query', $fail_cleaned, 999 );
		$result = ( new RecoveryService() )->cleanup_attachment( $attachment_id, true );
		remove_filter( 'query', $fail_cleaned, 999 );
		$this->assertSame( 'conflict', $result['status'] );
		$this->assertSame( 0, $result['cleaned'] );
		$this->assertFileDoesNotExist( $derivative );
		$this->assertSame( $source_hash, hash_file( 'sha256', $source ) );
		$this->assertSame( 'conflict', $wpdb->get_var( $wpdb->prepare( "SELECT lifecycle_status FROM {$registry} WHERE attachment_id = %d LIMIT 1", $attachment_id ) ) );

		// An audit write failure after successful registry/file cleanup is also surfaced as a conflict.
		[ $audit_attachment_id, $audit_source ] = $this->create_png_attachment( 'recovery-audit-failure', false );
		$audit_source_hash                     = hash_file( 'sha256', $audit_source );
		$this->assertSame( 1, $this->jobs->enqueue_many( ( new AttachmentJobFactory() )->jobs_for_attachment( $audit_attachment_id ) ) );
		$this->assertSame( 1, ( new QueueWorker( $this->jobs ) )->run_batch( 1 )['completed'] );
		$audit_derivative = preg_replace( '/\\.png$/', '.jio.webp', $audit_source );
		$events            = QueueSchema::events_table();
		$fail_audit        = static function ( string $query ) use ( $events ): string {
			if ( str_starts_with( $query, "INSERT INTO `{$events}` " ) ) {
				return "UPDATE {$events} SET missing_column = 1";
			}
			return $query;
		};
		add_filter( 'query', $fail_audit, 999 );
		$audit_result = ( new RecoveryService() )->cleanup_attachment( $audit_attachment_id, true );
		remove_filter( 'query', $fail_audit, 999 );
		$this->assertSame( 'conflict', $audit_result['status'] );
		$this->assertSame( 0, $audit_result['cleaned'] );
		$this->assertFileDoesNotExist( $audit_derivative );
		$this->assertSame( $audit_source_hash, hash_file( 'sha256', $audit_source ) );
		$this->assertSame( 'cleaned', $wpdb->get_var( $wpdb->prepare( "SELECT lifecycle_status FROM {$registry} WHERE attachment_id = %d LIMIT 1", $audit_attachment_id ) ) );
	}

	public function test_persistent_bulk_cleanup_batch_processes_bounded_pages_and_preserves_source(): void {
		global $wpdb;
		$this->set_output_format( 'webp' );
		[ $attachment_id, $source, $metadata ] = $this->create_png_attachment( 'bulk-recovery', true );
		$source_hash                           = hash_file( 'sha256', $source );
		$this->assertSame( 2, $this->jobs->enqueue_many( ( new AttachmentJobFactory() )->jobs_for_attachment( $attachment_id ) ) );
		$this->assertSame( 2, ( new QueueWorker( $this->jobs ) )->run_batch( 2 )['completed'] );

		$service  = new RecoveryService();
		$repo     = $service->repository();
		$batch_id = $repo->create_cleanup_batch( $repo->active_summary()['records'], get_current_user_id() );
		$this->assertGreaterThan( 0, $batch_id );
		$first = $service->cleanup_batch( $batch_id, 1 );
		$this->assertSame( 1, $first['processed'] );
		$this->assertSame( 'running', $first['status'] );
		$second = $service->cleanup_batch( $batch_id, 1 );
		$this->assertSame( 1, $second['processed'] );
		$this->assertSame( 'running', $second['status'] );
		$final = $service->cleanup_batch( $batch_id, 1 );
		$this->assertSame( 'complete', $final['status'] );

		$batch = $repo->cleanup_batch( $batch_id );
		$this->assertSame( 'complete', $batch['status'] );
		$this->assertSame( 2, (int) $batch['cleaned_count'] );
		$this->assertSame( $metadata, wp_get_attachment_metadata( $attachment_id ) );
		$this->assertSame( $source_hash, hash_file( 'sha256', $source ) );
		$this->assertSame( array(), glob( dirname( $source ) . DIRECTORY_SEPARATOR . '*.jio.webp' ) ?: array() );
		$this->assertSame( '', $wpdb->last_error );
	}

	public function test_automatic_queueing_is_off_by_default_and_deduplicates_after_metadata_generation(): void {
		[ $attachment_id, , $metadata ] = $this->create_png_attachment( 'automatic', true );
		$this->assertSame( $metadata, apply_filters( 'wp_generate_attachment_metadata', $metadata, $attachment_id, 'create' ) );
		$this->assertSame( 0, $this->jobs->pending_count() );
		$this->set_output_format( 'webp', true );
		$this->assertCount( 2, ( new AttachmentJobFactory() )->jobs_for_attachment( $attachment_id ), 'factory discovers the original and thumbnail for the enabled format' );
		$this->assertNotFalse( has_filter( 'wp_generate_attachment_metadata' ), 'plugin boot registers automatic upload queueing' );
		$this->assertTrue( Settings::sanitize( get_option( Plugin::OPTION_NAME ) )['auto_optimize'] );
		$this->assertSame( $metadata, apply_filters( 'wp_generate_attachment_metadata', $metadata, $attachment_id, 'create' ) );
		$this->assertSame(
			2,
			$this->jobs->pending_count(),
			wp_json_encode(
				array(
					'filter_registered' => has_filter( 'wp_generate_attachment_metadata' ),
					'settings'          => Settings::sanitize( get_option( Plugin::OPTION_NAME ) ),
					'jobs'              => $this->jobs->recent(),
				)
			)
		);
		apply_filters( 'wp_generate_attachment_metadata', $metadata, $attachment_id, 'update' );
		$this->assertSame( 2, $this->jobs->pending_count() );
	}

	public function test_database_claim_retry_stale_recovery_pause_and_cancellation(): void {
		$this->set_output_format( 'webp' );
		[ $attachment_id ] = $this->create_png_attachment( 'queue', false );
		$factory           = new AttachmentJobFactory();
		$this->assertSame( 1, $this->jobs->enqueue_many( $factory->jobs_for_attachment( $attachment_id ) ) );
		$claim_token = bin2hex( random_bytes( 16 ) );
		$job         = $this->jobs->claim_next( $claim_token );
		$this->assertIsArray( $job );
		$this->assertFalse( $this->jobs->finish( (int) $job['id'], 'wrong-owner-token', 'completed', 1, 1 ) );
		$this->assertTrue( $this->jobs->fail( (int) $job['id'], $claim_token, 'test_failure' ) );
		global $wpdb;
		$table = QueueSchema::jobs_table();
		for ( $attempt = 2; $attempt <= 3; ++$attempt ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET available_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = %d", (int) $job['id'] ) );
			$retry_token = bin2hex( random_bytes( 16 ) );
			$claim       = $this->jobs->claim_next( $retry_token );
			$this->assertIsArray( $claim );
			$this->assertSame( $attempt, (int) $claim['attempts'] );
			$this->assertTrue( $this->jobs->fail( (int) $job['id'], $retry_token, 'test_failure' ) );
		}
		$this->assertSame( 1, $this->jobs->stats()['failed'] );
		$this->assertSame( 1, $this->jobs->retry_failed() );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET available_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = %d", (int) $job['id'] ) );
		$stale_token = bin2hex( random_bytes( 16 ) );
		$stale       = $this->jobs->claim_next( $stale_token );
		$this->assertIsArray( $stale );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET locked_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 601 SECOND) WHERE id = %d", (int) $job['id'] ) );
		$secondary = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$secondary->prefix = $wpdb->prefix;
		$lock_name = 'jio_' . substr( hash( 'sha256', $wpdb->prefix . ':' . $attachment_id ), 0, 48 );
		$this->assertSame( '1', (string) $secondary->get_var( $secondary->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ) );
		$this->jobs->recover_stale();
		$this->assertSame( 'running', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", (int) $job['id'] ) ), 'an active attachment operation prevents stale ownership transfer' );
		$secondary->get_var( $secondary->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		$secondary->close();
		$this->jobs->recover_stale();
		$this->assertSame( 'pending', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", (int) $job['id'] ) ) );
		QueueState::pause();
		$this->assertSame( 0, ( new QueueWorker( $this->jobs ) )->run_batch( 1 )['claimed'] );
		QueueState::resume();
		$this->assertSame( 1, $this->jobs->cancel_pending() );
		$this->assertSame( 0, $this->jobs->pending_count() );
	}

	public function test_concurrent_claims_are_distinct_and_require_their_own_worker_tokens(): void {
		$this->set_output_format( 'webp' );
		[ $first_id ]  = $this->create_png_attachment( 'claim-one', false );
		[ $second_id ] = $this->create_png_attachment( 'claim-two', false );
		$factory       = new AttachmentJobFactory();
		$this->jobs->enqueue_many( $factory->jobs_for_attachment( $first_id ) );
		$this->jobs->enqueue_many( $factory->jobs_for_attachment( $second_id ) );
		$first_token  = bin2hex( random_bytes( 16 ) );
		$second_token = bin2hex( random_bytes( 16 ) );
		$first        = $this->jobs->claim_next( $first_token );
		$second       = $this->jobs->claim_next( $second_token );
		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertNotSame( $first['id'], $second['id'] );
		$this->assertFalse( $this->jobs->finish( (int) $first['id'], $second_token, 'completed', 1, 1 ) );
		$this->assertTrue( $this->jobs->finish( (int) $first['id'], $first_token, 'completed', 1, 1 ) );
		$this->assertTrue( $this->jobs->finish( (int) $second['id'], $second_token, 'completed', 1, 1 ) );
	}

	public function test_attachment_operation_lock_is_exclusive_across_database_connections_and_releases(): void {
		global $wpdb;
		[ $attachment_id ] = $this->create_png_attachment( 'operation-lock', false );
		$primary       = $wpdb;
		$secondary     = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$secondary->prefix   = $primary->prefix;
		$lock_name           = 'jio_' . substr( hash( 'sha256', $primary->prefix . ':' . $attachment_id ), 0, 48 );
		$this->assertSame( '1', (string) $secondary->get_var( $secondary->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ) );
		$lock = new AttachmentOperationLock();
		$this->assertFalse( $lock->acquire( $attachment_id ), 'another connection holding the same lock blocks plugin cleanup/optimization' );
		$secondary->get_var( $secondary->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		$this->assertTrue( $lock->acquire( $attachment_id ) );
		$lock->release( $attachment_id );
		$this->assertTrue( $lock->acquire( $attachment_id ), 'released lock can be reacquired' );
		$lock->release( $attachment_id );
		$this->assertFalse( $lock->acquire( 0 ), 'invalid attachment IDs never acquire an operation lock' );
	}

	public function test_bulk_scan_uses_bounded_keyset_pages_and_persists_progress(): void {
		$this->set_output_format( 'webp' );
		for ( $index = 0; $index < BulkScanner::BATCH + 1; ++$index ) {
			$this->create_png_attachment( 'bulk-' . $index, false );
		}
		$worker  = new QueueWorker( $this->jobs );
		$scanner = new BulkScanner( $this->jobs, $worker );
		$this->assertSame( BulkScanner::BATCH + 1, $scanner->count_attachments(), 'scan query sees each disposable test attachment' );
		$this->assertTrue( $scanner->start() );
		$this->assertSame( BulkScanner::BATCH, $scanner->scan_batch() );
		$this->assertSame( BulkScanner::BATCH, $this->jobs->pending_count() );
		$this->assertSame( 1, $scanner->scan_batch() );
		$this->assertSame( 0, $scanner->scan_batch() );
		$state = $scanner->state();
		$this->assertSame( 'complete', $state['status'] );
		$this->assertSame( BulkScanner::BATCH + 1, $state['scanned'] );
		$this->assertSame( BulkScanner::BATCH + 1, $state['jobs_added'] );
	}

	public function test_unsupported_and_missing_media_are_safely_skipped(): void {
		$this->set_output_format( 'webp' );
		$pdf_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Unsupported document',
			)
		);
		$this->assertIsInt( $pdf_id );
		$this->assertSame( array(), ( new AttachmentTargetProvider() )->targets( $pdf_id ) );
		$this->assertSame( array(), ( new AttachmentTargetProvider() )->targets( 999999999 ) );
		[ $attachment_id, $source ] = $this->create_png_attachment( 'missing', false );
		$this->assertSame( 1, $this->jobs->enqueue_many( ( new AttachmentJobFactory() )->jobs_for_attachment( $attachment_id ) ) );
		unlink( $source );
		[ $deleted_id ] = $this->create_png_attachment( 'deleted', false );
		$this->assertSame( 1, $this->jobs->enqueue_many( ( new AttachmentJobFactory() )->jobs_for_attachment( $deleted_id ) ) );
		wp_delete_attachment( $deleted_id, true );
		$counts = ( new QueueWorker( $this->jobs ) )->run_batch( 2 );
		$this->assertSame( 2, $counts['skipped'] );
		$this->assertSame( 0, $this->jobs->stats()['failed'] );
		$this->test_attachment_ids[] = $pdf_id;
	}

	private function boot_admin_as( string $role ): void {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		set_current_screen( 'dashboard' );
		( new Plugin() )->boot();
		do_action( 'admin_init' );
		do_action( 'admin_menu' );
	}

	/** Returns the MySQL session commit counter to detect nested transaction commits.
	 *
	 * @return int MySQL COMMIT statement count for this connection.
	 */
	private function database_commit_count(): int {
		global $wpdb;
		$status = $wpdb->get_row( "SHOW SESSION STATUS LIKE 'Com_commit'", ARRAY_N );
		return is_array( $status ) && isset( $status[1] ) ? (int) $status[1] : -1;
	}

	/** Creates an isolated real attachment fixture with optional registered thumbnail metadata.
	 *
	 * @param string $name       Safe fixture name.
	 * @param bool   $with_thumb Whether to create a thumbnail variant.
	 * @return array{int,string,array<string,mixed>} Attachment ID, source path, and persisted metadata.
	 */
	private function create_png_attachment( string $name, bool $with_thumb ): array {
		$uploads   = wp_upload_dir();
		$directory = trailingslashit( $uploads['basedir'] ) . 'jio-m4-' . sanitize_file_name( $name ) . '-' . bin2hex( random_bytes( 4 ) );
		wp_mkdir_p( $directory );
		$this->test_media_directories[] = $directory;
		$source                         = trailingslashit( $directory ) . sanitize_file_name( $name ) . '.png';
		$data                           = self::png_fixture_data();
		file_put_contents( $source, $data );
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'JIO M4 ' . $name,
				'post_status'    => 'inherit',
			),
			$source,
			0,
			true
		);
		$this->assertIsInt( $attachment_id );
		update_attached_file( $attachment_id, $source );
		$this->test_attachment_ids[] = $attachment_id;
		$metadata                    = array(
			'file'       => ltrim( str_replace( wp_normalize_path( $uploads['basedir'] ) . '/', '', wp_normalize_path( $source ) ), '/' ),
			'width'      => 1,
			'height'     => 1,
			'sizes'      => array(),
			'image_meta' => array(),
		);
		if ( $with_thumb ) {
			$thumbnail = trailingslashit( $directory ) . sanitize_file_name( $name ) . '-thumbnail.png';
			copy( $source, $thumbnail );
			$metadata['sizes']['medium'] = array(
				'file'      => basename( $thumbnail ),
				'width'     => 1,
				'height'    => 1,
				'mime-type' => 'image/png',
			);
		}
		wp_update_attachment_metadata( $attachment_id, $metadata );
		$this->assertNotEmpty(
			( new AttachmentTargetProvider() )->targets( $attachment_id ),
			wp_json_encode(
				array(
					'post_type'       => get_post_type( $attachment_id ),
					'post_mime_type'  => get_post_mime_type( $attachment_id ),
					'uploads_root'    => wp_upload_dir()['basedir'],
					'attached_file'   => get_attached_file( $attachment_id ),
					'unfiltered_file' => get_attached_file( $attachment_id, true ),
					'file_mime'       => wp_get_image_mime( $source ),
					'file_exists'     => is_file( $source ),
					'file_readable'   => is_readable( $source ),
				)
			)
		);
		return array( $attachment_id, $source, wp_get_attachment_metadata( $attachment_id ) );
	}

	/** Creates a deterministic JPEG attachment fixture under the disposable uploads root.
	 *
	 * @param string $name Safe fixture name.
	 * @return array{int,string,array<string,mixed>} Attachment ID, source path, and metadata.
	 */
	private function create_jpeg_attachment( string $name ): array {
		$uploads   = wp_upload_dir();
		$directory = trailingslashit( $uploads['basedir'] ) . 'jio-m4-' . sanitize_file_name( $name ) . '-' . bin2hex( random_bytes( 4 ) );
		wp_mkdir_p( $directory );
		$this->test_media_directories[] = $directory;
		$source                         = trailingslashit( $directory ) . sanitize_file_name( $name ) . '.jpg';
		$image                          = imagecreatetruecolor( 192, 128 );
		$this->assertNotFalse( $image );
		$colors = array(
			imagecolorallocate( $image, 20, 80, 180 ),
			imagecolorallocate( $image, 220, 40, 60 ),
			imagecolorallocate( $image, 20, 170, 90 ),
			imagecolorallocate( $image, 240, 190, 30 ),
		);
		imagefill( $image, 0, 0, $colors[0] );
		for ( $index = 0; $index < 4; ++$index ) {
			imagefilledrectangle( $image, $index * 48, 0, ( $index + 1 ) * 48 - 1, 127, $colors[ $index ] );
		}
		$this->assertTrue( imagejpeg( $image, $source, 88 ) );
		imagedestroy( $image );
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'JIO M4 ' . $name,
				'post_status'    => 'inherit',
			),
			$source,
			0,
			true
		);
		$this->assertIsInt( $attachment_id );
		update_attached_file( $attachment_id, $source );
		$this->test_attachment_ids[] = $attachment_id;
		$metadata                    = array(
			'file'       => ltrim( str_replace( wp_normalize_path( $uploads['basedir'] ) . '/', '', wp_normalize_path( $source ) ), '/' ),
			'width'      => 192,
			'height'     => 128,
			'sizes'      => array(),
			'image_meta' => array(),
		);
		wp_update_attachment_metadata( $attachment_id, $metadata );
		return array( $attachment_id, $source, wp_get_attachment_metadata( $attachment_id ) );
	}

	/** Builds a deterministic transparent PNG with valid chunk CRCs.
	 *
	 * @return string PNG fixture bytes.
	 */
	private static function png_fixture_data(): string {
		$chunk  = static function ( string $type, string $data ): string {
			return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'H*', hash( 'crc32b', $type . $data ) );
		};
		$header = pack( 'NNCCCCC', 1, 1, 8, 6, 0, 0, 0 );
		$image  = gzcompress( "\0\xff\0\0\0" );
		return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', $header ) . $chunk( 'IDAT', $image ) . $chunk( 'IEND', '' );
	}

	/** Selects a sanitized derivative target for integration tests.
	 *
	 * @param string $format     Allowed settings format.
	 * @param bool   $auto_queue Whether automatic upload queueing is enabled.
	 */
	private function set_output_format( string $format, bool $auto_queue = false ): void {
		update_option(
			Plugin::OPTION_NAME,
			Settings::sanitize(
				array(
					'output_format'   => $format,
					'image_quality'   => 80,
					'minimum_quality' => 60,
					'auto_optimize'   => $auto_queue,
					'concurrency'     => 1,
				)
			)
		);
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
