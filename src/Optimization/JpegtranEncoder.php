<?php
/**
 * Lossless JPEG optimization using jpegtran.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

/** Performs coefficient-domain JPEG optimization and retains all markers. */
final class JpegtranEncoder extends CommandEncoder {
	/** Returns the adapter identifier. */
	public function id(): string {
		return 'jpegtran';
	}

	/** Returns accepted image MIME types. */
	public function supported_mime_types(): array {
		return array( 'image/jpeg' );
	}

	/** Returns accepted processing modes. */
	public function supported_modes(): array {
		return array( OptimizationRequest::MODE_LOSSLESS );
	}

	/** Checks whether jpegtran can be resolved. */
	public function is_available(): bool {
		return null !== $this->binary( 'jpegtran' );
	}

	/** Performs lossless coefficient optimization while retaining JPEG markers.
	 *
	 * @param string              $source_path          Confined JPEG path.
	 * @param string              $temporary_output_path Temporary output path.
	 * @param OptimizationRequest $request              Validated operation settings.
	 * @return EncoderExecution Completed operation.
	 * @throws OptimizationException When jpegtran is unavailable or fails.
	 */
	public function optimize( string $source_path, string $temporary_output_path, OptimizationRequest $request ): EncoderExecution {
		$binary = $this->binary( 'jpegtran' );
		if ( null === $binary ) {
			throw new OptimizationException( 'encoder_unavailable', 'jpegtran is not available.' );
		}
		$this->execute( $binary, array( '-copy', 'all', '-optimize', '-progressive', '-outfile', $temporary_output_path, $source_path ), $request );
		return new EncoderExecution( EncoderExecution::COMPLETE );
	}
}
