<?php

/** Builds the entirely synthetic media and Elementor pages for browser CI. */

function jio_browser_require( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

function jio_browser_image( $asset, $mime ) {
	$uploads = wp_upload_dir();
	$path    = trailingslashit( $uploads['basedir'] ) . 'jio-browser-' . $asset . ( 'image/jpeg' === $mime ? '.jpg' : '.png' );
	$image   = imagecreatetruecolor( 1200, 800 );
	imagealphablending( $image, false );
	imagesavealpha( $image, true );
	for ( $x = 0; $x < 1200; $x += 40 ) {
		$red   = ( $x * 7 ) % 256;
		$green = ( $x * 3 ) % 256;
		$blue  = ( $x * 11 ) % 256;
		$color = 'image/jpeg' === $mime ? imagecolorallocate( $image, $red, $green, $blue ) : imagecolorallocatealpha( $image, $red, $green, $blue, ( $x / 40 ) % 2 ? 20 : 0 );
		imagefilledrectangle( $image, $x, 0, min( 1199, $x + 39 ), 799, $color );
	}
	$saved = 'image/jpeg' === $mime ? imagejpeg( $image, $path, 88 ) : imagepng( $image, $path, 6 );
	imagedestroy( $image );
	jio_browser_require( $saved && is_file( $path ), 'Could not create synthetic ' . $mime . ' test media.' );
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $mime,
			'post_title'     => 'JIO browser ' . $asset,
			'post_status'    => 'inherit',
		),
		$path,
		0,
		true
	);
	jio_browser_require( ! is_wp_error( $attachment_id ), 'Could not register synthetic attachment ' . $asset . '.' );
	update_attached_file( $attachment_id, $path );
	update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'JIO accessible ' . $asset . ' image' );
	$metadata = wp_generate_attachment_metadata( $attachment_id, $path );
	jio_browser_require( is_array( $metadata ) && ! empty( $metadata['sizes'] ), 'WordPress did not generate responsive variants for ' . $asset . '.' );
	wp_update_attachment_metadata( $attachment_id, $metadata );
	return array(
		'id'       => (int) $attachment_id,
		'path'     => $path,
		'url'      => wp_get_attachment_url( $attachment_id ),
		'metadata' => $metadata,
		'sha256'   => hash_file( 'sha256', $path ),
	);
}

function jio_browser_all_records( $attachment_id ) {
	$repository = new \Jocky\ImageOptimizer\Recovery\DerivativeRepository();
	$all        = array();
	$after      = 0;
	for ( $page = 0; $page < 40; ++$page ) {
		$records = $repository->for_attachment( $attachment_id, 10, $after );
		if ( array() === $records ) {
			break;
		}
		$all  = array_merge( $all, $records );
		$after = (int) end( $records )['id'];
	}
	return $all;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\Elementor\Plugin' ) ) {
	fwrite( STDERR, "This fixture requires WP-CLI and the real activated Elementor plugin.\n" );
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/image.php';

update_option(
	\Jocky\ImageOptimizer\Plugin::OPTION_NAME,
	\Jocky\ImageOptimizer\Settings::sanitize(
		array(
			'output_format'      => 'both',
			'image_quality'      => 82,
			'minimum_quality'    => 60,
			'auto_optimize'      => false,
			'frontend_delivery'  => false,
			'delivery_preference' => 'auto',
			'concurrency'        => 1,
		)
	)
);

$png  = jio_browser_image( 'png', 'image/png' );
$jpeg = jio_browser_image( 'jpeg', 'image/jpeg' );
$ids  = array(
	'png'  => $png['id'],
	'jpeg' => $jpeg['id'],
);
update_option( 'jio_browser_attachments', $ids, false );
update_option( 'jio_browser_original_hashes', array( 'png' => $png['sha256'], 'jpeg' => $jpeg['sha256'] ), false );

$jobs   = new \Jocky\ImageOptimizer\Queue\JobRepository();
$worker = new \Jocky\ImageOptimizer\Queue\QueueWorker( $jobs );
$media  = new \Jocky\ImageOptimizer\Media\MediaIntegration( $jobs, $worker );
foreach ( array( $png, $jpeg ) as $asset ) {
	jio_browser_require( $media->enqueue_attachment( $asset['id'] ) > 0, 'The synthetic attachment produced no optimization jobs.' );
}
$completed = 0;
for ( $attempt = 0; $attempt < 4 && $jobs->pending_count() > 0; ++$attempt ) {
	$counts = $worker->run_batch( 10 );
	$completed += $counts['completed'];
	jio_browser_require( 0 === $counts['failed'] && 0 === $counts['skipped'], 'The real encoder queue returned a failed or skipped browser fixture job.' );
}
jio_browser_require( 0 === $jobs->pending_count() && $completed > 0, 'The real encoder queue did not finish its bounded synthetic media jobs.' );

foreach ( array( $png, $jpeg ) as $asset ) {
	$records = jio_browser_all_records( $asset['id'] );
	jio_browser_require( count( $records ) >= 4, 'Expected current WebP/AVIF full-size and responsive derivative records.' );
	foreach ( $records as $record ) {
		jio_browser_require( '' !== (string) $record['derivative_identity'], 'Generated derivative is missing its current Milestone 6 file identity.' );
	}
	$current_metadata = wp_get_attachment_metadata( $asset['id'] );
	jio_browser_require( $asset['sha256'] === hash_file( 'sha256', $asset['path'] ), 'The worker modified an original attachment.' );
	jio_browser_require( $asset['metadata'] === $current_metadata, 'The worker changed WordPress attachment metadata.' );
}

$block_markup = static function ( $asset, $link ) {
	$id    = $asset['id'];
	$image = wp_get_attachment_image(
		$id,
		'full',
		false,
		array(
			'alt'      => 'JIO accessible image ' . $id,
			'class'    => 'attachment-full size-full wp-image-' . $id,
			'sizes'    => '(max-width: 1200px) 100vw, 1200px',
		)
	);
	return '<!-- wp:image {"id":' . $id . ',"sizeSlug":"full","linkDestination":"custom"} -->' .
		'<figure class="wp-block-image size-full"><a href="' . esc_url( $link ) . '">' . $image . '</a><figcaption>JIO browser caption ' . $id . '</figcaption></figure>' .
		'<!-- /wp:image -->';
};
$gutenberg_content = $block_markup( $png, home_url( '/jio-png-linked-target/' ) ) . $block_markup( $jpeg, home_url( '/jio-jpeg-linked-target/' ) );
foreach ( array( $png, $jpeg ) as $asset ) {
	jio_browser_require( str_contains( $gutenberg_content, 'wp-image-' . $asset['id'] ), 'wp_get_attachment_image() omitted the expected Gutenberg image class for attachment ' . $asset['id'] . '.' );
}
$gutenberg_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'JIO Browser Gutenberg Fixture',
		'post_name'    => 'jio-browser-gutenberg',
		'post_content' => $gutenberg_content,
	),
	true
);
jio_browser_require( ! is_wp_error( $gutenberg_id ), 'Could not create the Gutenberg fixture page.' );
$stored_gutenberg_content = (string) get_post_field( 'post_content', $gutenberg_id );
foreach ( array( $png, $jpeg ) as $asset ) {
	if ( ! str_contains( $stored_gutenberg_content, 'wp-image-' . $asset['id'] ) ) {
		fwrite( STDERR, 'WordPress did not retain Gutenberg image markup for attachment ' . $asset['id'] . '; stored content prefix: ' . substr( $stored_gutenberg_content, 0, 1200 ) . PHP_EOL );
		exit( 1 );
	}
}

$elementor_id = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'JIO Browser Elementor Fixture',
		'post_name'   => 'jio-browser-elementor',
	),
	true
);
jio_browser_require( ! is_wp_error( $elementor_id ), 'Could not create the Elementor fixture page.' );
$elementor_data = array(
	array(
		'id'       => 'jiosec1',
		'elType'   => 'container',
		'settings' => array(),
		'elements' => array(
			array(
				'id'         => 'jioimg1',
				'elType'     => 'widget',
				'widgetType' => 'image',
				'settings'   => array(
					'image'          => array( 'id' => $png['id'], 'url' => $png['url'] ),
					'image_size'     => 'full',
					'caption_source' => 'custom',
					'caption'        => 'JIO Elementor image caption',
					'link_to'        => 'custom',
					'link'           => array( 'url' => home_url( '/elementor-image-target/' ), 'is_external' => '', 'nofollow' => '' ),
				),
				'elements'   => array(),
			),
			array(
				'id'         => 'jioibox1',
				'elType'     => 'widget',
				'widgetType' => 'image-box',
				'settings'   => array(
					'image'       => array( 'id' => $jpeg['id'], 'url' => $jpeg['url'] ),
					'thumbnail_size' => 'full',
					'title_text'  => 'JIO Elementor Image Box',
				'description_text' => 'Synthetic image box description',
				'link'        => array( 'url' => home_url( '/elementor-box-target/' ), 'is_external' => '', 'nofollow' => '' ),
				),
				'elements'   => array(),
			),
			array(
				'id'       => 'jiobg01',
				'elType'   => 'container',
				'settings' => array(
					'background_background' => 'classic',
					'background_image'      => array( 'url' => $jpeg['url'], 'id' => $jpeg['id'] ),
				),
				'elements' => array(),
			),
		),
	)
);
update_post_meta( $elementor_id, '_elementor_edit_mode', 'builder' );
update_post_meta( $elementor_id, '_elementor_template_type', 'wp-page' );
update_post_meta( $elementor_id, '_elementor_version', ELEMENTOR_VERSION );
update_post_meta( $elementor_id, '_elementor_data', wp_slash( wp_json_encode( $elementor_data ) ) );
update_option( 'jio_browser_elementor_page', (int) $elementor_id, false );
update_option( 'jio_browser_gutenberg_page', (int) $gutenberg_id, false );
update_option( 'jio_browser_secret', bin2hex( random_bytes( 32 ) ), false );
update_option(
	'jio_browser_fixture_data',
	array(
		'wordpress'          => get_bloginfo( 'version' ),
		'elementor'          => ELEMENTOR_VERSION,
		'gutenberg_url'      => get_permalink( $gutenberg_id ),
		'elementor_url'      => home_url( '/?jio_browser=elementor' ),
		'elementor_preflight_url' => home_url( '/?jio_browser=elementor-preflight' ),
		'elementor_page_id'  => (int) $elementor_id,
		'elementor_token'    => get_option( 'jio_browser_secret' ),
		'original_hashes'    => array( 'png' => $png['sha256'], 'jpeg' => $jpeg['sha256'] ),
		'original_metadata'  => array( 'png' => $png['metadata'], 'jpeg' => $jpeg['metadata'] ),
		'attachments'        => array(
			'png'  => array_merge( $png, array( 'alt' => 'JIO accessible image ' . $png['id'] ) ),
			'jpeg' => array_merge( $jpeg, array( 'alt' => 'JIO accessible image ' . $jpeg['id'] ) ),
		),
	),
	false
);

WP_CLI::success( 'Created synthetic Gutenberg and Elementor image fixtures; private fixture token is not printed.' );
