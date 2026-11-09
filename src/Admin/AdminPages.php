<?php
/**
 * WordPress admin pages for settings and runtime diagnostics.
 *
 * @package Jocky\ImageOptimizer
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Admin;

use Jocky\ImageOptimizer\CapabilityDetector;
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
	 */
	public function __construct( private CapabilityDetector $capabilities ) {
	}

	/** Registers the admin hooks. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
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
		echo '<p>' . esc_html__( 'Milestone 1 foundation. Image processing is not implemented yet; no media has been modified.', 'jocky-image-optimizer' ) . '</p>';
		echo '<h2>' . esc_html__( 'System capabilities', 'jocky-image-optimizer' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Capability', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Status', 'jocky-image-optimizer' ) . '</th><th>' . esc_html__( 'Details', 'jocky-image-optimizer' ) . '</th></tr></thead><tbody>';
		foreach ( $capabilities as $name => $capability ) {
			printf( '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>', esc_html( ucwords( str_replace( '_', ' ', $name ) ) ), esc_html( $capability['available'] ? __( 'Available', 'jocky-image-optimizer' ) : __( 'Unavailable', 'jocky-image-optimizer' ) ), esc_html( $capability['detail'] ) );
		}
		echo '</tbody></table><p>' . esc_html__( 'Install and configure server-side image libraries or encoders to enable future processing. This plugin does not install system packages.', 'jocky-image-optimizer' ) . '</p></div>';
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
		$this->number_field( 'max_width', __( 'Maximum width (0 = unlimited)', 'jocky-image-optimizer' ), $settings['max_width'], 0, 20000 );
		$this->number_field( 'max_height', __( 'Maximum height (0 = unlimited)', 'jocky-image-optimizer' ), $settings['max_height'], 0, 20000 );
		$this->checkbox_field( 'auto_optimize', __( 'Automatically optimize new uploads', 'jocky-image-optimizer' ), $settings['auto_optimize'] );
		$this->checkbox_field( 'keep_backup', __( 'Keep original backup', 'jocky-image-optimizer' ), $settings['keep_backup'] );
		$this->number_field( 'concurrency', __( 'Processing concurrency (fixed for this release)', 'jocky-image-optimizer' ), $settings['concurrency'], 1, 1 );
		echo '</tbody></table><p class="description">' . esc_html__( 'These settings are stored for future milestones. Processing and bulk actions are not available in this foundation release. Worker concurrency is fixed at one for safety.', 'jocky-image-optimizer' ) . '</p>';
		submit_button();
		echo '</form></div>';
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
