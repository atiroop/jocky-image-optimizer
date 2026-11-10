<?php
/**
 * WP-CLI recovery and derivative maintenance commands.
 *
 * @package Jocky\ImageOptimizer\Cli
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Cli;

use Jocky\ImageOptimizer\Recovery\DerivativeRepository;
use Jocky\ImageOptimizer\Recovery\RecoveryService;
use Jocky\ImageOptimizer\Recovery\RecoveryWorker;

/** Provides bounded, attachment-ID-only recovery operations. */
final class RecoveryCommand extends \WP_CLI_Command {

	/** Creates the command service.
	 *
	 * @param RecoveryService $service Recovery service.
	 * @param RecoveryWorker  $worker Persistent cleanup worker.
	 */
	public function __construct( private RecoveryService $service, private RecoveryWorker $worker ) {}

	/** Shows persisted registry and batch status.
	 *
	 * ## EXAMPLES
	 *     wp jio recovery status
	 */
	public function status(): void {
		$repo = $this->service->repository();
		\WP_CLI::line( 'Registered derivative states:' );
		foreach ( $repo->totals() as $row ) {
			\WP_CLI::line( sprintf( '%s / %s: %d records, %s bytes', $row['lifecycle_status'], $row['validation_status'], (int) $row['records'], number_format_i18n( (int) $row['bytes'] ) ) );
		}
		$batch = $repo->latest_cleanup_batch();
		\WP_CLI::line( null === $batch ? 'Cleanup batch: none' : sprintf( 'Cleanup batch #%d: %s; %d/%d processed; %d cleaned; %d conflicts', (int) $batch['id'], $batch['status'], (int) $batch['processed_count'], (int) $batch['eligible_count'], (int) $batch['cleaned_count'], (int) $batch['conflict_count'] ) );
	}

	/** Verifies original and generated derivative integrity for one attachment.
	 *
	 * ## OPTIONS
	 * --attachment-id=<id>
	 * [--after-id=<id>]
	 *
	 * ## EXAMPLES
	 *     wp jio recovery verify --attachment-id=123
	 *
	 * @param array<int, mixed>    $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function verify( array $args, array $assoc_args ): void {
		$id     = $this->attachment_id( $assoc_args );
		$after  = isset( $assoc_args['after-id'] ) ? max( 0, (int) $assoc_args['after-id'] ) : 0;
		$result = $this->service->verify_attachment( $id, $after );
		\WP_CLI::line( sprintf( 'Attachment %d integrity: %s', $id, $result['status'] ) );
		foreach ( $result['records'] as $record ) {
			\WP_CLI::line( sprintf( '#%d %s %s: %s (%s)', (int) $record['id'], $record['variant_key'], $record['output_format'], $record['integrity_status'], $record['lifecycle_status'] ) );
		}
		if ( ! in_array( $result['status'], array( 'valid', 'no_derivatives' ), true ) ) {
			\WP_CLI::error( 'Integrity verification found a recovery issue.' );
		}
		if ( ! empty( $result['has_more'] ) ) {
			\WP_CLI::line( sprintf( 'Continue with --after-id=%d.', (int) $result['next_after_id'] ) );
		}
		\WP_CLI::success( 'Verification complete; no files were changed.' );
	}

	/** Previews eligible derivative cleanup for one attachment without changing files.
	 *
	 * ## OPTIONS
	 * --attachment-id=<id>
	 * [--after-id=<id>]
	 *
	 * @param array<int, mixed>    $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function preview( array $args, array $assoc_args ): void {
		$id    = $this->attachment_id( $assoc_args );
		$after = isset( $assoc_args['after-id'] ) ? max( 0, (int) $assoc_args['after-id'] ) : 0;
		$plan  = $this->service->preview_attachment( $id, $after );
		\WP_CLI::line( sprintf( 'Attachment %d cleanup status: %s', $id, $plan['status'] ) );
		\WP_CLI::line( sprintf( 'Eligible files: %d; bytes: %s; conflicts: %d', count( $plan['eligible'] ), number_format_i18n( (int) $plan['bytes'] ), count( $plan['conflicts'] ) ) );
		foreach ( array_merge( $plan['eligible'], $plan['conflicts'] ) as $record ) {
			\WP_CLI::line( sprintf( '#%d %s %s: %s, %s bytes', (int) $record['id'], $record['variant_key'], $record['output_format'], $record['integrity_status'], (int) $record['output_bytes'] ) );
		}
		if ( ! empty( $plan['has_more'] ) ) {
			\WP_CLI::line( sprintf( 'Continue with --after-id=%d.', (int) $plan['next_after_id'] ) );
		}
		if ( 'ready' !== $plan['status'] ) {
			\WP_CLI::error( 'Cleanup preview is blocked by a conflict.' );
		}
	}

	/** Deletes validated plugin-owned derivatives for one attachment; originals are retained.
	 *
	 * ## OPTIONS
	 * --attachment-id=<id>
	 * [--after-id=<id>]
	 * [--confirm]
	 *
	 * ## EXAMPLES
	 *     wp jio recovery cleanup --attachment-id=123 --confirm
	 *
	 * @param array<int, mixed>    $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		$id    = $this->attachment_id( $assoc_args );
		$after = isset( $assoc_args['after-id'] ) ? max( 0, (int) $assoc_args['after-id'] ) : 0;
		if ( ! isset( $assoc_args['confirm'] ) ) {
			\WP_CLI::error( 'Destructive cleanup requires --confirm.' );
		}
		$result = $this->service->cleanup_attachment( $id, true, $after );
		\WP_CLI::line( sprintf( 'Cleanup status: %s; removed %d derivatives; conflicts %d.', $result['status'], $result['cleaned'], $result['conflicts'] ) );
		if ( ! empty( $result['has_more'] ) ) {
			\WP_CLI::line( sprintf( 'Continue with --after-id=%d --confirm.', (int) $result['next_after_id'] ) );
		}
		if ( 'complete' !== $result['status'] ) {
			\WP_CLI::error( 'Cleanup did not complete without conflicts.' );
		}
		\WP_CLI::success( 'Validated derivatives removed; original files and core attachment metadata were retained.' );
	}

	/** Indexes a bounded page of legacy JIO derivative metadata.
	 *
	 * ## OPTIONS
	 * [--batch-size=<n>] [--after-id=<id>]
	 *
	 * @param array<int, mixed>    $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function scan( array $args, array $assoc_args ): void {
		$limit = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 10;
		$after = isset( $assoc_args['after-id'] ) ? (int) $assoc_args['after-id'] : 0;
		if ( $limit < 1 || $limit > 50 || $after < 0 ) {
			\WP_CLI::error( 'Use --batch-size from 1 to 50 and a non-negative --after-id.' );
		}
		$result = $this->service->scan_legacy_batch( $after, $limit );
		\WP_CLI::line( sprintf( 'Scanned %d attachments; imported %d owned derivative records; next after-id=%d; complete=%s.', $result['scanned'], $result['imported'], $result['last_id'], $result['complete'] ? 'yes' : 'no' ) );
	}

	/** Previews or controls a persistent bounded bulk cleanup batch.
	 *
	 * ## OPTIONS
	 * --action=<preview|start|run|pause|resume|cancel|retry>
	 * [--confirm] [--batch-id=<id>] [--batch-size=<n>]
	 *
	 * @param array<int, mixed>    $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function bulk( array $args, array $assoc_args ): void {
		$action = isset( $assoc_args['action'] ) ? sanitize_key( (string) $assoc_args['action'] ) : 'preview';
		$repo   = $this->service->repository();
		$batch  = isset( $assoc_args['batch-id'] ) ? $repo->cleanup_batch( (int) $assoc_args['batch-id'] ) : $repo->latest_cleanup_batch();
		switch ( $action ) {
			case 'preview':
				$summary = $repo->active_summary();
				\WP_CLI::line( sprintf( 'Registered active candidates: %d files across %d attachments; %s bytes. Each source and derivative is reverified before removal.', $summary['records'], $summary['attachments'], number_format_i18n( $summary['bytes'] ) ) );
				return;
			case 'start':
				if ( ! isset( $assoc_args['confirm'] ) ) {
					\WP_CLI::error( 'Starting destructive bulk cleanup requires --confirm.' );
				}
				if ( null !== $batch && in_array( $batch['status'], array( 'pending', 'running', 'paused' ), true ) ) {
					\WP_CLI::error( 'An active cleanup batch already exists.' );
				}
				$summary = $repo->active_summary();
				$id      = $repo->create_cleanup_batch( $summary['records'], get_current_user_id() );
				if ( $id < 1 ) {
					\WP_CLI::error( 'Unable to create the cleanup batch.' );
				}
				$this->worker->schedule( $id );
				\WP_CLI::success( sprintf( 'Started cleanup batch #%d; it will process at most 10 records per worker tick.', $id ) );
				return;
			case 'run':
				if ( null === $batch ) {
					\WP_CLI::error( 'No cleanup batch exists.' );
				}
				$size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 10;
				if ( $size < 1 || $size > 10 ) {
					\WP_CLI::error( 'Use a cleanup batch size from 1 to 10.' );
				}
				$result = $this->service->cleanup_batch( (int) $batch['id'], $size );
				\WP_CLI::line( sprintf( 'Batch #%d: %s; processed %d.', (int) $batch['id'], $result['status'], $result['processed'] ) );
				return;
			case 'pause':
				$this->transition_batch( $repo, $batch, array( 'pending', 'running' ), 'paused' );
				return;
			case 'resume':
				$this->transition_batch( $repo, $batch, array( 'paused' ), 'pending' );
				$this->worker->schedule( (int) $batch['id'] );
				return;
			case 'cancel':
				$this->transition_batch( $repo, $batch, array( 'pending', 'paused' ), 'cancelled' );
				return;
			case 'retry':
				$count = $repo->retry_conflicts();
				\WP_CLI::success( sprintf( 'Requeued %d conflicted registry entries; a fresh preview is required.', $count ) );
				return;
			default:
				\WP_CLI::error( 'Unknown bulk action.' );
		}
	}

	/** Validates the required numeric attachment identifier.
	 *
	 * @param array<string, mixed> $assoc_args Named command arguments.
	 */
	private function attachment_id( array $assoc_args ): int {
		$id = isset( $assoc_args['attachment-id'] ) ? (int) $assoc_args['attachment-id'] : 0;
		if ( $id < 1 || 'attachment' !== get_post_type( $id ) ) {
			\WP_CLI::error( 'A valid --attachment-id is required.' );
		}
		return $id;
	}

	/** Applies an allow-listed persistent batch state transition.
	 *
	 * @param DerivativeRepository      $repo Registry repository.
	 * @param array<string, mixed>|null $batch Current cleanup batch.
	 * @param string[]                  $from Allow-listed prior states.
	 * @param string                    $to New state.
	 */
	private function transition_batch( DerivativeRepository $repo, ?array $batch, array $from, string $to ): void {
		if ( null === $batch || ! $repo->set_batch_status( (int) $batch['id'], $from, $to ) ) {
			\WP_CLI::error( 'Cleanup batch state could not be changed.' );
		}
		\WP_CLI::success( sprintf( 'Cleanup batch #%d is now %s.', (int) $batch['id'], $to ) );
	}
}
