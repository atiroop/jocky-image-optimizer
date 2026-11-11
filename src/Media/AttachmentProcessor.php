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
use Jocky\ImageOptimizer\Recovery\AttachmentOperationLock;
use Jocky\ImageOptimizer\Recovery\DerivativeRepository;
use Jocky\ImageOptimizer\Recovery\IntegrityHasher;
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
		$attachment_id = (int) ( $job['attachment_id'] ?? 0 );
		$lock          = new AttachmentOperationLock();
		if ( ! $lock->acquire( $attachment_id ) ) {
			return array(
				'status'       => 'failed',
				'input_bytes'  => 0,
				'output_bytes' => null,
				'error_code'   => 'attachment_operation_locked',
			);
		}
		try {
			return $this->process_locked( $job );
		} finally {
			$lock->release( $attachment_id );
		}
	}

	/** Executes processing while holding the per-attachment optimizer/recovery lock.
	 *
	 * @param array<string, mixed> $job Claimed database queue row.
	 * @return array{status:string,input_bytes:int,output_bytes:int|null,error_code:string|null} Bounded outcome.
	 */
	private function process_locked( array $job ): array {
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
		$meta_key    = '_jio_derivative_' . substr( hash( 'sha256', $target['relative_path'] . '|' . $format . '|' . $target['fingerprint'] ), 0, 32 );
		$old_record  = get_post_meta( $attachment_id, $meta_key, true );
		$hasher      = new IntegrityHasher();
		$source_hash = $hasher->hash_file( $target['source_path'] );
		if ( null === $source_hash ) {
			return array(
				'status'       => 'failed',
				'input_bytes'  => $target['bytes'],
				'output_bytes' => null,
				'error_code'   => 'source_integrity_unavailable',
			);
		}
		if ( is_array( $old_record ) && hash_equals( $target['fingerprint'], (string) ( $old_record['source_fingerprint'] ?? '' ) ) && hash_equals( $relative_destination, (string) ( $old_record['derivative_relative_path'] ?? '' ) ) && is_file( $destination ) && ! is_link( $destination ) ) {
			$existing_hash   = $hasher->hash_file( $destination );
			$old_output_hash = (string) ( $old_record['derivative_sha256'] ?? '' );
			$old_source_hash = (string) ( $old_record['source_sha256'] ?? '' );
			if ( null !== $existing_hash && ( '' === $old_output_hash || hash_equals( $old_output_hash, $existing_hash['sha256'] ) ) && ( '' === $old_source_hash || hash_equals( $old_source_hash, $source_hash['sha256'] ) ) && wp_get_image_mime( $destination ) === $target_mime ) {
				$ownership_id                    = $this->persist_ownership( $attachment_id, $target, $relative_destination, $format, $target_mime, $source_hash, $existing_hash, (string) ( $old_record['encoder_id'] ?? 'legacy' ), array() );
				$old_record['source_sha256']     = $source_hash['sha256'];
				$old_record['derivative_sha256'] = $existing_hash['sha256'];
				$old_record['encoder_id']        = (string) ( $old_record['encoder_id'] ?? 'legacy' );
				if ( false === $ownership_id ) {
					return array(
						'status'       => 'failed',
						'input_bytes'  => $target['bytes'],
						'output_bytes' => $existing_hash['bytes'],
						'error_code'   => 'derivative_registry_failed',
					);
				}
				update_post_meta( $attachment_id, $meta_key, $old_record );
				return array(
					'status'       => 'completed',
					'input_bytes'  => $target['bytes'],
					'output_bytes' => $existing_hash['bytes'],
					'error_code'   => null,
				);
			}
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
		$source_after = $hasher->hash_file( $target['source_path'] );
		$output_hash  = $hasher->hash_file( $destination );
		if ( null === $source_after || ! hash_equals( $source_hash['sha256'], $source_after['sha256'] ) || null === $output_hash ) {
			return array(
				'status'       => 'failed',
				'input_bytes'  => $result->input_bytes,
				'output_bytes' => $result->output_bytes,
				'error_code'   => 'integrity_verification_failed',
			);
		}
		$record       = array(
			'source_relative_path'     => $target['relative_path'],
			'source_fingerprint'       => $target['fingerprint'],
			'derivative_relative_path' => $relative_destination,
			'output_format'            => $format,
			'mime_type'                => $target_mime,
			'input_bytes'              => $result->input_bytes,
			'output_bytes'             => $result->output_bytes,
			'bytes_saved'              => $result->bytes_saved,
			'source_sha256'            => $source_hash['sha256'],
			'derivative_sha256'        => $output_hash['sha256'],
			'encoder_id'               => $result->encoder_id ?? $encoder_id,
			'generated_gmt'            => gmdate( 'c' ),
		);
		$ownership_id = $this->persist_ownership(
			$attachment_id,
			$target,
			$relative_destination,
			$format,
			$target_mime,
			$source_hash,
			$output_hash,
			$result->encoder_id ?? $encoder_id,
			array(
				'quality'         => $settings['image_quality'],
				'minimum_quality' => $settings['minimum_quality'],
				'mode'            => OptimizationRequest::MODE_LOSSY,
			)
		);
		if ( false === $ownership_id ) {
			return array(
				'status'       => 'failed',
				'input_bytes'  => $result->input_bytes,
				'output_bytes' => $result->output_bytes,
				'error_code'   => 'derivative_registry_failed',
			);
		}
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

	/** Persists portable, digest-backed ownership metadata for one validated derivative.
	 *
	 * @param int                            $attachment_id Attachment ID.
	 * @param array<string, mixed>           $target Resolved attachment source.
	 * @param string                         $derivative_relative_path Portable uploads-relative derivative path.
	 * @param string                         $format Output format.
	 * @param string                         $mime Output MIME type.
	 * @param array{sha256:string,bytes:int} $source_hash Source digest.
	 * @param array{sha256:string,bytes:int} $derivative_hash Derivative digest.
	 * @param string                         $encoder_id Trusted encoder identifier.
	 * @param array<string, scalar>          $parameters Bounded encoder parameters.
	 * @return int|false Registry row identifier or false on failure.
	 */
	private function persist_ownership( int $attachment_id, array $target, string $derivative_relative_path, string $format, string $mime, array $source_hash, array $derivative_hash, string $encoder_id, array $parameters ): int|false {
		$uploads       = wp_upload_dir();
		$path          = empty( $uploads['error'] ) && isset( $uploads['basedir'] ) ? trailingslashit( $uploads['basedir'] ) . str_replace( '/', DIRECTORY_SEPARATOR, $derivative_relative_path ) : '';
		$stat          = '' !== $path && ! is_link( $path ) && is_file( $path ) ? stat( $path ) : false;
		$identity      = false !== $stat ? implode( ':', array( (int) $stat['dev'], (int) $stat['ino'], (int) $stat['size'], (int) $stat['mtime'] ) ) : '';
		$metadata      = wp_get_attachment_metadata( $attachment_id );
		$attached      = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$metadata_hash = hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'attached_file' => $attached,
					'metadata'      => is_array( $metadata ) ? $metadata : array(),
				)
			)
		);
		$record_id     = ( new DerivativeRepository() )->record(
			array(
				'attachment_id'            => $attachment_id,
				'variant_key'              => $target['variant_key'],
				'source_relative_path'     => $target['relative_path'],
				'source_mime'              => $target['mime'],
				'source_bytes'             => $source_hash['bytes'],
				'source_sha256'            => $source_hash['sha256'],
				'source_fingerprint'       => $target['fingerprint'],
				'metadata_hash'            => $metadata_hash,
				'derivative_relative_path' => $derivative_relative_path,
				'output_format'            => $format,
				'derivative_sha256'        => $derivative_hash['sha256'],
				'derivative_identity'      => $identity,
				'input_bytes'              => $source_hash['bytes'],
				'output_bytes'             => $derivative_hash['bytes'],
				'encoder_id'               => $encoder_id,
				'encoder_parameters'       => (string) wp_json_encode( $parameters ),
			)
		);
		if ( false !== $record_id ) {
			do_action( 'jio_derivative_updated', $attachment_id );
		}
		return $record_id;
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
