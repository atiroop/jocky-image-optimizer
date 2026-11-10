<?php
/**
 * Structured outcome for an optimization request.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

/** Reports measured processing output without inventing savings. */
final class OptimizationResult {

	public const OPTIMIZED   = 'optimized';
	public const SKIPPED     = 'skipped';
	public const UNSUPPORTED = 'unsupported';
	public const UNAVAILABLE = 'unavailable';
	public const FAILED      = 'failed';

	/** Creates an immutable operation result.
	 *
	 * @param string      $source_format        Detected source MIME type.
	 * @param int         $input_bytes          Measured input size.
	 * @param int|null    $output_bytes         Measured output size if available.
	 * @param int         $bytes_saved          Input minus output bytes.
	 * @param float       $savings_percentage   Measured percentage change.
	 * @param string|null $encoder_id           Encoder identifier.
	 * @param float       $duration_milliseconds Total operation duration.
	 * @param string      $status               Result status.
	 * @param string|null $error_category       Stable error category.
	 * @param string|null $output_path          Committed output path.
	 */
	public function __construct(
		public readonly string $source_format,
		public readonly int $input_bytes,
		public readonly ?int $output_bytes,
		public readonly int $bytes_saved,
		public readonly float $savings_percentage,
		public readonly ?string $encoder_id,
		public readonly float $duration_milliseconds,
		public readonly string $status,
		public readonly ?string $error_category,
		public readonly ?string $output_path
	) {
	}
}
