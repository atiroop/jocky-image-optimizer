<?php
/**
 * Shared process-backed encoder adapter base.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

use Jocky\ImageOptimizer\System\BinaryLocator;
use Jocky\ImageOptimizer\System\ProcessRunner;

/** Supplies safe binary lookup and process execution to concrete adapters. */
abstract class CommandEncoder implements EncoderInterface {
	/** Creates the shared binary lookup and process runner dependencies.
	 *
	 * @param BinaryLocator $binaries Binary locator.
	 * @param ProcessRunner $runner   Bounded process runner.
	 */
	public function __construct( protected BinaryLocator $binaries, protected ProcessRunner $runner ) {}

	/** Resolves a supported binary identifier.
	 *
	 * @param string $id Known binary ID.
	 * @return string|null Absolute path when available.
	 */
	protected function binary( string $id ): ?string {
		return $this->binaries->locate( $id );
	}

	/** Executes an adapter command and requires a zero exit status.
	 *
	 * @param string              $binary    Absolute executable path.
	 * @param array<string>       $arguments Command arguments, excluding executable.
	 * @param OptimizationRequest $request   Validated operation options.
	 * @throws OptimizationException When the command exits unsuccessfully or times out.
	 */
	protected function execute( string $binary, array $arguments, OptimizationRequest $request ): void {
		$result = $this->runner->run( $binary, $arguments, $request->timeout_milliseconds / 1000 );
		if ( 0 !== $result->exit_code ) {
			throw new OptimizationException( 'encoder_failed', 'The selected image encoder returned an error.' );
		}
	}
}
