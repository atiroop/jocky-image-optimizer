<?php
/**
 * Verifies originals and safely manages plugin-owned derivatives.
 *
 * @package Jocky\ImageOptimizer\Recovery
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Recovery;

use Jocky\ImageOptimizer\Media\AttachmentTargetProvider;

/** Implements per-attachment integrity verification and non-destructive cleanup. */
final class RecoveryService {
	private const MAX_HASH_BYTES_PER_CALL = 104857600;

	/** Persistent derivative records and recovery batches.
	 *
	 * @var DerivativeRepository
	 */
	private DerivativeRepository $records;
	/** Bounded streaming integrity hasher.
	 *
	 * @var IntegrityHasher
	 */
	private IntegrityHasher $hasher;
	/** Shared per-attachment operation lock.
	 *
	 * @var AttachmentOperationLock
	 */
	private AttachmentOperationLock $lock;
	/** Resolves attachment variants from current core metadata.
	 *
	 * @var AttachmentTargetProvider
	 */
	private AttachmentTargetProvider $targets;
	/** Bounded cumulative hash budget for the current service operation.
	 *
	 * @var int
	 */
	private int $hashed_bytes = 0;
	/** Whether a bounded scan stopped at the cumulative hash budget.
	 *
	 * @var bool
	 */
	private bool $budget_exhausted = false;

	/** Creates the service with its persistence and filesystem dependencies.
	 *
	 * @param DerivativeRepository|null $records Optional registry repository.
	 * @param IntegrityHasher|null      $hasher Optional bounded hasher.
	 */
	public function __construct( ?DerivativeRepository $records = null, ?IntegrityHasher $hasher = null ) {
		$this->records = $records ?? new DerivativeRepository();
		$this->hasher  = $hasher ?? new IntegrityHasher();
		$this->lock    = new AttachmentOperationLock();
		$this->targets = new AttachmentTargetProvider();
	}

	/** Verifies original and derivative bytes for one attachment; no files are changed.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $after_id Keyset cursor for repeated bounded verification.
	 */
	public function verify_attachment( int $attachment_id, int $after_id = 0 ): array {
		$this->hashed_bytes     = 0;
		$this->budget_exhausted = false;
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return array(
				'status'  => 'invalid_attachment',
				'records' => array(),
			);
		}
		$legacy_more = $this->import_legacy_records( $attachment_id );
		$records     = $this->records->for_attachment( $attachment_id, 10, $after_id );
		$results     = array();
		foreach ( $records as $record ) {
			$results[] = $this->inspect_record( $record, true );
		}
		$status = 'valid';
		foreach ( $results as $result ) {
			if ( 'valid' !== $result['integrity_status'] ) {
				$status = $result['integrity_status'];
				break;
			}
		}
		$last_id  = array() === $results ? max( 0, $after_id ) : (int) end( $results )['id'];
		$has_more = $legacy_more || $this->records->count_attachment_after( $attachment_id, $last_id ) > 0;
		$this->records->audit(
			$attachment_id,
			0,
			'verify',
			$status,
			array(
				'records_checked' => count( $results ),
				'has_more'        => $has_more,
			)
		);
		return array(
			'status'        => array() === $results ? 'no_derivatives' : $status,
			'records'       => $results,
			'has_more'      => $has_more,
			'next_after_id' => $last_id,
		);
	}

	/** Produces a read-only cleanup plan with per-file integrity classifications.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $after_id Keyset cursor for repeated bounded previews.
	 */
	public function preview_attachment( int $attachment_id, int $after_id = 0 ): array {
		$this->hashed_bytes     = 0;
		$this->budget_exhausted = false;
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return array(
				'status'    => 'invalid_attachment',
				'eligible'  => array(),
				'conflicts' => array(),
				'bytes'     => 0,
			);
		}
		$legacy_more = $this->import_legacy_records( $attachment_id );
		if ( $this->records->has_active_optimization( $attachment_id ) ) {
			return array(
				'status'    => 'queue_conflict',
				'eligible'  => array(),
				'conflicts' => array(),
				'bytes'     => 0,
			);
		}
		$eligible  = array();
		$conflicts = array();
		$bytes     = 0;
		$rows      = $this->records->active_for_attachment( $attachment_id, 10, $after_id );
		foreach ( $rows as $record ) {
			$checked = $this->inspect_record( $record, true );
			if ( 'valid' === $checked['integrity_status'] ) {
				$eligible[] = $checked;
				$bytes     += (int) $record['output_bytes'];
			} else {
				$conflicts[] = $checked;
			}
		}
		return array(
			'status'        => $this->records->has_active_optimization( $attachment_id ) ? 'queue_conflict' : 'ready',
			'eligible'      => $eligible,
			'conflicts'     => $conflicts,
			'bytes'         => $bytes,
			'next_after_id' => array() === $rows ? max( 0, $after_id ) : (int) end( $rows )['id'],
			'has_more'      => $legacy_more || $this->records->count_attachment_after( $attachment_id, array() === $rows ? max( 0, $after_id ) : (int) end( $rows )['id'], true ) > 0,
		);
	}

	/** Removes only integrity-verified plugin-owned derivative files, retaining originals.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $confirm Whether explicit user confirmation was provided.
	 * @param int  $after_id Keyset cursor for repeated bounded cleanup.
	 */
	public function cleanup_attachment( int $attachment_id, bool $confirm, int $after_id = 0 ): array {
		if ( ! $confirm ) {
			return array(
				'status'    => 'confirmation_required',
				'cleaned'   => 0,
				'conflicts' => 0,
			);
		}
		if ( ! $this->lock->acquire( $attachment_id ) ) {
			return array(
				'status'    => 'operation_locked',
				'cleaned'   => 0,
				'conflicts' => 0,
			);
		}
		try {
			$plan = $this->preview_attachment( $attachment_id, $after_id );
			if ( 'ready' !== $plan['status'] ) {
				$this->records->audit( $attachment_id, 0, 'cleanup', 'conflict', array( 'reason' => $plan['status'] ) );
				return array(
					'status'    => $plan['status'],
					'cleaned'   => 0,
					'conflicts' => count( $plan['conflicts'] ),
				);
			}
			$cleaned       = 0;
			$conflicts     = count( $plan['conflicts'] );
			$next_after_id = (int) $plan['next_after_id'];
			foreach ( $plan['eligible'] as $entry ) {
				$id = (int) $entry['id'];
				if ( ! $this->records->set_lifecycle( $id, 'active', 'removing' ) ) {
					++$conflicts;
					continue;
				}
				$source_path = $this->resolve_owned_path( (string) $entry['source_relative_path'] );
				$path        = $this->resolve_owned_path( (string) $entry['derivative_relative_path'] );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Path confinement and SHA-256/inode identity were rechecked in inspect_record immediately before lifecycle transition.
				if ( null === $source_path || null === $path || ! $this->same_identity( $source_path, $entry['source_identity'] ?? array() ) || ! $this->same_identity( $path, $entry['derivative_identity'] ?? array() ) || ! unlink( $path ) ) {
					$this->records->set_status( $id, 'conflict', 'conflict' );
					$this->records->audit( $attachment_id, $id, 'cleanup', 'conflict', array( 'reason' => 'file_changed_or_unlink_failed' ) );
					++$conflicts;
					continue;
				}
				$this->records->set_status( $id, 'cleaned', 'cleaned' );
				$this->remove_owned_postmeta( $attachment_id, $entry );
				$this->records->audit( $attachment_id, $id, 'cleanup', 'cleaned', array( 'bytes' => (int) $entry['output_bytes'] ) );
				++$cleaned;
				$next_after_id = $id;
				break;
			}
			$more = $this->records->count_attachment_after( $attachment_id, $next_after_id, true ) > 0;
			return array(
				'status'        => 0 === $conflicts && ! $more ? 'complete' : ( 0 === $cleaned ? 'conflict' : 'partial' ),
				'cleaned'       => $cleaned,
				'conflicts'     => $conflicts,
				'next_after_id' => $next_after_id,
				'has_more'      => $more,
			);
		} finally {
			$this->lock->release( $attachment_id );
		}
	}

	/** Processes one bounded persistent bulk cleanup batch.
	 *
	 * @param int $batch_id Persistent cleanup batch ID.
	 * @param int $limit Maximum records to inspect in this call.
	 */
	public function cleanup_batch( int $batch_id, int $limit = 10 ): array {
		$this->hashed_bytes     = 0;
		$this->budget_exhausted = false;
		$repository             = $this->records;
		global $wpdb;
		$batch_lock = 'jio_cb_' . substr( hash( 'sha256', $wpdb->prefix . ':' . $batch_id ), 0, 48 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-scoped lock prevents concurrent workers from advancing the same persistent batch.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $batch_lock ) ) ) {
			return array(
				'status'    => 'batch_locked',
				'processed' => 0,
			);
		}
		try {
			return $this->cleanup_batch_locked( $batch_id, $limit );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases this request's batch advisory lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $batch_lock ) );
		}
	}

	/** Performs one batch while holding its persistent advisory lock.
	 *
	 * @param int $batch_id Persistent cleanup batch ID.
	 * @param int $limit Maximum records.
	 */
	private function cleanup_batch_locked( int $batch_id, int $limit ): array {
		$repository = $this->records;
		$batch      = $repository->cleanup_batch( $batch_id );
		if ( null === $batch ) {
			return array(
				'status'    => 'batch_missing',
				'processed' => 0,
			);
		}
		if ( 'paused' === $batch['status'] || 'cancelled' === $batch['status'] || 'complete' === $batch['status'] ) {
			return array(
				'status'    => (string) $batch['status'],
				'processed' => 0,
			);
		}
		if ( 'pending' === $batch['status'] && ! $repository->transition_cleanup( $batch_id, array( 'pending' ), 'running' ) ) {
			return array(
				'status'    => 'batch_locked',
				'processed' => 0,
			);
		}
		$batch = $repository->cleanup_batch( $batch_id );
		if ( null === $batch || 'running' !== $batch['status'] ) {
			return array(
				'status'    => (string) ( $batch['status'] ?? 'batch_missing' ),
				'processed' => 0,
			);
		}
		$rows = $repository->cleanup_page( (int) $batch['last_derivative_id'], $limit );
		if ( array() === $rows ) {
			$repository->complete_cleanup( $batch_id );
			return array(
				'status'    => 'complete',
				'processed' => 0,
			);
		}
		$processed = 0;
		foreach ( $rows as $record ) {
			$attachment_id = (int) $record['attachment_id'];
			$result        = $this->cleanup_record( $record );
			$repository->advance_cleanup( $batch_id, (int) $record['id'], 'cleaned' === $result, 'cleaned' !== $result );
			++$processed;
		}
		return array(
			'status'    => 'running',
			'processed' => $processed,
		);
	}

	/** Imports trusted legacy postmeta for a bounded page of image attachments.
	 *
	 * @param int $after_attachment_id Keyset cursor.
	 * @param int $limit Maximum attachments to inspect.
	 */
	public function scan_legacy_batch( int $after_attachment_id, int $limit = 10 ): array {
		global $wpdb;
		$this->hashed_bytes     = 0;
		$this->budget_exhausted = false;
		$limit                  = max( 1, min( 50, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This bounded keyset read must avoid loading the whole Media Library or stale object-cache results.
		$ids      = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png') ORDER BY ID ASC LIMIT %d",
				max( 0, $after_attachment_id ),
				$limit
			)
		);
		$imported = 0;
		$scanned  = 0;
		$last_id  = max( 0, $after_attachment_id );
		foreach ( (array) $ids as $id ) {
			$before = $this->records->count_for_attachment( (int) $id );
			$this->import_legacy_records( (int) $id );
			if ( $this->budget_exhausted ) {
				break;
			}
			$imported += max( 0, $this->records->count_for_attachment( (int) $id ) - $before );
			$last_id   = (int) $id;
			++$scanned;
		}
		return array(
			'scanned'      => $scanned,
			'last_id'      => $last_id,
			'imported'     => $imported,
			'complete'     => ! $this->budget_exhausted && count( (array) $ids ) < $limit,
			'bytes_hashed' => $this->hashed_bytes,
		);
	}

	/** Returns the repository for bounded inventory and batch controls. */
	public function repository(): DerivativeRepository {
		return $this->records;
	}

	/** Validates record paths, current metadata references, and both stored digests.
	 *
	 * @param array<string, mixed> $record Trusted registry record.
	 * @param bool                 $persist_status Whether to persist the observed state.
	 */
	private function inspect_record( array $record, bool $persist_status ): array {
		$current_target  = $this->targets->target( (int) $record['attachment_id'], (string) $record['variant_key'] );
		$source_path     = $this->resolve_owned_path( (string) $record['source_relative_path'] );
		$derivative_path = $this->resolve_owned_path( (string) $record['derivative_relative_path'] );
		$metadata_hash   = $this->metadata_hash( (int) $record['attachment_id'] );
		$source_hash     = null;
		$derivative_hash = null;
		$status          = 'valid';
		if ( null === $current_target || null === $source_path ) {
			$status = 'source_missing';
		} elseif ( ! hash_equals( (string) $record['source_relative_path'], $current_target['relative_path'] ) || ! hash_equals( (string) $record['source_fingerprint'], $current_target['fingerprint'] ) || ! hash_equals( (string) $record['source_mime'], $current_target['mime'] ) || ! hash_equals( (string) $record['metadata_hash'], $metadata_hash ) ) {
			$status = 'source_changed';
		} elseif ( ! hash_equals( $this->expected_derivative_path( (string) $record['source_relative_path'], (string) $record['output_format'], (string) $record['source_mime'] ), (string) $record['derivative_relative_path'] ) ) {
			$status = 'ownership_conflict';
		} elseif ( null === $derivative_path ) {
			$status = 'derivative_missing';
		} elseif ( ( (int) $record['source_bytes'] + (int) $record['output_bytes'] ) > ( self::MAX_HASH_BYTES_PER_CALL - $this->hashed_bytes ) ) {
			$status = 'verification_deferred';
		} else {
			$source_hash     = $this->hash_budgeted( $source_path );
			$derivative_hash = $this->hash_budgeted( $derivative_path );
			if ( null === $source_hash || null === $derivative_hash ) {
				$status = 'verification_deferred';
			} elseif ( ! hash_equals( (string) $record['source_sha256'], $source_hash['sha256'] ) ) {
				$status = 'source_changed';
			} elseif ( ! hash_equals( (string) $record['derivative_sha256'], $derivative_hash['sha256'] ) || $this->format_mime( (string) $record['output_format'], (string) $record['source_mime'] ) !== wp_get_image_mime( $derivative_path ) ) {
				$status = 'derivative_corrupted';
			}
		}
		if ( $persist_status ) {
			$lifecycle = 'cleaned' === $record['lifecycle_status'] ? 'cleaned' : ( 'valid' === $status ? 'active' : 'conflict' );
			$this->records->set_status( (int) $record['id'], $status, $lifecycle );
		}
		$record['integrity_status']    = $status;
		$record['source_path']         = null === $source_path ? '' : $source_path;
		$record['derivative_path']     = null === $derivative_path ? '' : $derivative_path;
		$record['source_identity']     = $source_hash ?? array();
		$record['derivative_identity'] = $derivative_hash ?? array();
		return $record;
	}

	/** Hashes a file within the current service operation's cumulative byte budget.
	 *
	 * @param string $path Confined local file path.
	 */
	private function hash_budgeted( string $path ): ?array {
		$remaining = self::MAX_HASH_BYTES_PER_CALL - $this->hashed_bytes;
		$size      = filesize( $path );
		if ( $remaining < 1 || ( false !== $size && $size > $remaining && $size <= IntegrityHasher::MAX_BYTES ) ) {
			$this->budget_exhausted = true;
			return null;
		}
		$result = $this->hasher->hash_file( $path, min( IntegrityHasher::MAX_BYTES, $remaining ) );
		if ( null !== $result ) {
			$this->hashed_bytes += $result['bytes'];
		}
		return $result;
	}

	/** Rechecks the identity observed during hashing before unlinking the exact confined file.
	 *
	 * @param string               $path Confined local file path.
	 * @param array<string, mixed> $identity Identity returned by the stream hasher.
	 */
	private function same_identity( string $path, array $identity ): bool {
		if ( is_link( $path ) || ! is_file( $path ) || ! isset( $identity['dev'], $identity['ino'], $identity['size'], $identity['mtime'] ) ) {
			return false;
		}
		$stat = lstat( $path );
		return false !== $stat && (int) $stat['dev'] === (int) $identity['dev'] && (int) $stat['ino'] === (int) $identity['ino'] && (int) $stat['size'] === (int) $identity['size'] && (int) $stat['mtime'] === (int) $identity['mtime'];
	}

	/** Removes one bulk-selected derivative only after acquiring its attachment lock and rechecking it.
	 *
	 * @param array<string, mixed> $record Trusted registry record.
	 */
	private function cleanup_record( array $record ): string {
		$attachment_id = (int) $record['attachment_id'];
		if ( ! $this->lock->acquire( $attachment_id ) ) {
			$this->records->audit( $attachment_id, (int) $record['id'], 'bulk_cleanup', 'conflict', array( 'reason' => 'operation_locked' ) );
			return 'conflict';
		}
		try {
			if ( $this->records->has_active_optimization( $attachment_id ) ) {
				$this->records->audit( $attachment_id, (int) $record['id'], 'bulk_cleanup', 'conflict', array( 'reason' => 'queue_active' ) );
				return 'conflict';
			}
			$checked = $this->inspect_record( $record, true );
			if ( 'valid' !== $checked['integrity_status'] || ! $this->records->set_lifecycle( (int) $record['id'], 'active', 'removing' ) ) {
				$this->records->audit( $attachment_id, (int) $record['id'], 'bulk_cleanup', 'conflict', array( 'reason' => $checked['integrity_status'] ) );
				return 'conflict';
			}
			$source_path = $this->resolve_owned_path( (string) $record['source_relative_path'] );
			$path        = $this->resolve_owned_path( (string) $record['derivative_relative_path'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- The path is registry-owned and rechecked against canonical uploads confinement and SHA-256 immediately before deletion.
			if ( null === $source_path || null === $path || ! $this->same_identity( $source_path, $checked['source_identity'] ?? array() ) || ! $this->same_identity( $path, $checked['derivative_identity'] ?? array() ) || ! unlink( $path ) ) {
				$this->records->set_status( (int) $record['id'], 'conflict', 'conflict' );
				$this->records->audit( $attachment_id, (int) $record['id'], 'bulk_cleanup', 'conflict', array( 'reason' => 'file_changed_or_unlink_failed' ) );
				return 'conflict';
			}
			$this->records->set_status( (int) $record['id'], 'cleaned', 'cleaned' );
			$this->remove_owned_postmeta( $attachment_id, $record );
			$this->records->audit( $attachment_id, (int) $record['id'], 'bulk_cleanup', 'cleaned', array( 'bytes' => (int) $record['output_bytes'] ) );
			return 'cleaned';
		} finally {
			$this->lock->release( $attachment_id );
		}
	}

	/** Imports only legacy plugin postmeta whose source variant and expected destination still agree.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool Whether more legacy entries remain after the hash budget was reached.
	 */
	private function import_legacy_records( int $attachment_id ): bool {
		$targets  = $this->targets->targets( $attachment_id );
		$all_meta = get_post_meta( $attachment_id );
		foreach ( $all_meta as $key => $values ) {
			if ( ! str_starts_with( (string) $key, '_jio_derivative_' ) || ! isset( $values[0] ) || ! is_array( maybe_unserialize( $values[0] ) ) ) {
				continue;
			}
			$legacy  = maybe_unserialize( $values[0] );
			$variant = '';
			foreach ( $targets as $candidate_key => $target ) {
				if ( hash_equals( (string) ( $legacy['source_fingerprint'] ?? '' ), $target['fingerprint'] ) && hash_equals( (string) ( $legacy['source_relative_path'] ?? '' ), $target['relative_path'] ) ) {
					$variant = $candidate_key;
					break;
				}
			}
			if ( '' === $variant ) {
				continue;
			}
			$format = (string) ( $legacy['output_format'] ?? '' );
			if ( ! in_array( $format, array( 'source', 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ), true ) ) {
				continue;
			}
			if ( $this->records->has_variant_format( $attachment_id, $variant, $format, $targets[ $variant ]['fingerprint'] ) ) {
				continue;
			}
			$expected = $this->expected_derivative_path( $targets[ $variant ]['relative_path'], $format, $targets[ $variant ]['mime'] );
			if ( ! hash_equals( $expected, (string) ( $legacy['derivative_relative_path'] ?? '' ) ) ) {
				continue;
			}
			$source_path     = $targets[ $variant ]['source_path'];
			$derivative_path = $this->resolve_owned_path( $expected );
			$source_hash     = $this->hash_budgeted( $source_path );
			$derivative_hash = null === $derivative_path ? null : $this->hash_budgeted( $derivative_path );
			if ( null === $source_hash || null === $derivative_hash ) {
				if ( $this->budget_exhausted ) {
					return true;
				}
				continue;
			}
			$this->records->record(
				array(
					'attachment_id'            => $attachment_id,
					'variant_key'              => $variant,
					'source_relative_path'     => $targets[ $variant ]['relative_path'],
					'source_mime'              => $targets[ $variant ]['mime'],
					'source_bytes'             => $source_hash['bytes'],
					'source_sha256'            => $source_hash['sha256'],
					'source_fingerprint'       => $targets[ $variant ]['fingerprint'],
					'metadata_hash'            => $this->metadata_hash( $attachment_id ),
					'derivative_relative_path' => $expected,
					'output_format'            => $format,
					'derivative_sha256'        => $derivative_hash['sha256'],
					'input_bytes'              => (int) ( $legacy['input_bytes'] ?? $source_hash['bytes'] ),
					'output_bytes'             => $derivative_hash['bytes'],
					'encoder_id'               => 'legacy',
					'encoder_parameters'       => '{}',
				)
			);
		}
		return $this->budget_exhausted;
	}

	/** Resolves only safe relative paths under the current non-symlink uploads root.
	 *
	 * @param string $relative Uploads-relative path from the registry.
	 */
	private function resolve_owned_path( string $relative ): ?string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || is_link( $uploads['basedir'] ) || str_contains( $relative, "\0" ) || str_contains( $relative, '\\' ) || str_starts_with( $relative, '/' ) || str_contains( $relative, ':' ) ) {
			return null;
		}
		$parts = explode( '/', $relative );
		if ( array() === $parts || in_array( '', $parts, true ) || in_array( '.', $parts, true ) || in_array( '..', $parts, true ) ) {
			return null;
		}
		$root = realpath( $uploads['basedir'] );
		if ( false === $root ) {
			return null;
		}
		$path = $root;
		foreach ( $parts as $part ) {
			$path .= DIRECTORY_SEPARATOR . $part;
			if ( is_link( $path ) ) {
				return null;
			}
		}
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! str_starts_with( wp_normalize_path( $real ), rtrim( wp_normalize_path( $root ), '/' ) . '/' ) ) {
			return null;
		}
		return $real;
	}

	/** Computes the only derivative name permitted for a registered source/format pair.
	 *
	 * @param string $source_relative_path Trusted source path.
	 * @param string $format Registered format.
	 * @param string $source_mime Source MIME type.
	 */
	private function expected_derivative_path( string $source_relative_path, string $format, string $source_mime ): string {
		$extension = match ( $format ) {
			'image/webp' => 'webp',
			'image/avif' => 'avif',
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'source' => 'image/jpeg' === $source_mime ? 'jpg' : 'png',
			default => '',
		};
		$directory = pathinfo( $source_relative_path, PATHINFO_DIRNAME );
		$filename  = pathinfo( $source_relative_path, PATHINFO_FILENAME ) . '.jio.' . $extension;
		return ( '.' === $directory ? '' : $directory . '/' ) . $filename;
	}

	/** Returns the expected output MIME type for a stored format.
	 *
	 * @param string $format Registered format.
	 * @param string $source_mime Original MIME type.
	 */
	private function format_mime( string $format, string $source_mime ): string {
		return 'source' === $format ? $source_mime : $format;
	}

	/** Hashes only core attachment references used by the derivative's source relationship.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function metadata_hash( int $attachment_id ): string {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'attached_file' => $attached,
					'metadata'      => is_array( $metadata ) ? $metadata : array(),
				)
			)
		);
	}

	/** Removes only the legacy JIO meta key derived from the known record identity.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $record Registry record.
	 */
	private function remove_owned_postmeta( int $attachment_id, array $record ): void {
		$key    = '_jio_derivative_' . substr( hash( 'sha256', (string) $record['source_relative_path'] . '|' . (string) $record['output_format'] . '|' . (string) $record['source_fingerprint'] ), 0, 32 );
		$legacy = get_post_meta( $attachment_id, $key, true );
		if ( is_array( $legacy ) && hash_equals( (string) $record['derivative_relative_path'], (string) ( $legacy['derivative_relative_path'] ?? '' ) ) ) {
			delete_post_meta( $attachment_id, $key );
		}
	}
}
