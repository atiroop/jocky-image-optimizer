<?php
/**
 * Validated source/output image facts.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

/** Metadata used by the optimizer and its result reporting. */
final class ImageInfo {
	/** Stores validated image facts.
	 *
	 * @param string              $mime     Detected image MIME type.
	 * @param int                 $width    Image width in pixels.
	 * @param int                 $height   Image height in pixels.
	 * @param int                 $bytes    Measured source bytes.
	 * @param array<string, bool> $metadata Detected metadata categories.
	 * @param bool                $animated Whether the image is animated.
	 */
	public function __construct(
		public readonly string $mime,
		public readonly int $width,
		public readonly int $height,
		public readonly int $bytes,
		public readonly array $metadata = array(),
		public readonly bool $animated = false
	) {}
}
