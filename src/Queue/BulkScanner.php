<?php
/**
 * Keyset-paginated scanner for existing image attachments.
 *
 * @package Jocky\ImageOptimizer\Queue
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Queue;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset pagination and candidate counts must read persisted attachment rows without loading the library into memory.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The posts table comes from the trusted WordPress database object; cursor and page-size data are prepared.

/** Enqueues at most three attachment records in each scheduled scan tick. */
final class BulkScanner {

	public const CRON_HOOK = 'jio_scan_attachments';
	public const STATE     = 'jio_bulk_scan_state';
	public const BATCH     = 3;

	/** Registers the scanner cron callback.
	 *
	 * @param JobRepository $jobs Persistent queue storage.
	 * @param QueueWorker   $worker Bounded worker scheduler.
	 */
	public function __construct( private JobRepository $jobs, private QueueWorker $worker ) {}

	/** Registers the scheduled scanner. */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'scan_batch' ) );
	}

	/** Begins a resumable scan from the first supported attachment ID.
	 *
	 * @return bool Whether a scan was started.
	 */
	public function start(): bool {
		$state = get_option( self::STATE, array() );
		if ( is_array( $state ) && in_array( $state['status'] ?? '', array( 'scanning', 'paused' ), true ) ) {
			return false;
		}
		update_option(
			self::STATE,
			array(
				'status'       => 'scanning',
				'last_id'      => 0,
				'scanned'      => 0,
				'jobs_added'   => 0,
				'total_images' => $this->count_attachments(),
				'started_gmt'  => gmdate( 'c' ),
			),
			false
		);
		$this->schedule( 5 );
		return true;
	}

	/** Stops further scanning without canceling already persisted jobs. */
	public function pause(): void {
		$state = get_option( self::STATE, array() );
		if ( is_array( $state ) && 'scanning' === ( $state['status'] ?? '' ) ) {
			$state['status'] = 'paused';
			update_option( self::STATE, $state, false );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** Resumes the keyset scan from its durable last-ID cursor. */
	public function resume(): void {
		$state = get_option( self::STATE, array() );
		if ( is_array( $state ) && 'paused' === ( $state['status'] ?? '' ) ) {
			$state['status'] = 'scanning';
			update_option( self::STATE, $state, false );
			$this->schedule( 5 );
		}
	}

	/** Cancels scanning but leaves completed, failed, and running jobs intact. */
	public function cancel(): void {
		$state = get_option( self::STATE, array() );
		if ( is_array( $state ) ) {
			$state['status'] = 'cancelled';
			update_option( self::STATE, $state, false );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** Scans a bounded page and stores progress after queue insertion.
	 *
	 * @return int Number of attachment IDs scanned.
	 */
	public function scan_batch(): int {
		$token = bin2hex( random_bytes( 32 ) );
		if ( ! $this->jobs->acquire_scanner_lease( $token ) ) {
			return 0;
		}
		try {
			return $this->scan_batch_locked();
		} finally {
			$this->jobs->release_scanner_lease( $token );
		}
	}

	/** Runs one page while holding the exclusive scanner lease.
	 *
	 * @return int Number of attachment IDs scanned.
	 */
	private function scan_batch_locked(): int {
		global $wpdb;
		$state = get_option( self::STATE, array() );
		if ( ! is_array( $state ) || 'scanning' !== ( $state['status'] ?? '' ) ) {
			return 0;
		}
		$table = $wpdb->posts;
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$table} WHERE ID > %d AND post_type = 'attachment' AND post_status IN ('inherit','private') AND post_mime_type IN ('image/jpeg','image/png') ORDER BY ID ASC LIMIT %d",
				(int) $state['last_id'],
				self::BATCH
			)
		);
		$ids   = array_map( 'intval', is_array( $ids ) ? $ids : array() );
		if ( array() === $ids ) {
			$state['status'] = 'complete';
			update_option( self::STATE, $state, false );
			return 0;
		}
		$factory = new AttachmentJobFactory();
		$jobs    = array();
		foreach ( $ids as $attachment_id ) {
			$jobs = array_merge( $jobs, $factory->jobs_for_attachment( $attachment_id ) );
		}
		$state['last_id']    = (int) end( $ids );
		$state['scanned']    = (int) $state['scanned'] + count( $ids );
		$state['jobs_added'] = (int) $state['jobs_added'] + $this->jobs->enqueue_many( $jobs );
		update_option( self::STATE, $state, false );
		$this->worker->schedule_immediate();
		$this->schedule( 5 );
		return count( $ids );
	}

	/** Returns a snapshot of persisted scan state.
	 *
	 * @return array<string, int|string> Scan state.
	 */
	public function state(): array {
		$state    = get_option( self::STATE, array() );
		$defaults = array(
			'status'       => 'idle',
			'last_id'      => 0,
			'scanned'      => 0,
			'jobs_added'   => 0,
			'total_images' => $this->count_attachments(),
		);
		if ( ! is_array( $state ) ) {
			return $defaults;
		}
		$state = array_merge( $defaults, $state );
		if ( ! in_array( $state['status'], array( 'idle', 'scanning', 'paused', 'cancelled', 'complete' ), true ) ) {
			$state['status'] = 'idle';
		}
		foreach ( array( 'last_id', 'scanned', 'jobs_added', 'total_images' ) as $counter ) {
			$state[ $counter ] = max( 0, (int) $state[ $counter ] );
		}
		return $state;
	}

	/** Counts candidate MIME types using one bounded aggregate query.
	 *
	 * @return int JPEG and PNG attachments.
	 */
	public function count_attachments(): int {
		global $wpdb;
		$table = $wpdb->posts;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE post_type = 'attachment' AND post_status IN ('inherit','private') AND post_mime_type IN ('image/jpeg','image/png')" );
	}

	/** Schedules a single future scanner tick.
	 *
	 * @param int $delay Delay in seconds.
	 */
	private function schedule( int $delay ): void {
		if ( null === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + max( 5, $delay ), self::CRON_HOOK );
		}
	}
}
