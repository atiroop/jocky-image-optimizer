<?php
/**
 * Bounded WP-CLI commands for queue workers.
 *
 * @package Jocky\ImageOptimizer\Cli
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Cli;

use Jocky\ImageOptimizer\Queue\JobRepository;
use Jocky\ImageOptimizer\Queue\QueueMaintenanceWindow;
use Jocky\ImageOptimizer\Queue\QueueState;
use Jocky\ImageOptimizer\Queue\QueueWorker;

/** Exposes finite worker batches without creating a daemon or system cron. */
final class WorkerCommand extends \WP_CLI_Command {

	/** Creates the command services.
	 *
	 * @param QueueWorker   $worker Queue worker.
	 * @param JobRepository $jobs   Queue data source.
	 */
	public function __construct( private QueueWorker $worker, private JobRepository $jobs ) {}

	/** Runs one finite queue batch.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Maximum jobs to attempt, from 1 to 10. Defaults to 1.
	 *
	 * [--attachment-ids=<ids>]
	 * : Optional comma-separated positive attachment IDs. Creates a short expiring maintenance window that blocks global workers.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jio worker run --batch-size=5
	 *     wp jio worker run --attachment-ids=76,80 --batch-size=8
	 *
	 * Scoped runs create a 15-minute expiring maintenance lease. The lease
	 * blocks global cron work, is renewed during the bounded run, refuses to
	 * start while a worker is active, and restores normal scheduling on exit.
	 * Queue pause remains authoritative and cannot be bypassed.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		$batch_value = $assoc_args['batch-size'] ?? '1';
		if ( ! is_string( $batch_value ) || 1 !== preg_match( '/^[1-9][0-9]*$/', $batch_value ) ) {
			\WP_CLI::error( 'Batch size must be an integer between 1 and 10.' );
		}
		$batch_size = filter_var( $batch_value, FILTER_VALIDATE_INT );
		if ( false === $batch_size || $batch_size < 1 || $batch_size > 10 ) {
			\WP_CLI::error( 'Batch size must be between 1 and 10.' );
		}
		if ( QueueState::is_paused() ) {
			\WP_CLI::warning( 'The optimization queue is paused.' );
			return;
		}
		$attachment_ids = null;
		if ( isset( $assoc_args['attachment-ids'] ) ) {
			$value = $assoc_args['attachment-ids'];
			if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/', $value ) ) {
				\WP_CLI::error( 'Attachment IDs must be a comma-separated list of positive integers.' );
			}
			$parts = explode( ',', $value );
			if ( count( $parts ) > 100 || count( array_unique( $parts ) ) !== count( $parts ) || count( array_filter( $parts, static fn ( string $id ): bool => false === filter_var( $id, FILTER_VALIDATE_INT ) ) ) > 0 ) {
				\WP_CLI::error( 'Provide up to 100 unique valid attachment IDs.' );
			}
			$attachment_ids = array_map( 'intval', $parts );
		}
		if ( null === $attachment_ids ) {
			if ( QueueMaintenanceWindow::is_active() ) {
				\WP_CLI::warning( 'A scoped maintenance window is active; the global worker did not run.' );
				return;
			}
			$counts = $this->worker->run_batch( $batch_size );
			$this->worker->schedule_next();
		} else {
			$maintenance_token = QueueMaintenanceWindow::begin( $attachment_ids, $this->jobs );
			if ( is_wp_error( $maintenance_token ) ) {
				\WP_CLI::error( $maintenance_token->get_error_message() );
			}
			\WP_CLI::line( 'Scoped maintenance window active; global workers are held until this bounded run exits.' );
			try {
				$counts = $this->worker->run_batch( $batch_size, $attachment_ids, $maintenance_token );
			} finally {
				QueueMaintenanceWindow::release( $maintenance_token );
				$this->worker->schedule_next();
			}
			\WP_CLI::line( wp_json_encode( array( 'jobs' => $counts['jobs'] ), JSON_UNESCAPED_SLASHES ) );
		}
		if ( $counts['failed'] > 0 ) {
			\WP_CLI::error( sprintf( 'Stopped after %d failed job(s). Review sanitized job results above.', $counts['failed'] ) );
		}
		\WP_CLI::success( sprintf( 'Claimed %1$d; completed %2$d; failed %3$d; skipped %4$d.', $counts['claimed'], $counts['completed'], $counts['failed'], $counts['skipped'] ) );
	}

	/** Prints database-backed queue counts.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jio worker status
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$stats = $this->jobs->stats();
		\WP_CLI::line(
			wp_json_encode(
				array(
					'paused'             => QueueState::is_paused(),
					'scoped_maintenance' => QueueMaintenanceWindow::is_active(),
					'queue'              => $stats,
				),
				JSON_PRETTY_PRINT
			)
		);
	}
}
