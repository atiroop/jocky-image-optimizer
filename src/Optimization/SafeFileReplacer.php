<?php
/**
 * No-clobber promotion of validated output files.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP_Filesystem has no atomic hard-link/no-replace promotion primitive; these native same-volume operations fail closed and roll back exact paths.

/** Promotes a same-filesystem temporary file without replacing existing data. */
final class SafeFileReplacer {

	/** Atomically creates the destination as a hard link to the complete temporary file.
	 *
	 * @param string $temporary_path   Validated temporary output.
	 * @param string $destination_path New destination path.
	 * @param string $source_path      Original source path.
	 * @throws OptimizationException When promotion fails or is unsupported.
	 */
	public function commit( string $temporary_path, string $destination_path, string $source_path ): void {
		if ( is_link( $temporary_path ) || ! is_file( $temporary_path ) || is_link( $destination_path ) || file_exists( $destination_path ) ) {
			throw new OptimizationException( 'commit_precondition_failed', 'The output promotion preconditions were not met.' );
		}
		$temp_directory = realpath( dirname( $temporary_path ) );
		$dest_directory = realpath( dirname( $destination_path ) );
		$temp_stat      = false === $temp_directory ? false : stat( $temp_directory );
		$dest_stat      = false === $dest_directory ? false : stat( $dest_directory );
		if ( false === $temp_directory || false === $dest_directory || false === $temp_stat || false === $dest_stat || $temp_stat['dev'] !== $dest_stat['dev'] || is_link( dirname( $temporary_path ) ) || realpath( $temporary_path ) === realpath( $source_path ) ) {
			throw new OptimizationException( 'commit_precondition_failed', 'The temporary file is not safely colocated with the destination.' );
		}
		if ( ! link( $temporary_path, $destination_path ) ) {
			throw new OptimizationException( 'atomic_commit_unsupported', 'This filesystem cannot atomically create the output without replacing an existing path.' );
		}
		if ( ! unlink( $temporary_path ) ) {
			if ( ! unlink( $destination_path ) ) {
				throw new OptimizationException( 'recovery_required', 'The temporary link could not be removed after output promotion.' );
			}
			throw new OptimizationException( 'temporary_cleanup_failed', 'Output promotion was rolled back because temporary cleanup failed.' );
		}
		if ( ! rmdir( dirname( $temporary_path ) ) ) {
			if ( ! unlink( $destination_path ) ) {
				throw new OptimizationException( 'recovery_required', 'The output was promoted but its temporary directory could not be removed or rolled back.' );
			}
			throw new OptimizationException( 'temporary_cleanup_failed', 'Output promotion was rolled back because temporary directory cleanup failed.' );
		}
	}
}
