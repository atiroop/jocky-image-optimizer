<?php
/**
 * Bounded queue worker with slot leases and retry recovery.
 *
 * @package Jocky\ImageOptimizer\Queue
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Queue;

use Jocky\ImageOptimizer\Media\AttachmentProcessor;
use Jocky\ImageOptimizer\Plugin;
use Jocky\ImageOptimizer\Settings;

/** Claims and runs no more than a caller-selected bounded number of jobs. */
final class QueueWorker {

	public const CRON_HOOK = 'jio_process_queue';

	/** Processor for one attachment variant at a time.
	 *
	 * @var AttachmentProcessor
	 */
	private AttachmentProcessor $processor;

	/** Creates the runner dependencies.
	 *
	 * @param JobRepository $jobs Persistent job storage.
	 */
	public function __construct( private JobRepository $jobs ) {
		$this->processor = new AttachmentProcessor();
	}

	/** Registers the scheduled worker callback. */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled' ) );
	}

	/** Processes one cron tick, keeping upload/front-end requests out of encoding work. */
	public function run_scheduled(): void {
		if ( QueueState::is_paused() ) {
			return;
		}
		$this->run_batch( 1 );
		$this->schedule_next();
	}

	/** Processes one bounded batch under a configured worker slot.
	 *
	 * @param int $batch_size Maximum number of jobs for this invocation.
	 * @return array{claimed:int,completed:int,failed:int,skipped:int} Outcome counts.
	 */
	public function run_batch( int $batch_size = 1 ): array {
		$counts = array(
			'claimed'   => 0,
			'completed' => 0,
			'failed'    => 0,
			'skipped'   => 0,
		);
		if ( QueueState::is_paused() ) {
			return $counts;
		}
		$this->jobs->recover_stale();
		$settings   = Settings::sanitize( get_option( Plugin::OPTION_NAME, array() ) );
		$batch_size = max( 1, min( 10, $batch_size ) );
		$token      = bin2hex( random_bytes( 32 ) );
		if ( ! $this->jobs->acquire_slot( $token, $settings['concurrency'] ) ) {
			return $counts;
		}
		try {
			for ( $index = 0; $index < $batch_size; ++$index ) {
				if ( QueueState::is_paused() || ! $this->jobs->renew_slot( $token ) ) {
					break;
				}
				$job = $this->jobs->claim_next( $token );
				if ( null === $job ) {
					break;
				}
				++$counts['claimed'];
				try {
					$result = $this->processor->process( $job );
				} catch ( \Throwable ) {
					$result = array(
						'status'       => 'failed',
						'input_bytes'  => 0,
						'output_bytes' => null,
						'error_code'   => 'worker_exception',
					);
				}
				if ( 'failed' === $result['status'] ) {
					$this->jobs->fail( (int) $job['id'], $token, (string) $result['error_code'] );
					++$counts['failed'];
				} else {
					$this->jobs->finish( (int) $job['id'], $token, $result['status'], $result['input_bytes'], $result['output_bytes'], $result['error_code'] );
					++$counts[ 'completed' === $result['status'] ? 'completed' : 'skipped' ];
				}
			}
		} finally {
			$this->jobs->release_slot( $token );
		}
		return $counts;
	}

	/** Schedules a future bounded tick when pending jobs exist. */
	public function schedule_next(): void {
		if ( QueueState::is_paused() || null !== wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		$delay = $this->jobs->next_pending_delay();
		if ( null !== $delay ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
		}
	}

	/** Brings the next queue tick forward after newly ready work is inserted. */
	public function schedule_immediate(): void {
		if ( QueueState::is_paused() || 0 === $this->jobs->pending_count() ) {
			return;
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_single_event( time() + 5, self::CRON_HOOK );
	}
}
