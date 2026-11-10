<?php
/**
 * WebP conversion using Google's cwebp command-line encoder.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

/** Converts a validated JPEG or PNG into a still WebP image. */
final class CwebpEncoder extends CommandEncoder {
	/** Returns the stable adapter identifier. */
	public function id(): string {
		return 'cwebp';
	}

	/** Returns accepted source MIME types. */
	public function supported_mime_types(): array {
		return array( 'image/jpeg', 'image/png' );
	}

	/** Returns supported conversion modes. */
	public function supported_modes(): array {
		return array( OptimizationRequest::MODE_LOSSY, OptimizationRequest::MODE_LOSSLESS );
	}

	/** Checks whether cwebp can be located. */
	public function is_available(): bool {
		return null !== $this->binary( 'cwebp' );
	}

	/** Converts a source into a WebP staging file.
	 *
	 * @param string              $source_path          Source image path.
	 * @param string              $temporary_output_path Temporary WebP path.
	 * @param OptimizationRequest $request              Conversion settings.
	 * @return EncoderExecution Completion status.
	 * @throws OptimizationException When cwebp is unavailable or fails.
	 */
	public function optimize( string $source_path, string $temporary_output_path, OptimizationRequest $request ): EncoderExecution {
		$binary = $this->binary( 'cwebp' );
		if ( null === $binary ) {
			throw new OptimizationException( 'encoder_unavailable', 'The cwebp encoder is unavailable.' );
		}
		$args = array( '-quiet', '-m', '4' );
		if ( OptimizationRequest::MODE_LOSSLESS === $request->mode ) {
			$args[] = '-lossless';
			$args[] = '-exact';
		} else {
			$args[] = '-q';
			$args[] = (string) $request->quality;
		}
		$args[] = $source_path;
		$args[] = '-o';
		$args[] = $temporary_output_path;
		$this->execute( $binary, $args, $request );
		return new EncoderExecution( EncoderExecution::COMPLETE );
	}
}
