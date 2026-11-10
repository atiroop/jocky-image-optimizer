<?php
/**
 * Contract for a local image encoder.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

/** Exposes encoder capabilities and execution without WordPress dependencies. */
interface EncoderInterface {

	/** Returns this adapter's stable identifier. */
	public function id(): string;

	/** Returns supported MIME types. */
	public function supported_mime_types(): array;

	/** Returns supported processing modes. */
	public function supported_modes(): array;

	/** Returns true when the required validated binary paths are available. */
	public function is_available(): bool;

	/** Executes the encoder into a pre-created temporary output file.
	 *
	 * @param string              $source_path          Confined source image path.
	 * @param string              $temporary_output_path Exclusive temporary output path.
	 * @param OptimizationRequest $request              Validated processing options.
	 * @return EncoderExecution Execution status.
	 */
	public function optimize( string $source_path, string $temporary_output_path, OptimizationRequest $request ): EncoderExecution;
}
