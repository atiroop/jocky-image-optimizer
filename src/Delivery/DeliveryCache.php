<?php
/**
 * Cache invalidation for delivery setting changes.
 *
 * @package Jocky\ImageOptimizer\Delivery
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Delivery;

use Jocky\ImageOptimizer\Plugin;
use Jocky\ImageOptimizer\Settings;

/** Uses WP Rocket's documented domain purge when present and records manual CDN guidance. */
final class DeliveryCache {

	/** Whether a derivative change has already cleared page cache in this request.
	 *
	 * @var bool
	 */
	private bool $derivative_cache_purged = false;

	/** Registers settings-change and administrator notification hooks.
	 */
	public function register(): void {
		add_action( 'update_option_' . Plugin::OPTION_NAME, array( $this, 'settings_updated' ), 10, 2 );
		add_action( 'jio_derivative_updated', array( $this, 'derivative_updated' ) );
		add_action( 'update_option_elementor_element_cache_ttl', array( $this, 'elementor_cache_setting_updated' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_notices', array( $this, 'elementor_element_cache_notice' ) );
	}

	/** Purges page cache when either frontend setting changes.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $new_value Updated settings.
	 */
	public function settings_updated( $old_value, $new_value ): void {
		$old = is_array( $old_value ) ? $old_value : array();
		$new = is_array( $new_value ) ? $new_value : array();
		if ( ( $old['frontend_delivery'] ?? false ) === ( $new['frontend_delivery'] ?? false ) && ( $old['delivery_preference'] ?? 'auto' ) === ( $new['delivery_preference'] ?? 'auto' ) ) {
			return;
		}
		if ( ( $new['frontend_delivery'] ?? false ) && FrontendDelivery::elementor_element_cache_enabled() ) {
			update_option( 'jio_elementor_cache_notice', true, false );
		}
		if ( ( $old['frontend_delivery'] ?? false ) && ! ( $new['frontend_delivery'] ?? false ) && FrontendDelivery::elementor_element_cache_enabled() ) {
			update_option( 'jio_elementor_cache_notice', true, false );
		}
		$this->purge_cache();
	}

	/** Invalidates cached markup once per worker request when delivery is enabled.
	 *
	 * @param int $attachment_id Updated attachment ID.
	 */
	public function derivative_updated( int $attachment_id ): void {
		$settings = Settings::sanitize( get_option( Plugin::OPTION_NAME, array() ) );
		if ( $attachment_id < 1 || ! $settings['frontend_delivery'] || $this->derivative_cache_purged ) {
			return;
		}
		$this->derivative_cache_purged = true;
		$this->purge_cache();
	}

	/** Retains the compatibility warning until Elementor disables and clears its cache.
	 *
	 * @param mixed $old_value Previous cache setting.
	 * @param mixed $new_value Updated cache setting.
	 */
	public function elementor_cache_setting_updated( $old_value, $new_value ): void {
		if ( 'disable' === $new_value ) {
			delete_option( 'jio_elementor_cache_notice' );
			return;
		}
		$settings = Settings::sanitize( get_option( Plugin::OPTION_NAME, array() ) );
		if ( $settings['frontend_delivery'] ) {
			update_option( 'jio_elementor_cache_notice', true, false );
		}
	}

	/** Calls the optional cache purge API and records external cache guidance.
	 */
	private function purge_cache(): void {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			try {
				rocket_clean_domain();
			} catch ( \Throwable ) {
				update_option( 'jio_delivery_cache_notice', 'manual', false );
				return;
			}
		}
		update_option( 'jio_delivery_cache_notice', 'manual', false );
	}

	/** Warns that Elementor Element Caching is outside the supported delivery mode.
	 */
	public function elementor_element_cache_notice(): void {
		$settings                   = Settings::sanitize( get_option( Plugin::OPTION_NAME, array() ) );
		$cache_is_active            = FrontendDelivery::elementor_element_cache_enabled();
		$warning_pending            = (bool) get_option( 'jio_elementor_cache_notice', false );
		$delivery_cache_unavailable = ! $settings['frontend_delivery'] || ! $cache_is_active;
		if ( ! current_user_can( 'manage_options' ) || ( ! $warning_pending && $delivery_cache_unavailable ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Elementor Element Caching is active. Jocky Image Optimizer will leave Elementor Image and Image Box output unchanged while this cache is enabled. For Elementor delivery, disable Element Cache in Elementor > Editor > Settings > Performance, then use Elementor > Editor > Home > Tools > Clear Files & Data to remove previously cached widget markup.', 'jocky-image-optimizer' )
		);
	}

	/** Displays manual external-cache purge guidance to administrators.
	 */
	public function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || 'manual' !== get_option( 'jio_delivery_cache_notice' ) ) {
			return;
		}
		printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'Frontend delivery or derivative data changed. Purge Cloudflare and any page cache not cleared by WP Rocket; cached HTML may continue to reference prior markup.', 'jocky-image-optimizer' ) );
		delete_option( 'jio_delivery_cache_notice' );
	}
}
