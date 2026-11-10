<?php
/**
 * Runs persistent derivative cleanup in small cron batches.
 *
 * @package Jocky\ImageOptimizer\Recovery
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Recovery;

/** Schedules cleanup only after an administrator explicitly starts a batch. */
final class RecoveryWorker {

	public const CRON_HOOK = 'jio_process_cleanup';

	/** Creates the worker.
	 *
	 * @param RecoveryService $service Recovery and cleanup service.
	 */
	public function __construct( private RecoveryService $service ) {}

	/** Registers the bounded cleanup callback. */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled' ), 10, 1 );
	}

	/** Runs one scheduled batch.
	 *
	 * @param int $batch_id Persistent cleanup batch ID.
	 */
	public function run_scheduled( int $batch_id ): void {
		$result = $this->service->cleanup_batch( $batch_id, 10 );
		if ( 'running' === $result['status'] ) {
			$this->schedule( $batch_id );
		}
	}

	/** Schedules one continuation without creating duplicate events.
	 *
	 * @param int $batch_id Persistent cleanup batch ID.
	 */
	public function schedule( int $batch_id ): void {
		$args = array( $batch_id );
		if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, $args );
		}
	}
}
