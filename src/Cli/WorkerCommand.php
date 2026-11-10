<?php
/**
 * Bounded WP-CLI commands for queue workers.
 *
 * @package Jocky\ImageOptimizer\Cli
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Cli;

use Jocky\ImageOptimizer\Queue\JobRepository;
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
	 * ## EXAMPLES
	 *
	 *     wp jio worker run --batch-size=5
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		$batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 1;
		if ( $batch_size < 1 || $batch_size > 10 ) {
			\WP_CLI::error( 'Batch size must be between 1 and 10.' );
		}
		if ( QueueState::is_paused() ) {
			\WP_CLI::warning( 'The optimization queue is paused.' );
			return;
		}
		$counts = $this->worker->run_batch( $batch_size );
		$this->worker->schedule_next();
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
					'paused' => QueueState::is_paused(),
					'queue'  => $stats,
				),
				JSON_PRETTY_PRINT
			)
		);
	}
}
