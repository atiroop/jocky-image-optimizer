<?php
/**
 * AVIF conversion using the libavif avifenc command-line encoder.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

/** Converts a validated JPEG or PNG into a still AVIF image. */
final class AvifEncoder extends CommandEncoder {
	/** Returns the stable adapter identifier. */
	public function id(): string {
		return 'avifenc';
	}

	/** Returns accepted source MIME types. */
	public function supported_mime_types(): array {
		return array( 'image/jpeg', 'image/png' );
	}

	/** Returns supported conversion modes. */
	public function supported_modes(): array {
		return array( OptimizationRequest::MODE_LOSSY, OptimizationRequest::MODE_LOSSLESS );
	}

	/** Checks whether avifenc can be located. */
	public function is_available(): bool {
		return null !== $this->binary( 'avifenc' );
	}

	/** Converts a source into an AVIF staging file.
	 *
	 * @param string              $source_path          Source image path.
	 * @param string              $temporary_output_path Temporary AVIF path.
	 * @param OptimizationRequest $request              Conversion settings.
	 * @return EncoderExecution Completion status.
	 * @throws OptimizationException When avifenc is unavailable or fails.
	 */
	public function optimize( string $source_path, string $temporary_output_path, OptimizationRequest $request ): EncoderExecution {
		$binary = $this->binary( 'avifenc' );
		if ( null === $binary ) {
			throw new OptimizationException( 'encoder_unavailable', 'The avifenc encoder is unavailable.' );
		}
		$version_result = $this->runner->run( $binary, array( '--version' ), min( 5, $request->timeout_milliseconds / 1000 ) );
		$version_output = $version_result->stdout . ' ' . $version_result->stderr;
		if ( 0 !== $version_result->exit_code || 1 !== preg_match( '/\b(\d+)\.(\d+)\.(\d+)\b/', $version_output, $version_parts ) ) {
			throw new OptimizationException( 'encoder_version_unknown', 'The installed avifenc version could not be identified.' );
		}
		$version_id = ( (int) $version_parts[1] * 10000 ) + ( (int) $version_parts[2] * 100 ) + (int) $version_parts[3];
		$args       = array( '--jobs', '2', '--speed', (string) $request->encoding_speed );
		if ( OptimizationRequest::MODE_LOSSLESS === $request->mode ) {
			$args[] = '--lossless';
		} elseif ( $version_id >= 10200 ) {
			$args[] = '--qcolor';
			$args[] = (string) $request->quality;
			$args[] = '--qalpha';
			$args[] = '100';
		} else {
			$quantizer = (string) (int) round( ( 100 - $request->quality ) * 63 / 99 );
			$args[]    = '--min';
			$args[]    = $quantizer;
			$args[]    = '--max';
			$args[]    = $quantizer;
			$args[]    = '--minalpha';
			$args[]    = '0';
			$args[]    = '--maxalpha';
			$args[]    = '0';
		}
		$args[] = $source_path;
		$args[] = $temporary_output_path;
		$this->execute( $binary, $args, $request );
		return new EncoderExecution( EncoderExecution::COMPLETE );
	}
}
