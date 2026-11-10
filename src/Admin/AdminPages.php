<?php
/**
 * WordPress admin pages for settings and runtime diagnostics.
 *
 * @package Jocky\ImageOptimizer
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Admin;

use Jocky\ImageOptimizer\CapabilityDetector;
use Jocky\ImageOptimizer\Queue\BulkScanner;
use Jocky\ImageOptimizer\Queue\JobRepository;
use Jocky\ImageOptimizer\Queue\QueueState;
use Jocky\ImageOptimizer\Queue\QueueWorker;
use Jocky\ImageOptimizer\Recovery\DerivativeRepository;
use Jocky\ImageOptimizer\Recovery\RecoveryService;
use Jocky\ImageOptimizer\Recovery\RecoveryWorker;
use Jocky\ImageOptimizer\Plugin;
use Jocky\ImageOptimizer\Settings;

/**
 * Registers and renders the plugin's administrative pages.
 */
final class AdminPages {
	/** Integrity and cleanup operations.
	 *
	 * @var RecoveryService
	 */
	private RecoveryService $recovery;
	/** Bounded background cleanup scheduler.
	 *
	 * @var RecoveryWorker
	 */
	private RecoveryWorker $recovery_worker;

	/**
	 * Creates the admin page service.
	 *
	 * @param CapabilityDetector   $capabilities Runtime capability detector.
	 * @param JobRepository|null   $jobs         Persistent queue.
	 * @param BulkScanner|null     $bulk_scanner Paginated media scanner.
	 * @param QueueWorker|null     $worker       Queue worker.
	 * @param RecoveryService|null $recovery Recovery operations.
	 * @param RecoveryWorker|null  $recovery_worker Cleanup scheduler.
	 */
	public function __construct( private CapabilityDetector $capabilities, private ?JobRepository $jobs = null, private ?BulkScanner $bulk_scanner = null, private ?QueueWorker $worker = null, ?RecoveryService $recovery = null, ?RecoveryWorker $recovery_worker = null ) {
		$this->recovery        = $recovery ?? new RecoveryService();
		$this->recovery_worker = $recovery_worker ?? new RecoveryWorker( $this->recovery );
	}

	/** Registers the admin hooks. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_jio_recovery_attachment', array( $this, 'handle_attachment_cleanup' ) );
		add_action( 'admin_post_jio_recovery_action', array( $this, 'handle_recovery_action' ) );
		if ( null !== $this->jobs && null !== $this->bulk_scanner && null !== $this->worker ) {
			add_action( 'admin_post_jio_queue_action', array( $this, 'handle_queue_action' ) );
			add_action( 'wp_ajax_jio_queue_stats', array( $this, 'ajax_queue_stats' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_script' ) );
		}
	}

	/** Adds the Dashboard and Settings menu pages. */
	public function add_pages(): void {
		add_menu_page(
			esc_html__( 'Jocky Image Optimizer', 'jocky-image-optimizer' ),
			esc_html__( 'Image Optimizer', 'jocky-image-optimizer' ),
			'manage_options',
			'jocky-image-optimizer',
			array( $this, 'render_dashboard' ),
			'dashicons-format-image'
		);
		add_submenu_page( 'jocky-image-optimizer', esc_html__( 'Dashboard', 'jocky-image-optimizer' ), esc_html__( 'Dashboard', 'jocky-image-optimizer' ), 'manage_options', 'jocky-image-optimizer', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'jocky-image-optimizer', esc_html__( 'Settings', 'jocky-image-optimizer' ), esc_html__( 'Settings', 'jocky-image-optimizer' ), 'manage_options', 'jocky-image-optimizer-settings', array( $this, 'render_settings' ) );
		add_submenu_page( 'jocky-image-optimizer', esc_html__( 'Optimization History', 'jocky-image-optimizer' ), esc_html__( 'History', 'jocky-image-optimizer' ), 'manage_options', 'jio-history', array( $this, 'render_history' ) );
		add_submenu_page( 'jocky-image-optimizer', esc_html__( 'Recovery', 'jocky-image-optimizer' ), esc_html__( 'Recovery', 'jocky-image-optimizer' ), 'manage_options', 'jio-recovery', array( $this, 'render_recovery' ) );
	}

	/** Renders paginated persisted derivative history. */
	public function render_history(): void {
		if ( ! $this->authorize() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter; no state change is performed.
		$page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$rows = ( new DerivativeRepository() )->history( $page, 25 );
		echo '<div class="wrap"><h1>' . esc_html__( 'Optimization History', 'jocky-image-optimizer' ) . '</h1><p>' . esc_html__( 'Savings compare an original with separate retained derivatives; they do not represent physical disk savings. Integrity is shown as the latest recorded verification state.', 'jocky-image-optimizer' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Attachment', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Variant', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Original bytes', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Derivative bytes', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Change', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Format', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Created', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Status / integrity', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Recovery', 'jocky-image-optimizer' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$id        = (int) $row['attachment_id'];
			$input     = (int) $row['input_bytes'];
			$output    = (int) $row['output_bytes'];
			$change    = 0 < $input ? round( ( ( $input - $output ) / $input ) * 100, 2 ) : 0;
			$name      = get_the_title( $id );
			$status    = (string) $row['job_status'];
			$integrity = (string) ( $row['validation_status'] ?? 'not_verified' );
			if ( 'completed' === $status ) {
				$status    = null === $row['lifecycle_status'] ? 'missing_derivative' : ( 'cleaned' === $row['lifecycle_status'] ? 'cleaned' : ( 'active' === $row['lifecycle_status'] ? 'completed' : (string) $row['lifecycle_status'] ) );
				$integrity = null === $row['validation_status'] ? 'missing_derivative' : (string) $row['validation_status'];
			}
			$link = add_query_arg(
				array(
					'page'          => 'jio-recovery',
					'attachment_id' => $id,
				),
				admin_url( 'admin.php' )
			);
			printf( '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s%%</td><td>%6$s</td><td>%7$s</td><td>%8$s / %9$s</td><td><a href="%10$s">%11$s</a></td></tr>', esc_html( false !== $name ? $name : __( 'Deleted attachment', 'jocky-image-optimizer' ) ), esc_html( (string) $row['variant_key'] ), esc_html( number_format_i18n( $input ) ), esc_html( number_format_i18n( $output ) ), esc_html( (string) $change ), esc_html( (string) $row['output_format'] ), esc_html( (string) $row['created_at'] ), esc_html( $status ), esc_html( $integrity ), esc_url( $link ), esc_html__( 'Verify / recover', 'jocky-image-optimizer' ) );
		}
		if ( array() === $rows ) {
			echo '<tr><td colspan="9">' . esc_html__( 'No derivative history is recorded yet.', 'jocky-image-optimizer' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$this->render_pagination( 'jio-history', $page, count( $rows ) );
		echo '</div>';
	}

	/** Renders dry-run inventory and per-attachment recovery preview. */
	public function render_recovery(): void {
		if ( ! $this->authorize() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only attachment selector; destructive operations use attachment-specific POST nonces.
		$id = isset( $_GET['attachment_id'] ) ? absint( wp_unslash( $_GET['attachment_id'] ) ) : 0;
		echo '<div class="wrap"><h1>' . esc_html__( 'Recovery and Derivative Lifecycle', 'jocky-image-optimizer' ) . '</h1>';
		echo '<p>' . esc_html__( 'Original files and core attachment metadata remain authoritative. This plugin removes only registered generated derivatives. If an original is missing or corrupt, restore it from UpdraftPlus or another verified external backup; derivatives are never used to recreate originals.', 'jocky-image-optimizer' ) . '</p>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status banner; value is sanitized and no state change occurs.
		if ( isset( $_GET['jio_result'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Status notice is read-only and sanitized before output.
			$message = sanitize_key( wp_unslash( $_GET['jio_result'] ) );
			echo '<div class="notice notice-info"><p>' . esc_html( $message ) . '</p></div>';
		}
		if ( $id > 0 ) {
			if ( 'attachment' !== get_post_type( $id ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'The requested attachment is invalid.', 'jocky-image-optimizer' ) . '</p></div>';
			} else {
				$this->render_attachment_recovery( $id );
			}
		}
		$this->render_bulk_recovery();
		$this->render_recovery_events();
		echo '</div>';
	}

	/** Renders bounded, persistent recovery audit history. */
	private function render_recovery_events(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter; no state change is performed.
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$events = $this->recovery->repository()->events( $page, 25 );
		echo '<h2>' . esc_html__( 'Recovery audit history', 'jocky-image-optimizer' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date (UTC)', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Attachment', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Derivative', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Action', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Result', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Details', 'jocky-image-optimizer' ) . '</th></tr></thead><tbody>';
		foreach ( $events as $event ) {
			printf(
				'<tr><td>%1$s</td><td>%2$d</td><td>%3$d</td><td>%4$s</td><td>%5$s</td><td><code>%6$s</code></td></tr>',
				esc_html( (string) $event['created_at'] ),
				(int) $event['attachment_id'],
				(int) $event['derivative_id'],
				esc_html( (string) $event['action'] ),
				esc_html( (string) $event['status'] ),
				esc_html( (string) $event['details'] )
			);
		}
		if ( array() === $events ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No recovery events have been recorded.', 'jocky-image-optimizer' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$this->render_pagination( 'jio-recovery', $page, count( $events ) );
	}

	/** Handles explicitly confirmed one-attachment derivative cleanup. */
	public function handle_attachment_cleanup(): void {
		$this->require_recovery_admin();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sanitized ID is used to select the attachment-specific nonce verified immediately below.
		$id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Cursor is covered by the attachment-specific POST nonce and only selects a bounded database page.
		$after_id = isset( $_POST['after_id'] ) ? absint( wp_unslash( $_POST['after_id'] ) ) : 0;
		check_admin_referer( 'jio_recovery_cleanup_' . $id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This confirmation flag is checked after the attachment-specific nonce above.
		if ( $id < 1 || 'attachment' !== get_post_type( $id ) || empty( $_POST['confirm_cleanup'] ) ) {
			wp_die( esc_html__( 'Confirm cleanup for a valid attachment.', 'jocky-image-optimizer' ), '', array( 'response' => 400 ) );
		}
		$result = $this->recovery->cleanup_attachment( $id, true, $after_id );
		$url    = add_query_arg(
			array(
				'page'          => 'jio-recovery',
				'attachment_id' => $id,
				'after_id'      => (int) ( $result['next_after_id'] ?? $after_id ),
				'jio_result'    => sanitize_key( $result['status'] ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/** Handles bounded scan, bulk cleanup, and persistent batch controls. */
	public function handle_recovery_action(): void {
		$this->require_recovery_admin();
		check_admin_referer( 'jio_recovery_action' );
		$action = isset( $_POST['recovery_action'] ) && is_string( $_POST['recovery_action'] ) ? sanitize_key( wp_unslash( $_POST['recovery_action'] ) ) : '';
		$repo   = $this->recovery->repository();
		$batch  = $repo->latest_cleanup_batch();
		$result = 'ok';
		switch ( $action ) {
			case 'scan_legacy':
				$cursor = (int) get_option( 'jio_recovery_scan_cursor', 0 );
				$scan   = $this->recovery->scan_legacy_batch( $cursor, 10 );
				update_option( 'jio_recovery_scan_cursor', $scan['complete'] ? 0 : $scan['last_id'], false );
				$result = 'scan_' . ( $scan['complete'] ? 'complete' : 'continued' );
				break;
			case 'start_bulk':
				if ( empty( $_POST['confirm_bulk'] ) ) {
					wp_die( esc_html__( 'Explicit bulk cleanup confirmation is required.', 'jocky-image-optimizer' ), '', array( 'response' => 400 ) );
				}
				if ( null !== $batch && in_array( $batch['status'], array( 'pending', 'running', 'paused' ), true ) ) {
					wp_die( esc_html__( 'A cleanup batch is already active.', 'jocky-image-optimizer' ), '', array( 'response' => 409 ) );
				}
				$summary  = $repo->active_summary();
				$batch_id = $repo->create_cleanup_batch( $summary['records'], get_current_user_id() );
				if ( $batch_id < 1 ) {
					wp_die( esc_html__( 'Unable to create the cleanup batch.', 'jocky-image-optimizer' ), '', array( 'response' => 500 ) );
				}
				$this->recovery_worker->schedule( $batch_id );
				$result = 'bulk_started';
				break;
			case 'pause':
				$result = null !== $batch && $repo->set_batch_status( (int) $batch['id'], array( 'pending', 'running' ), 'paused' ) ? 'paused' : 'state_error';
				break;
			case 'resume':
				$result = null !== $batch && $repo->set_batch_status( (int) $batch['id'], array( 'paused' ), 'pending' ) ? 'resumed' : 'state_error';
				if ( 'resumed' === $result ) {
					$this->recovery_worker->schedule( (int) $batch['id'] );
				}
				break;
			case 'cancel':
				$result = null !== $batch && $repo->set_batch_status( (int) $batch['id'], array( 'pending', 'paused' ), 'cancelled' ) ? 'cancelled' : 'state_error';
				break;
			case 'retry_conflicts':
				$result = 'retried_' . $repo->retry_conflicts();
				break;
			default:
				wp_die( esc_html__( 'The recovery action is not recognized.', 'jocky-image-optimizer' ), '', array( 'response' => 400 ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'jio-recovery',
					'jio_result' => $result,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Renders one attachment's verification and explicit deletion plan.
	 *
	 * @param int $id Attachment ID.
	 */
	private function render_attachment_recovery( int $id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only keyset cursor; destructive operations use the attachment-specific POST nonce.
		$after_id = isset( $_GET['after_id'] ) ? absint( wp_unslash( $_GET['after_id'] ) ) : 0;
		$title    = get_the_title( $id );
		$verify   = $this->recovery->verify_attachment( $id, $after_id );
		$plan     = $this->recovery->preview_attachment( $id, $after_id );
		// translators: 1: attachment title, 2: attachment ID.
		echo '<h2>' . esc_html( sprintf( __( 'Attachment: %1$s (#%2$d)', 'jocky-image-optimizer' ), false !== $title ? $title : __( 'Untitled', 'jocky-image-optimizer' ), $id ) ) . '</h2>';
		// translators: %s: current integrity status.
		echo '<p>' . esc_html( sprintf( __( 'Source and derivative integrity: %s. Original delivery state remains the WordPress attachment and metadata; derivative delivery is not enabled by this plugin.', 'jocky-image-optimizer' ), $verify['status'] ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Variant', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Format', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'State', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Integrity', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Bytes', 'jocky-image-optimizer' ) . '</th></tr></thead><tbody>';
		foreach ( $verify['records'] as $record ) {
			printf( '<tr><td>%1$d</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>', (int) $record['id'], esc_html( (string) $record['variant_key'] ), esc_html( (string) $record['output_format'] ), esc_html( (string) $record['lifecycle_status'] ), esc_html( (string) $record['integrity_status'] ), esc_html( number_format_i18n( (int) $record['output_bytes'] ) ) );
		}
		if ( array() === $verify['records'] ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No verifiable plugin-owned derivatives were found.', 'jocky-image-optimizer' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( 'ready' === $plan['status'] && array() !== $plan['eligible'] ) {
			// translators: 1: derivative file count, 2: total bytes.
			echo '<p>' . esc_html( sprintf( __( 'Preview: %1$d validated derivative files (%2$s bytes) can be removed. Original files and WordPress-generated thumbnails will be retained.', 'jocky-image-optimizer' ), count( $plan['eligible'] ), number_format_i18n( (int) $plan['bytes'] ) ) ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="jio_recovery_attachment"><input type="hidden" name="attachment_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="after_id" value="' . esc_attr( (string) $after_id ) . '">';
			wp_nonce_field( 'jio_recovery_cleanup_' . $id );
			echo '<label><input type="checkbox" name="confirm_cleanup" value="1" required> ' . esc_html__( 'I confirm one bounded cleanup step may remove a validated generated derivative from this page; the action can be repeated for remaining files.', 'jocky-image-optimizer' ) . '</label> ';
			submit_button( __( 'Remove next validated derivative', 'jocky-image-optimizer' ), 'delete', 'submit', false );
			echo '</form>';
		} elseif ( 'ready' !== $plan['status'] ) {
			// translators: %s: stable cleanup blocking status.
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Cleanup is blocked: %s. Inspect the integrity status and queue state before retrying.', 'jocky-image-optimizer' ), $plan['status'] ) ) . '</p></div>';
		}
		if ( ! empty( $plan['has_more'] ) ) {
			$next = add_query_arg(
				array(
					'page'          => 'jio-recovery',
					'attachment_id' => $id,
					'after_id'      => (int) $plan['next_after_id'],
				),
				admin_url( 'admin.php' )
			);
			echo '<p><a class="button" href="' . esc_url( $next ) . '">' . esc_html__( 'Inspect next derivative page', 'jocky-image-optimizer' ) . '</a></p>';
		}
	}

	/** Renders bounded legacy scan and bulk lifecycle controls. */
	private function render_bulk_recovery(): void {
		$repo    = $this->recovery->repository();
		$summary = $repo->active_summary();
		$batch   = $repo->latest_cleanup_batch();
		$cursor  = (int) get_option( 'jio_recovery_scan_cursor', 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only keyset cursor; state changes use a separate POST nonce.
		$derivative_cursor = isset( $_GET['bulk_after_id'] ) ? absint( wp_unslash( $_GET['bulk_after_id'] ) ) : 0;
		$candidates        = $repo->active_page( $derivative_cursor, 10 );
		echo '<h2>' . esc_html__( 'Bulk recovery', 'jocky-image-optimizer' ) . '</h2>';
		// translators: 1: derivative count, 2: attachment count, 3: byte count.
		echo '<p>' . esc_html( sprintf( __( 'Dry-run inventory: %1$d registered derivatives across %2$d attachments, %3$s bytes. This is a candidate count; every file and source is rechecked immediately before deletion. Separate derivatives may increase physical storage while retained.', 'jocky-image-optimizer' ), $summary['records'], $summary['attachments'], number_format_i18n( $summary['bytes'] ) ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Attachment', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Variant', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Format', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Bytes', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Integrity', 'jocky-image-optimizer' ) . '</th></tr></thead><tbody>';
		foreach ( $candidates as $candidate ) {
			$title = get_the_title( (int) $candidate['attachment_id'] );
			printf(
				'<tr><td>%1$s (#%2$d)</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
				esc_html( false !== $title ? $title : __( 'Deleted attachment', 'jocky-image-optimizer' ) ),
				(int) $candidate['attachment_id'],
				esc_html( (string) $candidate['variant_key'] ),
				esc_html( (string) $candidate['output_format'] ),
				esc_html( number_format_i18n( (int) $candidate['output_bytes'] ) ),
				esc_html__( 'Checked at cleanup', 'jocky-image-optimizer' )
			);
		}
		if ( array() === $candidates ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No registered cleanup candidates on this page.', 'jocky-image-optimizer' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( count( $candidates ) === 10 ) {
			$next_candidate = end( $candidates );
			$next_url       = add_query_arg(
				array(
					'page'          => 'jio-recovery',
					'bulk_after_id' => (int) $next_candidate['id'],
				),
				admin_url( 'admin.php' )
			);
			echo '<p><a class="button" href="' . esc_url( $next_url ) . '">' . esc_html__( 'Next candidate page', 'jocky-image-optimizer' ) . '</a></p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="jio_recovery_action">';
		wp_nonce_field( 'jio_recovery_action' );
		// translators: %d: last scanned attachment ID.
		echo '<p>' . esc_html( sprintf( __( 'Legacy inventory cursor: attachment %d. Each scan checks at most 10 attachments.', 'jocky-image-optimizer' ), $cursor ) ) . '</p>';
		$this->recovery_button( 'scan_legacy', __( 'Scan next 10 legacy attachments', 'jocky-image-optimizer' ), 'secondary' );
		if ( null !== $batch ) {
			// translators: 1: batch ID, 2: batch state, 3: processed count, 4: candidate count, 5: removed count, 6: conflict count.
			echo '<p>' . esc_html( sprintf( __( 'Batch #%1$d: %2$s; %3$d/%4$d processed; %5$d removed; %6$d conflicts.', 'jocky-image-optimizer' ), (int) $batch['id'], (string) $batch['status'], (int) $batch['processed_count'], (int) $batch['eligible_count'], (int) $batch['cleaned_count'], (int) $batch['conflict_count'] ) ) . '</p>';
			if ( in_array( $batch['status'], array( 'pending', 'running' ), true ) ) {
				$this->recovery_button( 'pause', __( 'Pause cleanup', 'jocky-image-optimizer' ), 'secondary' );
			}
			if ( 'paused' === $batch['status'] ) {
				$this->recovery_button( 'resume', __( 'Resume cleanup', 'jocky-image-optimizer' ), 'primary' );
			}
			if ( in_array( $batch['status'], array( 'pending', 'paused' ), true ) ) {
				$this->recovery_button( 'cancel', __( 'Cancel pending cleanup', 'jocky-image-optimizer' ), 'delete' );
			}
		}
		echo '</form>';
		if ( null === $batch || ! in_array( $batch['status'], array( 'pending', 'running', 'paused' ), true ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="jio_recovery_action"><input type="hidden" name="recovery_action" value="start_bulk">';
			wp_nonce_field( 'jio_recovery_action' );
			echo '<p><label><input type="checkbox" name="confirm_bulk" value="1" required> ' . esc_html__( 'I explicitly authorize a background batch to remove integrity-verified JIO derivatives; originals and WordPress thumbnails are retained.', 'jocky-image-optimizer' ) . '</label></p>';
			submit_button( __( 'Start bounded cleanup batch', 'jocky-image-optimizer' ), 'delete' );
			echo '</form>';
		}
		if ( null !== $batch && (int) $batch['conflict_count'] > 0 ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="jio_recovery_action"><input type="hidden" name="recovery_action" value="retry_conflicts">';
			wp_nonce_field( 'jio_recovery_action' );
			submit_button( __( 'Recheck conflicted records', 'jocky-image-optimizer' ), 'secondary' );
			echo '</form>';
		}
	}

	/** Adds an allow-listed recovery action button.
	 *
	 * @param string $action Action identifier.
	 * @param string $label Visible label.
	 * @param string $style WordPress button style.
	 */
	private function recovery_button( string $action, string $label, string $style ): void {
		printf( '<button class="button button-%1$s" type="submit" name="recovery_action" value="%2$s">%3$s</button> ', esc_attr( $style ), esc_attr( $action ), esc_html( $label ) );
	}

	/** Renders basic previous/next pagination without loading all media rows.
	 *
	 * @param string $slug Admin page slug.
	 * @param int    $page Current page.
	 * @param int    $row_count Rows returned on the page.
	 */
	private function render_pagination( string $slug, int $page, int $row_count ): void {
		if ( 25 === $row_count ) {
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url(
					add_query_arg(
						array(
							'page'  => $slug,
							'paged' => $page + 1,
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'Next page', 'jocky-image-optimizer' )
			);
		}
		if ( $page > 1 ) {
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url(
					add_query_arg(
						array(
							'page'  => $slug,
							'paged' => $page - 1,
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'Previous page', 'jocky-image-optimizer' )
			);
		}
	}

	/** Requires administrator permissions and keeps recovery POST processing nonces mandatory. */
	private function require_recovery_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage recovery operations.', 'jocky-image-optimizer' ), '', array( 'response' => 403 ) );
		}
	}

	/** Registers and sanitizes the plugin settings option. */
	public function register_settings(): void {
		register_setting(
			'jio_settings_group',
			Plugin::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/** Renders detected runtime capabilities. */
	public function render_dashboard(): void {
		if ( ! $this->authorize() ) {
			return;
		}
		$capabilities = $this->capabilities->detect();
		echo '<div class="wrap"><h1>' . esc_html__( 'Jocky Image Optimizer', 'jocky-image-optimizer' ) . '</h1>';
		echo '<p>' . esc_html__( 'Optimization creates separate local derivatives. Originals and WordPress attachment metadata remain unchanged; generated files are inactive for frontend delivery.', 'jocky-image-optimizer' ) . '</p>';
		echo '<h2>' . esc_html__( 'System capabilities', 'jocky-image-optimizer' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Capability', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Status', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Details', 'jocky-image-optimizer' ) . '</th></tr></thead><tbody>';
		foreach ( $capabilities as $name => $capability ) {
			printf( '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>', esc_html( ucwords( str_replace( '_', ' ', $name ) ) ), esc_html( $capability['available'] ? __( 'Available', 'jocky-image-optimizer' ) : __( 'Unavailable', 'jocky-image-optimizer' ) ), esc_html( $capability['detail'] ) );
		}
		echo '</tbody></table><p>' . esc_html__( 'This plugin does not install system packages.', 'jocky-image-optimizer' ) . '</p>';
		if ( null !== $this->jobs && null !== $this->bulk_scanner ) {
			$this->render_queue_dashboard();
		}
		echo '</div>';
	}

	/** Renders the settings form. */
	public function render_settings(): void {
		if ( ! $this->authorize() ) {
			return;
		}
		$settings = Settings::sanitize( get_option( Plugin::OPTION_NAME, array() ) );
		echo '<div class="wrap"><h1>' . esc_html__( 'Image Optimizer Settings', 'jocky-image-optimizer' ) . '</h1><form method="post" action="options.php">';
		settings_fields( 'jio_settings_group' );
		echo '<table class="form-table"><tbody>';
		$this->select_field(
			'output_format',
			__( 'Output format', 'jocky-image-optimizer' ),
			$settings['output_format'],
			array(
				'original' => __( 'Original', 'jocky-image-optimizer' ),
				'webp'     => 'WebP',
				'avif'     => 'AVIF',
				'both'     => __( 'WebP and AVIF', 'jocky-image-optimizer' ),
			)
		);
		$this->number_field( 'image_quality', __( 'Image quality', 'jocky-image-optimizer' ), $settings['image_quality'], 1, 100 );
		$this->number_field( 'minimum_quality', __( 'Minimum PNG quality', 'jocky-image-optimizer' ), $settings['minimum_quality'], 1, 100 );
		$this->checkbox_field( 'auto_optimize', __( 'Automatically optimize new uploads', 'jocky-image-optimizer' ), $settings['auto_optimize'] );
		$this->number_field( 'concurrency', __( 'Maximum concurrent workers', 'jocky-image-optimizer' ), $settings['concurrency'], 1, 4 );
		echo '</tbody></table><p class="description">' . esc_html__( 'Automatic optimization is disabled by default. Queue workers run through WP-Cron or WP-CLI. Originals and core-generated thumbnail files are retained.', 'jocky-image-optimizer' ) . '</p>';
		submit_button();
		echo '</form></div>';
	}

	/** Renders persisted job statistics and bounded queue controls. */
	private function render_queue_dashboard(): void {
		$stats  = $this->jobs->stats();
		$scan   = $this->bulk_scanner->state();
		$paused = QueueState::is_paused();
		$recent = $this->jobs->recent();
		echo '<h2>' . esc_html__( 'Media queue', 'jocky-image-optimizer' ) . '</h2>';
		echo '<div id="jio-queue-stats">';
		$this->render_stats( $stats, $scan );
		echo '</div>';
		echo '<p>' . esc_html__( 'Measured byte delta compares retained originals with separate derivatives. It is not physical disk savings.', 'jocky-image-optimizer' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="jio_queue_action">';
		wp_nonce_field( 'jio_queue_action' );
		$this->queue_button( 'start_bulk', __( 'Scan and queue eligible media', 'jocky-image-optimizer' ), 'secondary' );
		if ( $paused ) {
			$this->queue_button( 'resume', __( 'Resume queue', 'jocky-image-optimizer' ), 'primary' );
		} else {
			$this->queue_button( 'pause', __( 'Pause queue', 'jocky-image-optimizer' ), 'secondary' );
		}
		$this->queue_button( 'cancel', __( 'Cancel pending jobs', 'jocky-image-optimizer' ), 'delete' );
		$this->queue_button( 'retry_failed', __( 'Retry failed jobs', 'jocky-image-optimizer' ), 'secondary' );
		echo '</form>';
		echo '<h2>' . esc_html__( 'Recent queue activity', 'jocky-image-optimizer' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Attachment', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Variant', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Format', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Status', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Measured byte delta', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Issue', 'jocky-image-optimizer' ) . '</th></tr></thead><tbody>';
		foreach ( $recent as $job ) {
			$title = get_the_title( (int) $job['attachment_id'] );
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
				esc_html( false !== $title && '' !== $title ? $title : __( 'Deleted attachment', 'jocky-image-optimizer' ) ),
				esc_html( (string) $job['variant_key'] ),
				esc_html( (string) $job['output_format'] ),
				esc_html( (string) $job['status'] ),
				esc_html( number_format_i18n( (int) $job['bytes_saved'] ) ),
				esc_html( (string) ( $job['error_code'] ?? '' ) )
			);
		}
		if ( array() === $recent ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No queue activity yet.', 'jocky-image-optimizer' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Outputs job counts and bulk scan progress from persisted state.
	 *
	 * @param array<string, int|float|string> $stats Queue statistics.
	 * @param array<string, int|string>       $scan  Bulk scan state.
	 */
	private function render_stats( array $stats, array $scan ): void {
		$labels = array(
			'eligible'              => $this->bulk_scanner->count_attachments(),
			'completed_attachments' => $stats['completed_attachments'],
			'pending'               => $stats['pending'],
			'running'               => $stats['running'],
			'failed'                => $stats['failed'],
			'bytes_saved'           => $stats['bytes_saved'],
			'average_savings'       => round( $stats['average_savings'], 2 ),
			'worker_health'         => QueueState::is_paused() ? __( 'Paused', 'jocky-image-optimizer' ) : ( null !== wp_next_scheduled( QueueWorker::CRON_HOOK ) ? __( 'Scheduled', 'jocky-image-optimizer' ) : ( $stats['pending'] > 0 ? __( 'Waiting for WP-Cron or WP-CLI', 'jocky-image-optimizer' ) : __( 'Idle', 'jocky-image-optimizer' ) ) ),
		);
		echo '<dl class="jio-queue-summary">';
		foreach ( $labels as $key => $value ) {
			$label = match ( $key ) {
				'eligible'              => __( 'JPEG/PNG attachment candidates', 'jocky-image-optimizer' ),
				'completed_attachments' => __( 'Attachments processed', 'jocky-image-optimizer' ),
				'pending'               => __( 'Pending jobs', 'jocky-image-optimizer' ),
				'running'               => __( 'Running jobs', 'jocky-image-optimizer' ),
				'failed'                => __( 'Failed jobs', 'jocky-image-optimizer' ),
				'bytes_saved'           => __( 'Measured derivative byte delta', 'jocky-image-optimizer' ),
				'average_savings'        => __( 'Average derivative change (%)', 'jocky-image-optimizer' ),
				default                 => __( 'Worker health', 'jocky-image-optimizer' ),
			};
			$formatted = is_numeric( $value ) ? number_format_i18n( (float) $value, 2 ) : $value;
			printf( '<dt>%1$s</dt><dd id="jio-stat-%2$s">%3$s</dd>', esc_html( $label ), esc_attr( $key ), esc_html( $formatted ) );
		}
		// translators: 1: scan status, 2: attachments scanned, 3: total attachments, 4: new queue jobs.
		$progress = sprintf( __( 'Bulk scan: %1$s — %2$d of %3$d attachments scanned; %4$d jobs queued.', 'jocky-image-optimizer' ), (string) ( $scan['status'] ?? 'idle' ), (int) ( $scan['scanned'] ?? 0 ), (int) ( $scan['total_images'] ?? 0 ), (int) ( $scan['jobs_added'] ?? 0 ) );
		printf( '<dt>%1$s</dt><dd id="jio-stat-scan">%2$s</dd>', esc_html__( 'Bulk scan progress', 'jocky-image-optimizer' ), esc_html( $progress ) );
		echo '</dl>';
	}

	/** Adds a safe submit button for one allow-listed queue action.
	 *
	 * @param string $action Action identifier.
	 * @param string $label  Visible label.
	 * @param string $style  WordPress button style.
	 */
	private function queue_button( string $action, string $label, string $style ): void {
		printf( '<button class="button button-%1$s" type="submit" name="queue_action" value="%2$s">%3$s</button> ', esc_attr( $style ), esc_attr( $action ), esc_html( $label ) );
	}

	/** Handles nonce-protected queue controls.
	 */
	public function handle_queue_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage image jobs.', 'jocky-image-optimizer' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'jio_queue_action' );
		$action = isset( $_POST['queue_action'] ) && is_string( $_POST['queue_action'] ) ? sanitize_key( wp_unslash( $_POST['queue_action'] ) ) : '';
		switch ( $action ) {
			case 'start_bulk':
				QueueState::resume();
				$this->bulk_scanner->start();
				break;
			case 'pause':
				QueueState::pause();
				$this->bulk_scanner->pause();
				break;
			case 'resume':
				QueueState::resume();
				$this->bulk_scanner->resume();
				$this->worker->schedule_immediate();
				break;
			case 'cancel':
				$this->bulk_scanner->cancel();
				$this->jobs->cancel_pending();
				break;
			case 'retry_failed':
				$this->jobs->retry_failed();
				$this->worker->schedule_immediate();
				break;
			default:
				wp_die( esc_html__( 'The queue action is not recognized.', 'jocky-image-optimizer' ), '', array( 'response' => 400 ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=jocky-image-optimizer' ) );
		exit;
	}

	/** Returns rate-limited, capability- and nonce-protected progress data.
	 */
	public function ajax_queue_stats(): void {
		check_ajax_referer( 'jio_queue_stats' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		$rate_key = 'jio_stats_' . get_current_user_id();
		if ( get_transient( $rate_key ) ) {
			wp_send_json_error( array( 'message' => 'rate_limited' ), 429 );
		}
		set_transient( $rate_key, 1, 5 );
		wp_send_json_success(
			array(
				'stats' => $this->jobs->stats(),
				'scan'  => $this->bulk_scanner->state(),
			)
		);
	}

	/** Loads secure progress polling only on this plugin's dashboard.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 */
	public function enqueue_admin_script( string $hook_suffix ): void {
		if ( 'toplevel_page_jocky-image-optimizer' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script( 'jio-admin', plugins_url( 'assets/admin.js', JIO_FILE ), array(), JIO_VERSION, true );
		wp_localize_script(
			'jio-admin',
			'jioQueue',
			array(
				'url'    => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( 'jio_queue_stats' ),
				'action' => 'jio_queue_stats',
			)
		);
	}

	/** Ensures the current user can access the page. */
	private function authorize(): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		wp_die( esc_html__( 'You are not allowed to access this page.', 'jocky-image-optimizer' ) );
		return false;
	}

	/** Renders a select field.
	 *
	 * @param string                $key     Setting key.
	 * @param string                $label   Visible field label.
	 * @param string                $value   Selected value.
	 * @param array<string, string> $options Available options.
	 */
	private function select_field( string $key, string $label, string $value, array $options ): void {
		printf( '<tr><th scope="row"><label for="jio-%1$s">%2$s</label></th><td><select id="jio-%1$s" name="%3$s[%1$s]">', esc_attr( $key ), esc_html( $label ), esc_attr( Plugin::OPTION_NAME ) );
		foreach ( $options as $option => $text ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option ), selected( $value, $option, false ), esc_html( $text ) );
		}
		echo '</select></td></tr>';
	}

	/** Renders a numeric field with explicit bounds.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Visible field label.
	 * @param int    $value Current sanitized value.
	 * @param int    $min   Minimum accepted value.
	 * @param int    $max   Maximum accepted value.
	 */
	private function number_field( string $key, string $label, int $value, int $min, int $max ): void {
		printf( '<tr><th scope="row"><label for="jio-%1$s">%2$s</label></th><td><input class="small-text" type="number" id="jio-%1$s" name="%3$s[%1$s]" value="%4$s" min="%5$s" max="%6$s"></td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( Plugin::OPTION_NAME ), esc_attr( (string) $value ), esc_attr( (string) $min ), esc_attr( (string) $max ) );
	}

	/** Renders a checkbox setting.
	 *
	 * @param string $key     Setting key.
	 * @param string $label   Visible field label.
	 * @param bool   $checked Whether the checkbox is selected.
	 */
	private function checkbox_field( string $key, string $label, bool $checked ): void {
		printf( '<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s[%3$s]" value="1" %4$s> %5$s</label></td></tr>', esc_html( $label ), esc_attr( Plugin::OPTION_NAME ), esc_attr( $key ), checked( $checked, true, false ), esc_html__( 'Enabled', 'jocky-image-optimizer' ) );
	}
}
