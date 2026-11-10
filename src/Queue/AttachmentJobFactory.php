<?php
/**
 * Creates idempotent work records for current attachment variants.
 *
 * @package Jocky\ImageOptimizer\Queue
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Queue;

use Jocky\ImageOptimizer\Media\AttachmentTargetProvider;
use Jocky\ImageOptimizer\Plugin;
use Jocky\ImageOptimizer\Settings;

/** Maps sanitized plugin settings to bounded attachment derivative jobs. */
final class AttachmentJobFactory {

	/** Builds all target-format jobs for one attachment.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array<array{attachment_id:int,variant_key:string,output_format:string,source_fingerprint:string}> Job candidates.
	 */
	public function jobs_for_attachment( int $attachment_id ): array {
		$settings = Settings::sanitize( get_option( Plugin::OPTION_NAME, array() ) );
		$targets  = ( new AttachmentTargetProvider() )->targets( $attachment_id );
		$formats  = match ( $settings['output_format'] ) {
			'webp' => array( 'image/webp' ),
			'avif' => array( 'image/avif' ),
			'both' => array( 'image/webp', 'image/avif' ),
			default => array( 'source' ),
		};
		$jobs = array();
		foreach ( $targets as $target ) {
			foreach ( $formats as $format ) {
				$jobs[] = array(
					'attachment_id'      => $attachment_id,
					'variant_key'        => $target['variant_key'],
					'output_format'      => $format,
					'source_fingerprint' => $target['fingerprint'],
				);
			}
		}
		return $jobs;
	}
}
