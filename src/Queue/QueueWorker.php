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
use Jocky\ImageOptimizer\Integration\AttachmentReadiness;

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
		add_action( 'jio_queue_resumed', array( $this, 'schedule_immediate' ) );
		// Reconcile on WordPress requests, never as a side effect of scoped WP-CLI use.
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->schedule_next();
		}
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
	 * @param int                 $batch_size Maximum number of jobs for this invocation.
	 * @param array<int,int>|null $attachment_ids Optional attachment claim scope.
	 * @return array{claimed:int,completed:int,failed:int,skipped:int,jobs:array<int,array{job_id:int,attachment_id:int,status:string,error_code:?string}>} Outcome counts and sanitized job outcomes.
	 */
	public function run_batch( int $batch_size = 1, ?array $attachment_ids = null ): array {
		$counts = array(
			'claimed'   => 0,
			'completed' => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'jobs'      => array(),
		);
		if ( QueueState::is_paused() ) {
			return $counts;
		}
		$recovered = $this->jobs->recover_stale( $attachment_ids );
		foreach ( $recovered as $outcome ) {
			if ( 'failed' === $outcome['status'] ) {
				AttachmentReadiness::publish_if_terminal( $outcome['attachment_id'] );
				++$counts['failed'];
			}
			$counts['jobs'][] = array(
				'job_id'        => $outcome['job_id'],
				'attachment_id' => $outcome['attachment_id'],
				'status'        => $outcome['status'],
				'error_code'    => $outcome['error_code'],
			);
		}
		if ( null !== $attachment_ids && $counts['failed'] > 0 ) {
			return $counts;
		}
		if ( null !== $attachment_ids && 0 === $this->jobs->eligible_pending_count( $attachment_ids ) ) {
			return $counts;
		}
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
				$job = $this->jobs->claim_next( $token, $attachment_ids );
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
					$failed = $this->jobs->fail( (int) $job['id'], $token, (string) $result['error_code'] );
					if ( $failed ) {
						AttachmentReadiness::publish_if_terminal( (int) $job['attachment_id'] );
					}
					++$counts['failed'];
					$counts['jobs'][] = $this->job_outcome( $job, 'failed', (string) $result['error_code'] );
					if ( null !== $attachment_ids ) {
						break;
					}
				} else {
					$finished = $this->jobs->finish( (int) $job['id'], $token, $result['status'], $result['input_bytes'], $result['output_bytes'], $result['error_code'] );
					if ( $finished ) {
						AttachmentReadiness::publish_if_terminal( (int) $job['attachment_id'] );
					}
					++$counts[ 'completed' === $result['status'] ? 'completed' : 'skipped' ];
					$counts['jobs'][] = $this->job_outcome( $job, (string) $result['status'], $result['error_code'] );
				}
			}
		} finally {
			$this->jobs->release_slot( $token );
		}
		return $counts;
	}

	/** Returns an outcome containing identifiers and a stable error code only.
	 *
	 * @param array<string,mixed> $job Job row.
	 * @param string              $status Final status.
	 * @param string|null         $error_code Stable error code.
	 * @return array{job_id:int,attachment_id:int,status:string,error_code:?string} Sanitized outcome.
	 */
	private function job_outcome( array $job, string $status, ?string $error_code ): array {
		$code = null !== $error_code && 1 === preg_match( '/^[a-z0-9_]{1,64}$/', $error_code ) ? $error_code : ( null === $error_code ? null : 'processing_failed' );
		return array(
			'job_id'        => (int) $job['id'],
			'attachment_id' => (int) $job['attachment_id'],
			'status'        => $status,
			'error_code'    => $code,
		);
	}

	/** Schedules a future bounded tick when pending jobs exist. */
	public function schedule_next(): void {
		if ( QueueState::is_paused() || false !== wp_next_scheduled( self::CRON_HOOK ) ) {
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
