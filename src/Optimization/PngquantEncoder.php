<?php
/**
 * Lossy PNG optimization using pngquant.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

/** Quantizes PNG palettes with an explicit quality floor. */
final class PngquantEncoder extends CommandEncoder {
	/** Returns the adapter identifier. */
	public function id(): string {
		return 'pngquant';
	}

	/** Returns accepted image MIME types. */
	public function supported_mime_types(): array {
		return array( 'image/png' );
	}

	/** Returns accepted processing modes. */
	public function supported_modes(): array {
		return array( OptimizationRequest::MODE_LOSSY );
	}

	/** Checks whether pngquant can be resolved. */
	public function is_available(): bool {
		return null !== $this->binary( 'pngquant' );
	}

	/** Quantizes a PNG within the request quality range.
	 *
	 * @param string              $source_path          Confined PNG path.
	 * @param string              $temporary_output_path Temporary output path.
	 * @param OptimizationRequest $request              Validated operation settings.
	 * @return EncoderExecution Encoder completion or quality-floor skip.
	 * @throws OptimizationException When metadata preservation is requested or pngquant fails.
	 */
	public function optimize( string $source_path, string $temporary_output_path, OptimizationRequest $request ): EncoderExecution {
		$binary = $this->binary( 'pngquant' );
		if ( null === $binary ) {
			throw new OptimizationException( 'encoder_unavailable', 'pngquant is not available.' );
		}
		if ( $request->preserve_metadata ) {
			throw new OptimizationException( 'metadata_preservation_unsupported', 'pngquant metadata preservation cannot be guaranteed for this request.' );
		}
		$range  = $request->minimum_quality . '-' . $request->quality;
		$result = $this->runner->run( $binary, array( '--quality=' . $range, '--speed=1', '--force', '--output', $temporary_output_path, '--', $source_path ), $request->timeout_milliseconds / 1000 );
		if ( 99 === $result->exit_code ) {
			return new EncoderExecution( EncoderExecution::SKIPPED, 'quality_floor_not_met' );
		}
		if ( 0 !== $result->exit_code ) {
			throw new OptimizationException( 'encoder_failed', 'pngquant returned an error.' );
		}
		return new EncoderExecution( EncoderExecution::COMPLETE );
	}
}
