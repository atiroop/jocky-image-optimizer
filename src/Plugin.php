<?php
/**
 * Plugin lifecycle and application bootstrap.
 *
 * @package Jocky\ImageOptimizer
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer;

use Jocky\ImageOptimizer\Admin\AdminPages;
use Jocky\ImageOptimizer\Media\MediaIntegration;
use Jocky\ImageOptimizer\Queue\BulkScanner;
use Jocky\ImageOptimizer\Queue\JobRepository;
use Jocky\ImageOptimizer\Queue\QueueSchema;
use Jocky\ImageOptimizer\Queue\QueueWorker;
use Jocky\ImageOptimizer\Recovery\RecoveryService;
use Jocky\ImageOptimizer\Recovery\RecoveryWorker;

/** Coordinates the plugin's lifecycle and admin services. */
final class Plugin {

	public const OPTION_NAME = 'jio_settings';

	/** Boots admin integrations when WordPress is in the dashboard. */
	public function boot(): void {
		QueueSchema::maybe_install();
		$jobs            = new JobRepository();
		$worker          = new QueueWorker( $jobs );
		$bulk            = new BulkScanner( $jobs, $worker );
		$recovery        = new RecoveryService();
		$recovery_worker = new RecoveryWorker( $recovery );
		$worker->register();
		$bulk->register();
		$recovery_worker->register();
		( new MediaIntegration( $jobs, $worker ) )->register();
		if ( is_admin() ) {
			( new AdminPages( new CapabilityDetector(), $jobs, $bulk, $worker, $recovery, $recovery_worker ) )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'jio worker', new \Jocky\ImageOptimizer\Cli\WorkerCommand( $worker, $jobs ) );
			\WP_CLI::add_command( 'jio recovery', new \Jocky\ImageOptimizer\Cli\RecoveryCommand( $recovery, $recovery_worker ) );
		}
	}

	/** Adds default settings without replacing existing values. */
	public static function activate(): void {
		if ( isset( $GLOBALS['wpdb'] ) ) {
			QueueSchema::maybe_install();
		}
		if ( get_option( self::OPTION_NAME, false ) === false ) {
			add_option( self::OPTION_NAME, Settings::defaults(), '', false );
		}
	}

	/** Deactivates the plugin without deleting settings or media. */
	public static function deactivate(): void {
		// Keep scheduled queue work and persistent image/job data intact.
	}
}
