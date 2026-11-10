<?php
/**
 * Encoder execution outcome.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

/** Reports whether an encoder produced output or intentionally skipped it. */
final class EncoderExecution {

	public const COMPLETE = 'complete';
	public const SKIPPED  = 'skipped';

	/** Creates an encoder outcome.
	 *
	 * @param string      $status         Completion status.
	 * @param string|null $error_category Optional stable skip/failure category.
	 */
	public function __construct(
		public readonly string $status,
		public readonly ?string $error_category = null
	) {
	}
}
