<?php
/**
 * Persistent derivative ownership and recovery audit records.
 *
 * @package Jocky\ImageOptimizer\Recovery
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Recovery;

use Jocky\ImageOptimizer\Queue\QueueSchema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Recovery inventory and lifecycle writes must be immediately consistent with filesystem operations.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifiers come only from the trusted WordPress table prefix.

/** Stores portable relative-path ownership records, lifecycle state, and audit history. */
final class DerivativeRepository {

	/** Records a validated derivative, idempotently for a source/variant/format tuple.
	 *
	 * @param array<string, mixed> $record Validated ownership record.
	 * @return int|false Database row ID or false on failure.
	 */
	public function record( array $record ): int|false {
		global $wpdb;
		$key   = hash( 'sha256', implode( '|', array( (int) $record['attachment_id'], (string) $record['variant_key'], (string) $record['output_format'], (string) $record['source_sha256'] ) ) );
		$table = QueueSchema::derivatives_table();
		$sql   = $wpdb->prepare(
			"INSERT INTO {$table} (record_key,attachment_id,variant_key,source_relative_path,source_mime,source_bytes,source_sha256,source_fingerprint,metadata_hash,derivative_relative_path,output_format,derivative_sha256,input_bytes,output_bytes,encoder_id,encoder_parameters,validation_status,lifecycle_status,created_at,updated_at)
			VALUES (%s,%d,%s,%s,%s,%d,%s,%s,%s,%s,%s,%s,%d,%d,%s,%s,'valid','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())
			ON DUPLICATE KEY UPDATE source_relative_path=VALUES(source_relative_path),source_mime=VALUES(source_mime),source_bytes=VALUES(source_bytes),source_fingerprint=VALUES(source_fingerprint),metadata_hash=VALUES(metadata_hash),derivative_relative_path=VALUES(derivative_relative_path),derivative_sha256=VALUES(derivative_sha256),input_bytes=VALUES(input_bytes),output_bytes=VALUES(output_bytes),encoder_id=VALUES(encoder_id),encoder_parameters=VALUES(encoder_parameters),validation_status='valid',lifecycle_status='active',updated_at=UTC_TIMESTAMP()",
			$key,
			(int) $record['attachment_id'],
			(string) $record['variant_key'],
			(string) $record['source_relative_path'],
			(string) $record['source_mime'],
			(int) $record['source_bytes'],
			(string) $record['source_sha256'],
			(string) $record['source_fingerprint'],
			(string) $record['metadata_hash'],
			(string) $record['derivative_relative_path'],
			(string) $record['output_format'],
			(string) $record['derivative_sha256'],
			(int) $record['input_bytes'],
			(int) $record['output_bytes'],
			(string) $record['encoder_id'],
			(string) $record['encoder_parameters']
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only the immediately preceding $wpdb->prepare() result.
		if ( false === $wpdb->query( $sql ) ) {
			return false;
		}
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE record_key = %s", $key ) );
		return $id > 0 ? $id : false;
	}

	/** Finds current derivative records for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $limit Maximum rows.
	 * @param int $after_id Keyset cursor.
	 * @return array<int, array<string, mixed>> Records.
	 */
	public function for_attachment( int $attachment_id, int $limit = 10, int $after_id = 0 ): array {
		global $wpdb;
		$table = QueueSchema::derivatives_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE attachment_id = %d AND id > %d ORDER BY id ASC LIMIT %d", $attachment_id, max( 0, $after_id ), max( 1, min( 10, $limit ) ) ), ARRAY_A );
	}

	/** Lists a bounded page of candidate, conflicted, or interrupted attachment derivatives for cleanup reinspection.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $limit Maximum rows, capped at ten.
	 * @param int $after_id Keyset cursor.
	 */
	public function active_for_attachment( int $attachment_id, int $limit = 10, int $after_id = 0 ): array {
		global $wpdb;
		$table = QueueSchema::derivatives_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE attachment_id = %d AND lifecycle_status IN ('active','conflict','removing') AND id > %d ORDER BY id ASC LIMIT %d", $attachment_id, max( 0, $after_id ), max( 1, min( 10, $limit ) ) ), ARRAY_A );
	}

	/** Counts records after an attachment-specific cursor without loading them.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param int  $after_id Last record ID.
	 * @param bool $active_only Whether only non-cleaned cleanup candidates count.
	 */
	public function count_attachment_after( int $attachment_id, int $after_id, bool $active_only = false ): int {
		global $wpdb;
		$table  = QueueSchema::derivatives_table();
		$filter = $active_only ? " AND lifecycle_status IN ('active','conflict','removing')" : '';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE attachment_id = %d AND id > %d{$filter}", $attachment_id, max( 0, $after_id ) ) );
	}

	/** Counts all derivative registry rows for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function count_for_attachment( int $attachment_id ): int {
		global $wpdb;
		$table = QueueSchema::derivatives_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE attachment_id = %d", $attachment_id ) );
	}

	/** Checks whether a legacy source variant and output format were already imported.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $variant Variant key.
	 * @param string $format Output format.
	 * @param string $fingerprint Source fingerprint.
	 */
	public function has_variant_format( int $attachment_id, string $variant, string $format, string $fingerprint ): bool {
		global $wpdb;
		$table = QueueSchema::derivatives_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE attachment_id = %d AND variant_key = %s AND output_format = %s AND source_fingerprint = %s", $attachment_id, $variant, $format, $fingerprint ) ) > 0;
	}

	/** Fetches one record by trusted database identifier.
	 *
	 * @param int $id Derivative row ID.
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = QueueSchema::derivatives_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Updates lifecycle state only from the expected prior state.
	 *
	 * @param int    $id Derivative row ID.
	 * @param string $from Expected current state.
	 * @param string $to New state.
	 */
	public function set_lifecycle( int $id, string $from, string $to ): bool {
		global $wpdb;
		$table   = QueueSchema::derivatives_table();
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lifecycle_status = %s, updated_at = UTC_TIMESTAMP() WHERE id = %d AND lifecycle_status = %s", $to, $id, $from ) );
		return 1 === $changed;
	}

	/** Updates integrity/lifecycle status for one owned record.
	 *
	 * @param int    $id Derivative row ID.
	 * @param string $validation Validation result.
	 * @param string $lifecycle Lifecycle state.
	 */
	public function set_status( int $id, string $validation, string $lifecycle ): bool {
		global $wpdb;
		$table   = QueueSchema::derivatives_table();
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET validation_status = %s, lifecycle_status = %s, updated_at = UTC_TIMESTAMP() WHERE id = %d", $validation, $lifecycle, $id ) );
		return false !== $changed;
	}

	/** Checks whether optimization jobs are pending or currently processing for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function has_active_optimization( int $attachment_id ): bool {
		global $wpdb;
		$table = QueueSchema::jobs_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE attachment_id = %d AND status IN ('pending','running')", $attachment_id ) ) > 0;
	}

	/** Adds a bounded audit event; details must not include secrets or absolute paths.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param int    $derivative_id Derivative row ID.
	 * @param string $action Allow-listed action identifier.
	 * @param string $status Outcome.
	 * @param array  $details Non-sensitive bounded details.
	 */
	public function audit( int $attachment_id, int $derivative_id, string $action, string $status, array $details = array() ): bool {
		global $wpdb;
		$encoded = wp_json_encode( $details );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 4000 ) {
			$encoded = '{"detail":"omitted"}';
		}
		$table = QueueSchema::events_table();
		return false !== $wpdb->insert(
			$table,
			array(
				'attachment_id' => max( 0, $attachment_id ),
				'derivative_id' => max( 0, $derivative_id ),
				'action'        => sanitize_key( $action ),
				'status'        => sanitize_key( $status ),
				'details'       => $encoded,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/** Lists a page of recent completed, failed, and skipped optimization jobs.
	 *
	 * @param int $page Page number.
	 * @param int $per_page Maximum rows per page.
	 */
	public function history( int $page = 1, int $per_page = 25 ): array {
		global $wpdb;
		$jobs   = QueueSchema::jobs_table();
		$table  = QueueSchema::derivatives_table();
		$offset = ( max( 1, $page ) - 1 ) * max( 1, min( 100, $per_page ) );
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT j.id AS job_id,j.attachment_id,j.variant_key,j.output_format,j.input_bytes,j.output_bytes,j.bytes_saved,j.status AS job_status,j.finished_at AS created_at,d.lifecycle_status,d.validation_status,d.derivative_relative_path
				FROM {$jobs} j LEFT JOIN {$table} d ON d.attachment_id=j.attachment_id AND d.variant_key=j.variant_key AND d.output_format=j.output_format AND d.source_fingerprint=j.source_fingerprint
				WHERE j.status IN ('completed','skipped','failed') ORDER BY j.id DESC LIMIT %d OFFSET %d",
				max( 1, min( 100, $per_page ) ),
				$offset
			),
			ARRAY_A
		);
	}

	/** Returns one bounded page of recovery audit events.
	 *
	 * @param int $page Page number.
	 * @param int $per_page Maximum rows per page.
	 * @return array<int, array<string, mixed>> Sanitized audit records.
	 */
	public function events( int $page = 1, int $per_page = 25 ): array {
		global $wpdb;
		$table  = QueueSchema::events_table();
		$limit  = max( 1, min( 100, $per_page ) );
		$offset = ( max( 1, $page ) - 1 ) * $limit;
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id,attachment_id,derivative_id,action,status,details,created_at FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		);
	}

	/** Returns compact lifecycle totals. */
	public function totals(): array {
		global $wpdb;
		$table = QueueSchema::derivatives_table();
		$rows  = (array) $wpdb->get_results( "SELECT lifecycle_status,validation_status,COUNT(*) AS records,SUM(output_bytes) AS bytes FROM {$table} GROUP BY lifecycle_status,validation_status", ARRAY_A );
		return $rows;
	}

	/** Returns the currently registered cleanup preview totals without loading rows. */
	public function active_summary(): array {
		global $wpdb;
		$table = QueueSchema::derivatives_table();
		$row   = $wpdb->get_row( "SELECT COUNT(*) AS records, COALESCE(SUM(output_bytes),0) AS bytes, COUNT(DISTINCT attachment_id) AS attachments FROM {$table} WHERE lifecycle_status = 'active'", ARRAY_A );
		return is_array( $row ) ? array_map( 'intval', $row ) : array(
			'records'     => 0,
			'bytes'       => 0,
			'attachments' => 0,
		);
	}

	/** Lists a bounded page of registered cleanup candidates for the admin preview.
	 *
	 * @param int $after_id Last derivative ID in the previous page.
	 * @param int $limit Maximum rows, capped at twenty-five.
	 * @return array<int, array<string, mixed>> Candidate registry rows.
	 */
	public function active_page( int $after_id = 0, int $limit = 10 ): array {
		global $wpdb;
		$table = QueueSchema::derivatives_table();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,attachment_id,variant_key,output_format,output_bytes,lifecycle_status,validation_status FROM {$table} WHERE lifecycle_status = 'active' AND id > %d ORDER BY id ASC LIMIT %d",
				max( 0, $after_id ),
				max( 1, min( 25, $limit ) )
			),
			ARRAY_A
		);
	}

	/** Returns eligible active rows after a keyset cursor, capped to a bounded page.
	 *
	 * @param int $after_id Last inspected derivative ID.
	 * @param int $limit Maximum rows.
	 */
	public function cleanup_page( int $after_id, int $limit ): array {
		global $wpdb;
		$table = QueueSchema::derivatives_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d AND lifecycle_status = 'active' ORDER BY id ASC LIMIT %d", $after_id, max( 1, min( 50, $limit ) ) ), ARRAY_A );
	}

	/** Creates one persisted bounded cleanup batch from a dry-run summary.
	 *
	 * @param int $eligible Registered candidate count.
	 * @param int $requested_by Requesting WordPress user ID.
	 */
	public function create_cleanup_batch( int $eligible, int $requested_by ): int {
		global $wpdb;
		$table  = QueueSchema::cleanup_table();
		$result = $wpdb->insert(
			$table,
			array(
				'status'         => 'pending',
				'eligible_count' => max( 0, $eligible ),
				'requested_by'   => max( 0, $requested_by ),
				'created_at'     => current_time( 'mysql', true ),
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%s' )
		);
		return false === $result ? 0 : (int) $wpdb->insert_id;
	}

	/** Returns the latest persisted cleanup batch. */
	public function latest_cleanup_batch(): ?array {
		global $wpdb;
		$table = QueueSchema::cleanup_table();
		$row   = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Loads a specific persistent cleanup batch.
	 *
	 * @param int $id Cleanup batch ID.
	 */
	public function cleanup_batch( int $id ): ?array {
		global $wpdb;
		$table = QueueSchema::cleanup_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Mutates a cleanup batch state through conditional status transition.
	 *
	 * @param int      $id Cleanup batch ID.
	 * @param string[] $from Allowed current states.
	 * @param string   $to New state.
	 */
	public function transition_cleanup( int $id, array $from, string $to ): bool {
		global $wpdb;
		$table   = QueueSchema::cleanup_table();
		$allowed = array_map( 'sanitize_key', $from );
		if ( array() === $allowed ) {
			return false;
		}
		$placeholders = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
		$args         = array_merge( array( sanitize_key( $to ), current_time( 'mysql', true ), $id ), $allowed );
		$sql          = "UPDATE {$table} SET status = %s, updated_at = %s WHERE id = %d AND status IN ({$placeholders})";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic state placeholders are assembled from the allow-listed $from count and every value is passed to prepare().
		return 1 === $wpdb->query( $wpdb->prepare( $sql, ...$args ) );
	}

	/** Advances one cleanup batch after processing one derivative row.
	 *
	 * @param int  $batch_id Cleanup batch ID.
	 * @param int  $last_id Derivative cursor.
	 * @param bool $cleaned Whether removal succeeded.
	 * @param bool $conflict Whether validation failed.
	 */
	public function advance_cleanup( int $batch_id, int $last_id, bool $cleaned, bool $conflict ): bool {
		global $wpdb;
		$table = QueueSchema::cleanup_table();
		return 1 === $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET last_derivative_id = %d, processed_count = processed_count + 1, cleaned_count = cleaned_count + %d, conflict_count = conflict_count + %d, updated_at = UTC_TIMESTAMP() WHERE id = %d AND status = 'running'",
				$last_id,
				$cleaned ? 1 : 0,
				$conflict ? 1 : 0,
				$batch_id
			)
		);
	}

	/** Marks a running batch complete when no eligible rows remain.
	 *
	 * @param int $batch_id Cleanup batch ID.
	 */
	public function complete_cleanup( int $batch_id ): bool {
		global $wpdb;
		$table = QueueSchema::cleanup_table();
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'complete', updated_at = UTC_TIMESTAMP() WHERE id = %d AND status = 'running'", $batch_id ) );
	}

	/** Conditional bulk status update for pause/resume/cancel/retry controls.
	 *
	 * @param int      $id Cleanup batch ID.
	 * @param string[] $from Allow-listed prior states.
	 * @param string   $to New state.
	 */
	public function set_batch_status( int $id, array $from, string $to ): bool {
		global $wpdb;
		$table = QueueSchema::cleanup_table();
		$from  = array_map( 'sanitize_key', $from );
		if ( array() === $from ) {
			return false;
		}
		$marks = implode( ',', array_fill( 0, count( $from ), '%s' ) );
		$args  = array_merge( array( sanitize_key( $to ), current_time( 'mysql', true ), $id ), $from );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The bounded allow-list determines the placeholder count included in $marks.
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, updated_at = %s WHERE id = %d AND status IN ({$marks})", ...$args ) );
	}

	/** Requeues conflicted records for explicit retry; each file is revalidated by the worker. */
	public function retry_conflicts(): int {
		global $wpdb;
		$table = QueueSchema::derivatives_table();
		return max( 0, (int) $wpdb->query( "UPDATE {$table} SET lifecycle_status = 'active', validation_status = 'pending_retry', updated_at = UTC_TIMESTAMP() WHERE lifecycle_status = 'conflict'" ) );
	}
}
