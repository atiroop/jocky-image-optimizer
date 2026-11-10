<?php
/**
 * Registry of explicitly supported image encoder adapters.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

/** Resolves encoder identifiers without dynamic class loading. */
final class EncoderRegistry {
	/** Encoder adapters indexed by stable identifier.
	 *
	 * @var array<string, EncoderInterface>
	 */
	private array $encoders = array();

	/** Registers the available adapters.
	 *
	 * @param EncoderInterface[] $encoders Encoder instances.
	 */
	public function __construct( array $encoders ) {
		foreach ( $encoders as $encoder ) {
			$this->encoders[ $encoder->id() ] = $encoder;
		}
	}

	/** Returns a registered adapter, if found.
	 *
	 * @param string $id Stable encoder identifier.
	 * @return EncoderInterface|null Matching adapter.
	 */
	public function get( string $id ): ?EncoderInterface {
		return $this->encoders[ $id ] ?? null;
	}
}
