<?php
/**
 * Runs one validated attachment-variant queue job.
 *
 * @package Jocky\ImageOptimizer\Media
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Media;

use Jocky\ImageOptimizer\Optimization\AvifEncoder;
use Jocky\ImageOptimizer\Optimization\CwebpEncoder;
use Jocky\ImageOptimizer\Optimization\EncoderRegistry;
use Jocky\ImageOptimizer\Optimization\ImageValidator;
use Jocky\ImageOptimizer\Optimization\JpegtranEncoder;
use Jocky\ImageOptimizer\Optimization\MozJpegEncoder;
use Jocky\ImageOptimizer\Optimization\OptimizationManager;
use Jocky\ImageOptimizer\Optimization\OptimizationRequest;
use Jocky\ImageOptimizer\Optimization\OptimizationResult;
use Jocky\ImageOptimizer\Optimization\OxipngEncoder;
use Jocky\ImageOptimizer\Optimization\PathGuard;
use Jocky\ImageOptimizer\Optimization\PngquantEncoder;
use Jocky\ImageOptimizer\Optimization\SafeFileReplacer;
use Jocky\ImageOptimizer\Optimization\TemporaryFileManager;
use Jocky\ImageOptimizer\Plugin;
use Jocky\ImageOptimizer\Queue\JobRepository;
use Jocky\ImageOptimizer\Settings;
use Jocky\ImageOptimizer\System\BinaryLocator;
use Jocky\ImageOptimizer\System\ProcessRunner;

/** Processes sources into separate, inactive derivatives and records ownership metadata. */
final class AttachmentProcessor {

	/** WordPress-aware, upload-root-confined image source resolver.
	 *
	 * @var AttachmentTargetProvider
	 */
	private AttachmentTargetProvider $targets;

	/** Creates a processor using WordPress attachment metadata for source discovery. */
	public function __construct() {
		$this->targets = new AttachmentTargetProvider();
	}

	/** Executes one claimed derivative job.
	 *
	 * @param array<string, mixed> $job Claimed database queue row.
	 * @return array{status:string,input_bytes:int,output_bytes:int|null,error_code:string|null} Bounded outcome.
	 */
	public function process( array $job ): array {
		$attachment_id = (int) $job['attachment_id'];
		$target        = $this->targets->target( $attachment_id, (string) $job['variant_key'] );
		if ( null === $target || ! hash_equals( (string) $job['source_fingerprint'], $target['fingerprint'] ) ) {
			return array(
				'status'       => 'skipped',
				'input_bytes'  => 0,
				'output_bytes' => null,
				'error_code'   => 'source_unavailable_or_changed',
			);
		}
		$format      = (string) $job['output_format'];
		$target_mime = 'source' === $format ? $target['mime'] : $format;
		$extension   = match ( $target_mime ) {
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
			default      => '',
		};
		if ( '' === $extension ) {
			return array(
				'status'       => 'skipped',
				'input_bytes'  => $target['bytes'],
				'output_bytes' => null,
				'error_code'   => 'unsupported_format',
			);
		}
		$directory   = dirname( $target['source_path'] );
		$basename    = pathinfo( $target['source_path'], PATHINFO_FILENAME );
		$destination = $directory . DIRECTORY_SEPARATOR . $basename . '.jio.' . $extension;
		$uploads     = wp_upload_dir();
		$root        = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		if ( false === $root ) {
			return array(
				'status'       => 'skipped',
				'input_bytes'  => $target['bytes'],
				'output_bytes' => null,
				'error_code'   => 'uploads_unavailable',
			);
		}
		$relative_destination = $this->relative_path( $root, $destination );
		if ( null === $relative_destination ) {
			return array(
				'status'       => 'failed',
				'input_bytes'  => $target['bytes'],
				'output_bytes' => null,
				'error_code'   => 'path_outside_uploads',
			);
		}
		$meta_key   = '_jio_derivative_' . substr( hash( 'sha256', $target['relative_path'] . '|' . $format . '|' . $target['fingerprint'] ), 0, 32 );
		$old_record = get_post_meta( $attachment_id, $meta_key, true );
		if ( is_array( $old_record ) && hash_equals( $target['fingerprint'], (string) ( $old_record['source_fingerprint'] ?? '' ) ) && hash_equals( $relative_destination, (string) ( $old_record['derivative_relative_path'] ?? '' ) ) && is_file( $destination ) ) {
			return array(
				'status'       => 'completed',
				'input_bytes'  => $target['bytes'],
				'output_bytes' => (int) filesize( $destination ),
				'error_code'   => null,
			);
		}
		$settings   = Settings::sanitize( get_option( Plugin::OPTION_NAME, array() ) );
		$binary     = new BinaryLocator();
		$runner     = new ProcessRunner();
		$encoder_id = match ( $format ) {
			'image/webp' => 'cwebp',
			'image/avif' => 'avifenc',
			default      => 'image/jpeg' === $target['mime'] ? 'mozjpeg' : 'pngquant',
		};
		$registry = new EncoderRegistry(
			array(
				new MozJpegEncoder( $binary, $runner ),
				new JpegtranEncoder( $binary, $runner ),
				new PngquantEncoder( $binary, $runner ),
				new OxipngEncoder( $binary, $runner ),
				new CwebpEncoder( $binary, $runner ),
				new AvifEncoder( $binary, $runner ),
			)
		);
		try {
			$manager = new OptimizationManager( new PathGuard( array( $root ) ), new ImageValidator(), $registry, new TemporaryFileManager(), new SafeFileReplacer() );
			$request = new OptimizationRequest(
				$target['source_path'],
				$destination,
				$encoder_id,
				OptimizationRequest::MODE_LOSSY,
				$settings['image_quality'],
				$settings['minimum_quality'],
				false,
				52428800,
				40000000,
				120000,
				OptimizationRequest::OUTPUT_SOURCE === $format ? OptimizationRequest::OUTPUT_SOURCE : $format,
				OptimizationRequest::OUTPUT_SOURCE !== $format
			);
			$result  = $manager->optimize( $request );
		} catch ( \Throwable ) {
			return array(
				'status'       => 'failed',
				'input_bytes'  => $target['bytes'],
				'output_bytes' => null,
				'error_code'   => 'processor_initialization_failed',
			);
		}
		if ( OptimizationResult::OPTIMIZED !== $result->status ) {
			$status = in_array( $result->status, array( OptimizationResult::SKIPPED, OptimizationResult::NO_SAVINGS, OptimizationResult::UNSUPPORTED ), true ) ? 'skipped' : 'failed';
			return array(
				'status'       => $status,
				'input_bytes'  => $result->input_bytes,
				'output_bytes' => $result->output_bytes,
				'error_code'   => $result->error_category ?? 'optimization_' . $result->status,
			);
		}
		$record = array(
			'source_relative_path'     => $target['relative_path'],
			'source_fingerprint'       => $target['fingerprint'],
			'derivative_relative_path' => $relative_destination,
			'output_format'            => $format,
			'mime_type'                => $target_mime,
			'input_bytes'              => $result->input_bytes,
			'output_bytes'             => $result->output_bytes,
			'bytes_saved'              => $result->bytes_saved,
			'generated_gmt'            => gmdate( 'c' ),
		);
		update_post_meta( $attachment_id, $meta_key, $record );
		if ( ! hash_equals( wp_json_encode( $record ), wp_json_encode( get_post_meta( $attachment_id, $meta_key, true ) ) ) ) {
			return array(
				'status'       => 'failed',
				'input_bytes'  => $result->input_bytes,
				'output_bytes' => $result->output_bytes,
				'error_code'   => 'derivative_metadata_failed',
			);
		}
		return array(
			'status'       => 'completed',
			'input_bytes'  => $result->input_bytes,
			'output_bytes' => $result->output_bytes,
			'error_code'   => null,
		);
	}

	/** Returns a destination path relative to the current upload root.
	 *
	 * @param string $root Canonical uploads directory.
	 * @param string $path Destination path.
	 * @return string|null Relative path when confined.
	 */
	private function relative_path( string $root, string $path ): ?string {
		$root = rtrim( wp_normalize_path( $root ), '/' );
		$path = wp_normalize_path( $path );
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$matches = str_starts_with( strtolower( $path ), strtolower( $root . '/' ) );
		} else {
			$matches = str_starts_with( $path, $root . '/' );
		}
		return $matches ? substr( $path, strlen( $root ) + 1 ) : null;
	}
}
