<?php
/**
 * Lossless PNG optimization using oxipng.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

/** Optimizes PNGs while asking oxipng to retain metadata. */
final class OxipngEncoder extends CommandEncoder {
	/** Returns the adapter identifier. */
	public function id(): string {
		return 'oxipng';
	}

	/** Returns accepted image MIME types. */
	public function supported_mime_types(): array {
		return array( 'image/png' );
	}

	/** Returns accepted processing modes. */
	public function supported_modes(): array {
		return array( OptimizationRequest::MODE_LOSSLESS );
	}

	/** Checks whether oxipng can be resolved. */
	public function is_available(): bool {
		return null !== $this->binary( 'oxipng' );
	}

	/** Performs lossless PNG optimization.
	 *
	 * @param string              $source_path          Confined PNG path.
	 * @param string              $temporary_output_path Temporary output path.
	 * @param OptimizationRequest $request              Validated operation settings.
	 * @return EncoderExecution Completed operation.
	 * @throws OptimizationException When oxipng is unavailable or fails.
	 */
	public function optimize( string $source_path, string $temporary_output_path, OptimizationRequest $request ): EncoderExecution {
		$binary = $this->binary( 'oxipng' );
		if ( null === $binary ) {
			throw new OptimizationException( 'encoder_unavailable', 'oxipng is not available.' );
		}
		$arguments = array( '--opt', '3', '--out', $temporary_output_path );
		if ( $request->preserve_metadata ) {
			$arguments[] = '--preserve';
		}
		$arguments[] = '--';
		$arguments[] = $source_path;
		$this->execute( $binary, $arguments, $request );
		return new EncoderExecution( EncoderExecution::COMPLETE );
	}
}
