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
use Jocky\ImageOptimizer\Plugin;
use Jocky\ImageOptimizer\Settings;

/**
 * Registers and renders the plugin's administrative pages.
 */
final class AdminPages {

	/**
	 * Creates the admin page service.
	 *
	 * @param CapabilityDetector $capabilities Runtime capability detector.
	 * @param JobRepository|null $jobs         Persistent queue.
	 * @param BulkScanner|null   $bulk_scanner Paginated media scanner.
	 * @param QueueWorker|null   $worker       Queue worker.
	 */
	public function __construct( private CapabilityDetector $capabilities, private ?JobRepository $jobs = null, private ?BulkScanner $bulk_scanner = null, private ?QueueWorker $worker = null ) {
	}

	/** Registers the admin hooks. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
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
