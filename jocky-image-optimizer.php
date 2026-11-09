<?php
/**
 * Plugin Name: Jocky Image Optimizer
 * Description: A self-hosted image optimization foundation with runtime capability diagnostics.
 * Version: 0.1.0-dev
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Author: Jocky
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: jocky-image-optimizer
 * Domain Path: /languages
 *
 * @package Jocky\ImageOptimizer
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'JIO_VERSION', '0.1.0-dev' );
define( 'JIO_FILE', __FILE__ );
define( 'JIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'JIO_URL', plugin_dir_url( __FILE__ ) );

$jio_autoload = JIO_DIR . 'vendor/autoload.php';
if ( ! is_readable( $jio_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Jocky Image Optimizer is missing its Composer autoloader. Run composer install and install the complete plugin package.', 'jocky-image-optimizer' )
			);
		}
	);
	return;
}

require_once $jio_autoload;

register_activation_hook( __FILE__, array( Jocky\ImageOptimizer\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Jocky\ImageOptimizer\Plugin::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		( new Jocky\ImageOptimizer\Plugin() )->boot();
	}
);
