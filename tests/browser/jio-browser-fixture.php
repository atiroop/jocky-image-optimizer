<?php
/**
 * Test-only controls for the disposable frontend browser site.
 *
 * @package Jocky\ImageOptimizer\Tests
 */

add_filter(
	'query',
	static function ( $query ) {
		global $wpdb;
		if ( isset( $GLOBALS['jio_browser_registry_queries'] ) && false !== strpos( $query, $wpdb->prefix . 'jio_derivatives' ) && 0 === stripos( ltrim( $query ), 'SELECT' ) ) {
			++$GLOBALS['jio_browser_registry_queries'];
		}
		return $query;
	}
);

$GLOBALS['jio_browser_registry_queries'] = 0;

add_filter(
	'wp_get_attachment_image_attributes',
	static function ( $attributes, $attachment ) {
		$ids = get_option( 'jio_browser_attachments', array() );
		if ( ! $attachment instanceof WP_Post || ! in_array( (int) $attachment->ID, array_map( 'intval', (array) $ids ), true ) ) {
			return $attributes;
		}
	$attributes['loading']                 = 'lazy';
	$attributes['decoding']                = 'async';
	$attributes['fetchpriority']            = 'low';
	$attributes['data-jio-browser-fixture'] = 'synthetic';
	return $attributes;
	},
	10,
	2
);

register_shutdown_function(
	static function () {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		if ( isset( $GLOBALS['jio_browser_registry_queries'] ) ) {
			header( 'X-JIO-Registry-Lookups: ' . (int) $GLOBALS['jio_browser_registry_queries'] );
		}
	}
);

add_action(
	'template_redirect',
	static function () {
		$action = isset( $_GET['jio_browser'] ) ? sanitize_key( wp_unslash( $_GET['jio_browser'] ) ) : '';
		if ( '' === $action ) {
			return;
		}
		$provided = isset( $_SERVER['HTTP_X_JIO_BROWSER_TOKEN'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_JIO_BROWSER_TOKEN'] ) : '';
		$expected = (string) get_option( 'jio_browser_secret', '' );
		if ( '' === $expected || ! hash_equals( $expected, $provided ) ) {
			status_header( 403 );
			exit;
		}

		if ( 'elementor' === $action ) {
			$elementor = class_exists( '\Elementor\Plugin' ) ? \Elementor\Plugin::instance() : null;
			if ( ! is_object( $elementor ) || ! isset( $elementor->frontend ) ) {
				status_header( 503 );
				exit;
			}
			$markup = $elementor->frontend->get_builder_content_for_display( (int) get_option( 'jio_browser_elementor_page', 0 ), true );
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'X-JIO-Elementor-Version: ' . rawurlencode( (string) ELEMENTOR_VERSION ) );
			header( 'X-JIO-Registry-Lookups: ' . (int) $GLOBALS['jio_browser_registry_queries'] );
			echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>JIO Elementor fixture</title></head><body>' . $markup . '</body></html>';
			exit;
		}

		if ( 'control' !== $action ) {
			status_header( 404 );
			exit;
		}
		$operation = isset( $_GET['operation'] ) ? sanitize_key( wp_unslash( $_GET['operation'] ) ) : '';
		$asset     = isset( $_GET['asset'] ) ? sanitize_key( wp_unslash( $_GET['asset'] ) ) : '';
		$ids       = get_option( 'jio_browser_attachments', array() );
		$wpdb      = $GLOBALS['wpdb'];
		$settings  = \Jocky\ImageOptimizer\Settings::sanitize( get_option( \Jocky\ImageOptimizer\Plugin::OPTION_NAME, array() ) );

		if ( 'settings' === $operation ) {
			$enabled = isset( $_GET['enabled'] ) && '1' === (string) wp_unslash( $_GET['enabled'] );
			$preference = isset( $_GET['preference'] ) ? sanitize_key( wp_unslash( $_GET['preference'] ) ) : 'auto';
			$settings['frontend_delivery']   = $enabled;
			$settings['delivery_preference'] = $preference;
			update_option( \Jocky\ImageOptimizer\Plugin::OPTION_NAME, \Jocky\ImageOptimizer\Settings::sanitize( $settings ) );
		} elseif ( in_array( $operation, array( 'legacy', 'stale', 'missing' ), true ) ) {
			$attachment_id = isset( $ids[ $asset ] ) ? (int) $ids[ $asset ] : 0;
			if ( $attachment_id < 1 ) {
				status_header( 400 );
				exit;
			}
			if ( 'legacy' === $operation ) {
				$wpdb->update( \Jocky\ImageOptimizer\Queue\QueueSchema::derivatives_table(), array( 'derivative_identity' => null ), array( 'attachment_id' => $attachment_id ), array( '%s' ), array( '%d' ) );
			} elseif ( 'stale' === $operation ) {
				$wpdb->update( \Jocky\ImageOptimizer\Queue\QueueSchema::derivatives_table(), array( 'derivative_identity' => 'stale-browser-file-identity' ), array( 'attachment_id' => $attachment_id ), array( '%s' ), array( '%d' ) );
			} else {
				$uploads = wp_upload_dir();
				$root    = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
				$rows    = ( new \Jocky\ImageOptimizer\Recovery\DerivativeRepository() )->for_attachment( $attachment_id, 10 );
				foreach ( $rows as $row ) {
					$relative = (string) $row['derivative_relative_path'];
					if ( false === $root || str_contains( $relative, '..' ) || str_contains( $relative, '\\' ) ) {
						continue;
					}
					$path = trailingslashit( $root ) . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
					if ( is_file( $path ) && ! is_link( $path ) && false !== realpath( $path ) && str_starts_with( wp_normalize_path( realpath( $path ) ), wp_normalize_path( $root ) . '/' ) ) {
						unlink( $path );
					}
				}
				$wpdb->update( \Jocky\ImageOptimizer\Queue\QueueSchema::derivatives_table(), array( 'lifecycle_status' => 'cleaned' ), array( 'attachment_id' => $attachment_id ), array( '%s' ), array( '%d' ) );
			}
		} elseif ( 'reoptimize' === $operation ) {
			$attachment_id = isset( $ids[ $asset ] ) ? (int) $ids[ $asset ] : 0;
			if ( $attachment_id < 1 ) {
				status_header( 400 );
				exit;
			}
			$uploads = wp_upload_dir();
			$root    = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
			$rows    = ( new \Jocky\ImageOptimizer\Recovery\DerivativeRepository() )->for_attachment( $attachment_id, 10 );
			foreach ( $rows as $row ) {
				$relative = (string) $row['derivative_relative_path'];
				if ( false === $root || ! preg_match( '/\.jio\.(?:webp|avif)$/', $relative ) || str_contains( $relative, '..' ) || str_contains( $relative, '\\' ) ) {
					continue;
				}
				$path = trailingslashit( $root ) . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
				if ( is_file( $path ) && ! is_link( $path ) && false !== realpath( $path ) && str_starts_with( wp_normalize_path( realpath( $path ) ), wp_normalize_path( $root ) . '/' ) ) {
					unlink( $path );
				}
			}
			$wpdb->update( \Jocky\ImageOptimizer\Queue\QueueSchema::derivatives_table(), array( 'lifecycle_status' => 'cleaned' ), array( 'attachment_id' => $attachment_id ), array( '%s' ), array( '%d' ) );
			$wpdb->delete( \Jocky\ImageOptimizer\Queue\QueueSchema::jobs_table(), array( 'attachment_id' => $attachment_id ), array( '%d' ) );
			$settings['output_format'] = 'both';
			update_option( \Jocky\ImageOptimizer\Plugin::OPTION_NAME, $settings );
			$jobs   = new \Jocky\ImageOptimizer\Queue\JobRepository();
			$worker = new \Jocky\ImageOptimizer\Queue\QueueWorker( $jobs );
			( new \Jocky\ImageOptimizer\Media\MediaIntegration( $jobs, $worker ) )->enqueue_attachment( $attachment_id );
			for ( $attempt = 0; $attempt < 4 && $jobs->pending_count() > 0; ++$attempt ) {
				$counts = $worker->run_batch( 10 );
				if ( $counts['failed'] > 0 || $counts['skipped'] > 0 ) {
					status_header( 500 );
					exit;
				}
			}
		}

		$attachment_id = isset( $ids[ $asset ] ) ? (int) $ids[ $asset ] : 0;
		$records       = $attachment_id > 0 ? ( new \Jocky\ImageOptimizer\Recovery\DerivativeRepository() )->for_attachment( $attachment_id, 10 ) : array();
		$record_ids    = array_map( static fn ( $record ) => (int) $record['id'], $records );
		$identities    = array_map( static fn ( $record ) => (string) ( $record['derivative_identity'] ?? '' ), $records );
		$original_hashes = get_option( 'jio_browser_original_hashes', array() );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		echo wp_json_encode(
			array(
				'operation'              => $operation,
				'frontend_delivery'      => $settings['frontend_delivery'],
				'delivery_preference'    => $settings['delivery_preference'],
				'wp_rocket_available'    => function_exists( 'rocket_clean_domain' ),
				'cache_notice'           => get_option( 'jio_delivery_cache_notice', '' ),
				'attachment_id'          => $attachment_id,
				'record_ids'             => $record_ids,
				'derivative_identities'  => $identities,
				'attachment_metadata'    => $attachment_id > 0 ? wp_get_attachment_metadata( $attachment_id ) : null,
				'expected_original_sha256' => isset( $original_hashes[ $asset ] ) ? $original_hashes[ $asset ] : null,
				'original_sha256'        => $attachment_id > 0 && is_file( get_attached_file( $attachment_id ) ) ? hash_file( 'sha256', get_attached_file( $attachment_id ) ) : null,
			)
		);
		exit;
	}
);
