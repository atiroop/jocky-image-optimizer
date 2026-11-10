<?php
/**
 * Immutable request for a local image optimization operation.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

use InvalidArgumentException;

/** Holds trusted encoder selection and bounded operation settings. */
final class OptimizationRequest {

	public const MODE_LOSSY    = 'lossy';
	public const MODE_LOSSLESS = 'lossless';

	/** Creates a validated optimization request.
	 *
	 * @param string $source_path          Source file path.
	 * @param string $destination_path     Separate output path.
	 * @param string $encoder_id           Registered encoder identifier.
	 * @param string $mode                 Lossy or lossless processing mode.
	 * @param int    $quality              Encoder-specific quality value.
	 * @param int    $minimum_quality      Minimum acceptable lossy quality.
	 * @param bool   $preserve_metadata    Whether metadata retention is required.
	 * @param int    $maximum_input_bytes  Maximum input size.
	 * @param int    $maximum_pixels       Maximum pixel count.
	 * @param int    $timeout_milliseconds Maximum process duration.
	 * @throws InvalidArgumentException When values are outside supported ranges.
	 */
	public function __construct(
		public readonly string $source_path,
		public readonly string $destination_path,
		public readonly string $encoder_id,
		public readonly string $mode,
		public readonly int $quality = 80,
		public readonly int $minimum_quality = 65,
		public readonly bool $preserve_metadata = true,
		public readonly int $maximum_input_bytes = 52428800,
		public readonly int $maximum_pixels = 40000000,
		public readonly int $timeout_milliseconds = 120000
	) {
		if ( '' === $source_path || '' === $destination_path || '' === $encoder_id ) {
			throw new InvalidArgumentException( 'Source, destination, and encoder are required.' );
		}
		if ( ! in_array( $mode, array( self::MODE_LOSSY, self::MODE_LOSSLESS ), true ) ) {
			throw new InvalidArgumentException( 'The optimization mode is unsupported.' );
		}
		if ( $quality < 1 || $quality > 100 || $minimum_quality < 0 || $minimum_quality > $quality ) {
			throw new InvalidArgumentException( 'Quality values are outside the supported range.' );
		}
		if ( $maximum_input_bytes < 1 || $maximum_pixels < 1 || $timeout_milliseconds < 1 || $timeout_milliseconds > 600000 ) {
			throw new InvalidArgumentException( 'Resource limits are outside the supported range.' );
		}
	}
}
