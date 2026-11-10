<?php
/**
 * Coordinates safe, explicit image optimization operations.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

/** Processes only caller-selected files and writes a separate output file. */
final class OptimizationManager {
	/** Creates the orchestration service dependencies.
	 *
	 * @param PathGuard            $paths           Path confinement service.
	 * @param ImageValidator       $validator       Input/output validator.
	 * @param EncoderRegistry      $encoders        Supported adapters.
	 * @param TemporaryFileManager $temporary_files Temporary-file manager.
	 * @param SafeFileReplacer     $replacer        No-clobber promoter.
	 */
	public function __construct(
		private PathGuard $paths,
		private ImageValidator $validator,
		private EncoderRegistry $encoders,
		private TemporaryFileManager $temporary_files,
		private SafeFileReplacer $replacer
	) {}

	/** Last cleanup error category, if one occurred.
	 *
	 * @var string|null
	 */
	private ?string $cleanup_error_category = null;


	/** Optimizes one explicitly selected source to a separate destination.
	 *
	 * @param OptimizationRequest $request Validated source, destination, encoder, and limits.
	 * @return OptimizationResult Measured outcome and stable status.
	 * @throws OptimizationException Only when cleanup failure prevents returning a safe result.
	 */
	public function optimize( OptimizationRequest $request ): OptimizationResult {
		$this->cleanup_error_category = null;
		$started                      = microtime( true );
		$source                       = null;
		$input                        = 0;
		$format                       = 'unknown';
		$temporary                    = null;
		try {
			$source      = $this->paths->source( $request->source_path );
			$destination = $this->paths->destination( $request->destination_path, $source );
			$before_hash = hash_file( 'sha256', $source );
			$source_info = $this->validator->inspect( $source, $request );
			$input       = $source_info->bytes;
			$format      = $source_info->mime;
			if ( $source_info->animated ) {
				throw new OptimizationException( 'unsupported_animation', 'Animated image inputs are not supported by this engine.' );
			}
			if ( OptimizationRequest::OUTPUT_SOURCE !== $request->output_format && ( isset( $source_info->metadata['exif'] ) || isset( $source_info->metadata['exif_xmp'] ) ) ) {
				throw new OptimizationException( 'orientation_unsupported', 'EXIF-bearing sources are rejected because the engine cannot guarantee orientation preservation during conversion.' );
			}
			if ( OptimizationRequest::OUTPUT_SOURCE !== $request->output_format && $request->preserve_metadata && array() !== $source_info->metadata ) {
				throw new OptimizationException( 'metadata_preservation_unsupported', 'The selected conversion cannot guarantee faithful preservation of source metadata and orientation.' );
			}
			$encoder = $this->encoders->get( $request->encoder_id );
			if ( null === $encoder || ! in_array( $format, $encoder->supported_mime_types(), true ) || ! in_array( $request->mode, $encoder->supported_modes(), true ) ) {
				return $this->result( $format, $input, null, $request->encoder_id, $started, OptimizationResult::UNSUPPORTED, 'unsupported_encoder_or_mode', null );
			}
			if ( ! $encoder->is_available() ) {
				return $this->result( $format, $input, null, $request->encoder_id, $started, OptimizationResult::UNAVAILABLE, 'encoder_unavailable', null );
			}
			$target_mime = match ( $request->output_format ) {
				OptimizationRequest::OUTPUT_WEBP => 'image/webp',
				OptimizationRequest::OUTPUT_AVIF => 'image/avif',
				default => $format,
			};
			if ( ( 'cwebp' === $request->encoder_id && OptimizationRequest::OUTPUT_WEBP !== $request->output_format ) || ( 'avifenc' === $request->encoder_id && OptimizationRequest::OUTPUT_AVIF !== $request->output_format ) || ( ! in_array( $request->encoder_id, array( 'cwebp', 'avifenc' ), true ) && OptimizationRequest::OUTPUT_SOURCE !== $request->output_format ) ) {
				return $this->result( $format, $input, null, $request->encoder_id, $started, OptimizationResult::UNSUPPORTED, 'output_format_mismatch', null );
			}
			$suffix    = 'image/webp' === $target_mime ? '.webp' : ( 'image/avif' === $target_mime ? '.avif' : '.tmp' );
			$temporary = $this->temporary_files->create( dirname( $destination ), $suffix );
			$execution = $encoder->optimize( $source, $temporary, $request );
			if ( EncoderExecution::SKIPPED === $execution->status ) {
				return $this->result( $format, $input, null, $encoder->id(), $started, OptimizationResult::SKIPPED, $execution->error_category, null );
			}
			if ( ! hash_equals( (string) $before_hash, (string) hash_file( 'sha256', $source ) ) ) {
				throw new OptimizationException( 'source_changed', 'The source image changed during processing.' );
			}
			$output_info = $this->validator->inspect( $temporary, $request );
			if ( $output_info->animated ) {
				throw new OptimizationException( 'output_validation_failed', 'The encoder output unexpectedly contains animation.' );
			}
			if ( $output_info->mime !== $target_mime || $output_info->width !== $source_info->width || $output_info->height !== $source_info->height ) {
				throw new OptimizationException( 'output_validation_failed', 'The encoder output format or dimensions do not match the source.' );
			}
			if ( $source_info->alpha && ! $output_info->alpha ) {
				throw new OptimizationException( 'alpha_validation_failed', 'The output does not contain the source alpha channel.' );
			}
			if ( OptimizationRequest::OUTPUT_SOURCE === $request->output_format && $request->preserve_metadata && array_diff_key( $source_info->metadata, $output_info->metadata ) ) {
				throw new OptimizationException( 'metadata_validation_failed', 'The encoder output did not retain all detected source metadata.' );
			}
			$output = $output_info->bytes;
			$saved  = $input - $output;
			if ( $saved <= 0 && ! $request->allow_larger_output ) {
				$status = OptimizationRequest::OUTPUT_SOURCE === $request->output_format ? OptimizationResult::SKIPPED : OptimizationResult::NO_SAVINGS;
				return $this->result( $format, $input, $output, $encoder->id(), $started, $status, 'no_size_benefit', null );
			}
			$this->replacer->commit( $temporary, $destination, $source );
			$this->temporary_files->forget( $temporary );
			$temporary = null;
			return $this->result( $format, $input, $output, $encoder->id(), $started, OptimizationResult::OPTIMIZED, null, $destination );
		} catch ( OptimizationException $exception ) {
			$status = in_array( $exception->category, array( 'encoder_unavailable' ), true ) ? OptimizationResult::UNAVAILABLE : ( in_array( $exception->category, array( 'unsupported_format', 'unsupported_animation', 'unknown_encoder' ), true ) ? OptimizationResult::UNSUPPORTED : OptimizationResult::FAILED );
			return $this->result( $format, $input, null, $request->encoder_id, $started, $status, $exception->category, null );
		} catch ( \Throwable ) {
			return $this->result( $format, $input, null, $request->encoder_id, $started, OptimizationResult::FAILED, 'unexpected_error', null );
		} finally {
			if ( null !== $temporary && is_file( $temporary ) && ! is_link( $temporary ) ) {
				try {
					$this->temporary_files->remove( $temporary );
				} catch ( OptimizationException $cleanup_exception ) {
					$this->cleanup_error_category = $cleanup_exception->category;
				}
			}
		}
	}

	/** Returns the most recent temporary cleanup failure category.
	 *
	 * @return string|null Cleanup failure category or null.
	 */
	public function cleanup_error_category(): ?string {
		return $this->cleanup_error_category;
	}

	/** Builds the immutable measured result object.
	 *
	 * @param string      $format  Source MIME type.
	 * @param int         $input   Input bytes.
	 * @param int|null    $output  Output bytes when generated.
	 * @param string|null $encoder Encoder identifier.
	 * @param float       $started Start time from microtime().
	 * @param string      $status  Result status.
	 * @param string|null $error   Stable error category.
	 * @param string|null $path    Committed output path.
	 * @return OptimizationResult Result values.
	 */
	private function result( string $format, int $input, ?int $output, ?string $encoder, float $started, string $status, ?string $error, ?string $path ): OptimizationResult {
		$saved      = null === $output ? 0 : $input - $output;
		$percentage = $input > 0 && null !== $output ? ( $saved / $input ) * 100 : 0.0;
		return new OptimizationResult( $format, $input, $output, $saved, $percentage, $encoder, ( microtime( true ) - $started ) * 1000, $status, $error, $path );
	}
}
