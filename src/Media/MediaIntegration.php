<?php
/**
 * WordPress media hooks for explicit and automatic queueing.
 *
 * @package Jocky\ImageOptimizer\Media
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Media;

use Jocky\ImageOptimizer\Plugin;
use Jocky\ImageOptimizer\Queue\AttachmentJobFactory;
use Jocky\ImageOptimizer\Queue\JobRepository;
use Jocky\ImageOptimizer\Queue\QueueWorker;
use Jocky\ImageOptimizer\Settings;

/** Enqueues work after WordPress finishes image metadata generation. */
final class MediaIntegration {

	/** Creates media queue integration dependencies.
	 *
	 * @param JobRepository $jobs   Persistent queue.
	 * @param QueueWorker   $worker Queue scheduler.
	 */
	public function __construct( private JobRepository $jobs, private QueueWorker $worker ) {}

	/** Registers upload and Media Library row-action hooks. */
	public function register(): void {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'after_metadata_generated' ), 100, 2 );
		add_filter( 'media_row_actions', array( $this, 'attachment_row_action' ), 10, 2 );
		add_action( 'admin_post_jio_optimize_attachment', array( $this, 'handle_manual_request' ) );
	}

	/** Queues automatic work after all core-generated size metadata is ready.
	 *
	 * This callback never encodes or modifies metadata.
	 *
	 * @param mixed $metadata  Core-generated attachment metadata.
	 * @param int   $post_id   Attachment ID.
	 * @return mixed Unmodified WordPress metadata.
	 */
	public function after_metadata_generated( $metadata, int $post_id ) {
		$settings = Settings::sanitize( get_option( Plugin::OPTION_NAME, array() ) );
		if ( $settings['auto_optimize'] && 'attachment' === get_post_type( $post_id ) ) {
			$this->enqueue_attachment( $post_id );
			$this->worker->schedule_immediate();
		}
		return $metadata;
	}

	/** Adds a capability-gated manual action to eligible Media Library rows.
	 *
	 * @param array<string, string> $actions Existing row actions.
	 * @param \WP_Post              $post    Attachment row.
	 * @return array<string, string> Extended actions.
	 */
	public function attachment_row_action( array $actions, \WP_Post $post ): array {
		if ( ! current_user_can( 'manage_options' ) || 'attachment' !== $post->post_type || ! in_array( $post->post_mime_type, array( 'image/jpeg', 'image/png' ), true ) ) {
			return $actions;
		}
		$url                     = add_query_arg(
			array(
				'action'        => 'jio_optimize_attachment',
				'attachment_id' => (string) $post->ID,
			),
			admin_url( 'admin-post.php' )
		);
		$actions['jio_optimize'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( wp_nonce_url( $url, 'jio_optimize_attachment_' . $post->ID ) ), esc_html__( 'Queue optimization', 'jocky-image-optimizer' ) );
		$recovery_url            = add_query_arg(
			array(
				'page'          => 'jio-recovery',
				'attachment_id' => (int) $post->ID,
			),
			admin_url( 'admin.php' )
		);
		$actions['jio_recovery'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $recovery_url ), esc_html__( 'JIO recovery preview', 'jocky-image-optimizer' ) );
		return $actions;
	}

	/** Validates a Media Library row action and queues only the addressed attachment. */
	public function handle_manual_request(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to queue image optimization.', 'jocky-image-optimizer' ), '', array( 'response' => 403 ) );
		}
		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( wp_unslash( $_GET['attachment_id'] ) ) : 0;
		check_admin_referer( 'jio_optimize_attachment_' . $attachment_id );
		if ( 0 === $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_die( esc_html__( 'The requested image attachment is invalid.', 'jocky-image-optimizer' ), '', array( 'response' => 400 ) );
		}
		$queued = $this->enqueue_attachment( $attachment_id );
		$this->worker->schedule_immediate();
		$referer  = wp_get_referer();
		$redirect = add_query_arg( 'jio_queued', (string) $queued, false !== $referer ? $referer : admin_url( 'upload.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/** Enqueues bounded work for the current local attachment variants.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Number of new unique jobs.
	 */
	public function enqueue_attachment( int $attachment_id ): int {
		$jobs = ( new AttachmentJobFactory() )->jobs_for_attachment( $attachment_id );
		return $this->jobs->enqueue_many( $jobs );
	}
}
