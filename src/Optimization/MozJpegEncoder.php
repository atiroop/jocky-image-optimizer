<?php
/**
 * Lossy JPEG optimization using djpeg and MozJPEG cjpeg.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.unlink_unlink -- The external decoder requires a private local PPM path, and cleanup must target that exact temporary file.

/** Decodes to PPM before encoding, avoiding accidental JPEG double compression. */
final class MozJpegEncoder extends CommandEncoder {
	/** Returns the adapter identifier. */
	public function id(): string {
		return 'mozjpeg';
	}

	/** Returns accepted image MIME types. */
	public function supported_mime_types(): array {
		return array( 'image/jpeg' );
	}

	/** Returns accepted processing modes. */
	public function supported_modes(): array {
		return array( OptimizationRequest::MODE_LOSSY );
	}

	/** Checks whether both the decoder and encoder can be resolved. */
	public function is_available(): bool {
		return null !== $this->binary( 'djpeg' ) && null !== $this->binary( 'cjpeg' );
	}

	/** Decodes JPEG to PPM before lossy re-encoding.
	 *
	 * @param string              $source_path          Confined JPEG path.
	 * @param string              $temporary_output_path Temporary JPEG output path.
	 * @param OptimizationRequest $request              Validated operation settings.
	 * @return EncoderExecution Completed operation.
	 * @throws OptimizationException When metadata preservation is requested or a tool fails.
	 */
	public function optimize( string $source_path, string $temporary_output_path, OptimizationRequest $request ): EncoderExecution {
		if ( $request->preserve_metadata ) {
			throw new OptimizationException( 'metadata_preservation_unsupported', 'The lossy JPEG adapter cannot guarantee preservation of source metadata.' );
		}
		$djpeg = $this->binary( 'djpeg' );
		$cjpeg = $this->binary( 'cjpeg' );
		if ( null === $djpeg || null === $cjpeg ) {
			throw new OptimizationException( 'encoder_unavailable', 'Both djpeg and cjpeg are required for lossy JPEG optimization.' );
		}
		$ppm    = dirname( $temporary_output_path ) . DIRECTORY_SEPARATOR . '.jio-' . bin2hex( random_bytes( 12 ) ) . '.ppm';
		$handle = fopen( $ppm, 'x+b' );
		if ( false === $handle ) {
			throw new OptimizationException( 'temporary_file_failed', 'The JPEG decoder temporary file could not be reserved.' );
		}
		fclose( $handle );
		try {
			$this->execute( $djpeg, array( '-outfile', $ppm, $source_path ), $request );
			$this->execute( $cjpeg, array( '-quality', (string) $request->quality, '-optimize', '-progressive', '-outfile', $temporary_output_path, $ppm ), $request );
		} finally {
			if ( is_file( $ppm ) && ! is_link( $ppm ) ) {
				unlink( $ppm );
			}
		}
		return new EncoderExecution( EncoderExecution::COMPLETE );
	}
}
