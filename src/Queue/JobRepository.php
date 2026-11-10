<?php
/**
 * Database-backed queue storage and atomic worker leases.
 *
 * @package Jocky\ImageOptimizer\Queue
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Queue;

use Jocky\ImageOptimizer\Recovery\AttachmentOperationLock;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue reads must reflect current persisted state; transactional claims cannot use the object cache.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Identifiers are derived only from the trusted WordPress table prefix; all dynamic data values use $wpdb->prepare().

/** Persists idempotent jobs and claims them with InnoDB row locks. */
final class JobRepository {
	/** Optional independent connection used by integration concurrency checks.
	 *
	 * @var \wpdb|null
	 */
	private ?\wpdb $connection;

	/** Creates a repository, optionally bound to a specific database connection.
	 *
	 * @param \wpdb|null $connection Database connection.
	 */
	public function __construct( ?\wpdb $connection = null ) {
		$this->connection = $connection;
	}

	public const MAX_ATTEMPTS  = 3;
	public const STALE_SECONDS = 600;
	public const MAX_BATCH     = 100;

	/** Enqueues a bounded batch and returns the number of newly inserted jobs.
	 *
	 * @param array<array{attachment_id:int,variant_key:string,output_format:string,source_fingerprint:string}> $jobs Job rows.
	 * @return int Number of new jobs.
	 */
	public function enqueue_many( array $jobs ): int {
		$inserted = 0;
		foreach ( array_chunk( array_slice( $jobs, 0, self::MAX_BATCH * 10 ), self::MAX_BATCH ) as $batch ) {
			$inserted += $this->insert_batch( $batch );
		}
		return $inserted;
	}

	/** Inserts one bounded SQL batch.
	 *
	 * @param array<int, mixed> $jobs Candidate job rows.
	 * @return int Number of new jobs.
	 */
	private function insert_batch( array $jobs ): int {
		global $wpdb;
		if ( array() === $jobs ) {
			return 0;
		}
		$table        = QueueSchema::jobs_table();
		$placeholders = array();
		$values       = array();
		foreach ( $jobs as $job ) {
			if ( ! $this->valid_job( $job ) ) {
				continue;
			}
			$job_key        = hash( 'sha256', implode( '|', array( $job['attachment_id'], $job['variant_key'], $job['output_format'], $job['source_fingerprint'] ) ) );
			$placeholders[] = '(%d,%s,%s,%s,%s,\'pending\',0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())';
			array_push( $values, $job['attachment_id'], $job['variant_key'], $job['output_format'], $job['source_fingerprint'], $job_key );
		}
		if ( array() === $placeholders ) {
			return 0;
		}
		$sql    = "INSERT IGNORE INTO {$table} (attachment_id,variant_key,output_format,source_fingerprint,job_key,status,attempts,available_at,created_at,updated_at) VALUES " . implode( ',', $placeholders );
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
		return false === $result ? 0 : (int) $result;
	}

	/** Atomically acquires one of the configured worker slots.
	 *
	 * @param string $token       Unique worker ownership token.
	 * @param int    $concurrency Configured slot ceiling.
	 * @return bool Whether a slot was acquired.
	 */
	public function acquire_slot( string $token, int $concurrency ): bool {
		global $wpdb;
		$table   = QueueSchema::slots_table();
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET lock_token = %s, locked_at = UTC_TIMESTAMP() WHERE slot_id BETWEEN 1 AND %d AND (lock_token IS NULL OR locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)) ORDER BY slot_id ASC LIMIT 1",
				$token,
				max( 1, min( 4, $concurrency ) ),
				self::STALE_SECONDS
			)
		);
		return 1 === $updated;
	}

	/** Atomically acquires the single bulk scanner lease in slot zero.
	 *
	 * @param string $token Unique scanner ownership token.
	 * @return bool Whether the scan lease was acquired.
	 */
	public function acquire_scanner_lease( string $token ): bool {
		global $wpdb;
		$table   = QueueSchema::slots_table();
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET lock_token = %s, locked_at = UTC_TIMESTAMP() WHERE slot_id = 0 AND (lock_token IS NULL OR locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND))",
				$token,
				self::STALE_SECONDS
			)
		);
		return 1 === $updated;
	}

	/** Releases the bulk scanner lease only when the token still matches.
	 *
	 * @param string $token Scanner ownership token.
	 */
	public function release_scanner_lease( string $token ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . QueueSchema::slots_table() . ' SET lock_token = NULL, locked_at = NULL WHERE slot_id = 0 AND lock_token = %s', $token ) );
	}

	/** Renews a currently owned worker slot.
	 *
	 * @param string $token Ownership token.
	 * @return bool Whether the lease remains owned.
	 */
	public function renew_slot( string $token ): bool {
		global $wpdb;
		$table = QueueSchema::slots_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET locked_at = UTC_TIMESTAMP() WHERE lock_token = %s", $token ) );
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT slot_id FROM {$table} WHERE lock_token = %s LIMIT 1", $token ) );
	}

	/** Releases only the slot owned by this worker.
	 *
	 * @param string $token Ownership token.
	 */
	public function release_slot( string $token ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . QueueSchema::slots_table() . ' SET lock_token = NULL, locked_at = NULL WHERE lock_token = %s', $token ) );
	}

	/** Atomically claims one ready job with a conditional InnoDB update and worker token.
	 *
	 * @param string $token Ownership token.
	 * @return array<string, mixed>|null Claimed job.
	 */
	public function claim_next( string $token ): ?array {
		global $wpdb;
		$db      = $this->connection ?? $wpdb;
		$table   = QueueSchema::jobs_table();
		$updated = $db->query(
			$db->prepare(
				"UPDATE {$table} SET status = 'running', attempts = attempts + 1, lock_token = %s, locked_at = UTC_TIMESTAMP(), started_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP(), error_code = NULL WHERE status = 'pending' AND available_at <= UTC_TIMESTAMP() ORDER BY id ASC LIMIT 1",
				$token
			)
		);
		if ( 1 !== $updated ) {
			return null;
		}
		$job = $db->get_row( $db->prepare( "SELECT * FROM {$table} WHERE status = 'running' AND lock_token = %s ORDER BY id DESC LIMIT 1", $token ), ARRAY_A );
		return is_array( $job ) ? $job : null;
	}

	/** Returns stale running jobs to the queue only after acquiring their shared attachment lock.
	 *
	 * A live optimizer holds the same lock, so an expired timestamp alone cannot transfer ownership.
	 */
	public function recover_stale(): void {
		global $wpdb;
		$table = QueueSchema::jobs_table();
		$stale = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id,attachment_id FROM {$table} WHERE status = 'running' AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND) ORDER BY id ASC LIMIT %d", self::STALE_SECONDS, self::MAX_BATCH ),
			ARRAY_A
		);
		$locks = new AttachmentOperationLock();
		foreach ( $stale as $job ) {
			$attachment_id = (int) $job['attachment_id'];
			if ( ! $locks->acquire( $attachment_id ) ) {
				continue;
			}
			try {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET status = IF(attempts >= %d,'failed','pending'), available_at = UTC_TIMESTAMP(), finished_at = IF(attempts >= %d,UTC_TIMESTAMP(),NULL), lock_token = NULL, locked_at = NULL, error_code = 'stale_worker_recovered', updated_at = UTC_TIMESTAMP() WHERE id = %d AND status = 'running' AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)",
						self::MAX_ATTEMPTS,
						self::MAX_ATTEMPTS,
						(int) $job['id'],
						self::STALE_SECONDS
					)
				);
			} finally {
				$locks->release( $attachment_id );
			}
		}
	}

	/** Marks a job completed or skipped only when its worker still owns it.
	 *
	 * @param int         $id          Job identifier.
	 * @param string      $token       Ownership token.
	 * @param string      $status      completed or skipped.
	 * @param int         $input_bytes Input file bytes.
	 * @param int|null    $output_bytes Output bytes.
	 * @param string|null $error_code  Optional stable outcome code.
	 * @return bool Whether the transition succeeded.
	 */
	public function finish( int $id, string $token, string $status, int $input_bytes, ?int $output_bytes, ?string $error_code = null ): bool {
		global $wpdb;
		if ( ! in_array( $status, array( 'completed', 'skipped' ), true ) ) {
			return false;
		}
		$saved = null === $output_bytes ? 0 : $input_bytes - $output_bytes;
		$sql   = 'UPDATE ' . QueueSchema::jobs_table() . " SET status = %s, input_bytes = %d, output_bytes = %d, bytes_saved = %d, error_code = %s, finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP(), lock_token = NULL, locked_at = NULL WHERE id = %d AND status = 'running' AND lock_token = %s";
		return 1 === $wpdb->query( $wpdb->prepare( $sql, $status, $input_bytes, $output_bytes ?? 0, $saved, $error_code, $id, $token ) );
	}

	/** Records a failure and applies bounded exponential retry backoff.
	 *
	 * @param int    $id         Job identifier.
	 * @param string $token      Ownership token.
	 * @param string $error_code Stable non-sensitive error category.
	 * @return bool Whether the transition succeeded.
	 */
	public function fail( int $id, string $token, string $error_code ): bool {
		global $wpdb;
		$code    = preg_match( '/^[a-z0-9_]{1,64}$/', $error_code ) ? $error_code : 'processing_failed';
		$table   = QueueSchema::jobs_table();
		$sql     = "UPDATE {$table} SET status = IF(attempts >= %d,'failed','pending'), available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND), error_code = %s, finished_at = IF(attempts >= %d,UTC_TIMESTAMP(),NULL), lock_token = NULL, locked_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = %d AND status = 'running' AND lock_token = %s";
		$attempt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d AND status = 'running' AND lock_token = %s", $id, $token ) );
		$delay   = min( 3600, 60 * ( 2 ** max( 0, $attempt - 1 ) ) );
		return 1 === $wpdb->query( $wpdb->prepare( $sql, self::MAX_ATTEMPTS, $delay, $code, self::MAX_ATTEMPTS, $id, $token ) );
	}

	/** Requeues failed work after an explicit administrator retry request.
	 *
	 * @return int Number of jobs reset.
	 */
	public function retry_failed(): int {
		global $wpdb;
		$result = $wpdb->query( 'UPDATE ' . QueueSchema::jobs_table() . " SET status = 'pending', attempts = 0, available_at = UTC_TIMESTAMP(), finished_at = NULL, error_code = NULL, updated_at = UTC_TIMESTAMP() WHERE status = 'failed'" );
		return false === $result ? 0 : (int) $result;
	}

	/** Cancels pending jobs without interrupting an active encoder process.
	 *
	 * @return int Number of pending jobs canceled.
	 */
	public function cancel_pending(): int {
		global $wpdb;
		$result = $wpdb->query( 'UPDATE ' . QueueSchema::jobs_table() . " SET status = 'cancelled', finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE status = 'pending'" );
		return false === $result ? 0 : (int) $result;
	}

	/** Returns persisted queue totals and measured output statistics.
	 *
	 * @return array<string, int|float> Actual aggregate queue values.
	 */
	public function stats(): array {
		global $wpdb;
		$table = QueueSchema::jobs_table();
		$rows  = $wpdb->get_results( "SELECT status,COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );
		$stats = array(
			'pending'   => 0,
			'running'   => 0,
			'completed' => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'cancelled' => 0,
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $stats[ $row['status'] ] ) ) {
				$stats[ $row['status'] ] = (int) $row['total'];
			}
		}
		$stats['completed_attachments'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT attachment_id) FROM {$table} WHERE status IN ('completed','skipped')" );
		$stats['bytes_saved']           = (int) $wpdb->get_var( "SELECT COALESCE(SUM(bytes_saved),0) FROM {$table} WHERE status = 'completed'" );
		$stats['average_savings']       = (float) $wpdb->get_var( "SELECT COALESCE(AVG((CAST(input_bytes AS SIGNED)-CAST(output_bytes AS SIGNED))/input_bytes*100),0) FROM {$table} WHERE status = 'completed' AND input_bytes > 0" );
		return $stats;
	}

	/** Returns recent jobs without exposing local absolute paths or process output.
	 *
	 * @param int $limit Maximum number of rows.
	 * @return array<int, array<string, mixed>> Recent job data.
	 */
	public function recent( int $limit = 10 ): array {
		global $wpdb;
		$table = QueueSchema::jobs_table();
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id,attachment_id,variant_key,output_format,status,attempts,input_bytes,output_bytes,bytes_saved,error_code,created_at,finished_at FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, min( 25, $limit ) ) ),
			ARRAY_A
		);
	}

	/** Counts jobs eligible to run now or at a later retry time.
	 *
	 * @return int Pending jobs.
	 */
	public function pending_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . QueueSchema::jobs_table() . " WHERE status = 'pending'" );
	}

	/** Returns bounded retry scheduling delay in seconds.
	 *
	 * @return int|null Delay until the next pending job is available.
	 */
	public function next_pending_delay(): ?int {
		global $wpdb;
		$seconds = $wpdb->get_var( 'SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),MIN(available_at)) FROM ' . QueueSchema::jobs_table() . " WHERE status = 'pending'" );
		return null === $seconds ? null : max( 5, min( 3600, (int) $seconds ) );
	}

	/** Checks the bounded data contract for a queue insert.
	 *
	 * @param mixed $job Candidate row.
	 * @return bool Whether the candidate is valid.
	 */
	private function valid_job( $job ): bool {
		return is_array( $job )
			&& isset( $job['attachment_id'], $job['variant_key'], $job['output_format'], $job['source_fingerprint'] )
			&& is_int( $job['attachment_id'] ) && $job['attachment_id'] > 0
			&& is_string( $job['variant_key'] ) && 1 === preg_match( '/^(full|original|size:[a-z0-9_-]{1,64})$/', $job['variant_key'] )
			&& is_string( $job['output_format'] ) && in_array( $job['output_format'], array( 'source', 'image/webp', 'image/avif' ), true )
			&& is_string( $job['source_fingerprint'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $job['source_fingerprint'] );
	}
}
