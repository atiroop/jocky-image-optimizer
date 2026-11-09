<?php
/**
 * Plugin lifecycle and application bootstrap.
 *
 * @package Jocky\ImageOptimizer
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer;

use Jocky\ImageOptimizer\Admin\AdminPages;

/** Coordinates the plugin's lifecycle and admin services. */
final class Plugin {

	public const OPTION_NAME = 'jio_settings';

	/** Boots admin integrations when WordPress is in the dashboard. */
	public function boot(): void {
		if ( is_admin() ) {
			( new AdminPages( new CapabilityDetector() ) )->register();
		}
	}

	/** Adds default settings without replacing existing values. */
	public static function activate(): void {
		if ( get_option( self::OPTION_NAME, false ) === false ) {
			add_option( self::OPTION_NAME, Settings::defaults(), '', false );
		}
	}

	/** Deactivates the plugin without deleting settings or media. */
	public static function deactivate(): void {
		// No scheduled work or persistent runtime state exists in Milestone 1.
	}
}
