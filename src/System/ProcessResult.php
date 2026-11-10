<?php
/**
 * Captured encoder process result.
 *
 * @package Jocky\ImageOptimizer\System
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\System;

/** Bounded result from an external process. */
final class ProcessResult {
	/** Creates a bounded process result.
	 *
	 * @param int    $exit_code        Process exit code.
	 * @param string $stdout           Captured stdout.
	 * @param string $stderr           Captured stderr.
	 * @param float  $duration_seconds Elapsed runtime.
	 */
	public function __construct(
		public readonly int $exit_code,
		public readonly string $stdout,
		public readonly string $stderr,
		public readonly float $duration_seconds
	) {}
}
