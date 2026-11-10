<?php
/**
 * Safe, categorized optimization error.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

use RuntimeException;

/** Carries a non-sensitive error category suitable for API results. */
final class OptimizationException extends RuntimeException {

	/** Creates a categorized exception.
	 *
	 * @param string $category Stable machine-readable category.
	 * @param string $message  Non-sensitive description.
	 */
	public function __construct( string $category, string $message ) {
		parent::__construct( $message );
		$this->category = $category;
	}

	/** Stable machine-readable error category.
	 *
	 * @var string
	 */
	public readonly string $category;
}
