<?php
/**
 * Secure temporary-file creation and cleanup.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Exclusive local creation in private mode-0700 directories and exact-file cleanup are required for untrusted image outputs; WP_Filesystem has no exclusive create primitive.

/** Creates exclusive temporary files next to destinations for same-volume promotion. */
final class TemporaryFileManager {
	/** Files and private directories reserved by this manager instance.
	 *
	 * @var array<string, string>
	 */
	private array $registered_files = array();

	/** Creates a private temporary file with a requested safe suffix.
	 *
	 * @param string $directory Destination directory.
	 * @param string $suffix    Safe filename suffix.
	 * @return string Newly reserved temporary path.
	 * @throws OptimizationException When the suffix or filesystem operation is invalid.
	 */
	public function create( string $directory, string $suffix ): string {
		if ( ! preg_match( '/^\.[a-z0-9]{1,8}$/i', $suffix ) ) {
			throw new OptimizationException( 'invalid_temp_suffix', 'The temporary-file suffix is invalid.' );
		}
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$private_directory = rtrim( $directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '.jio-' . bin2hex( random_bytes( 12 ) );
			if ( ! mkdir( $private_directory, 0700 ) ) {
				continue;
			}
			if ( ! chmod( $private_directory, 0700 ) ) {
				rmdir( $private_directory );
				throw new OptimizationException( 'temporary_file_permissions_failed', 'The temporary directory permissions could not be restricted.' );
			}
			$path   = $private_directory . DIRECTORY_SEPARATOR . 'output' . $suffix;
			$handle = fopen( $path, 'x+b' );
			if ( false !== $handle ) {
				fclose( $handle );
				if ( ! chmod( $path, 0600 ) ) {
					unlink( $path );
					rmdir( $private_directory );
					throw new OptimizationException( 'temporary_file_permissions_failed', 'The temporary file permissions could not be restricted.' );
				}
				$this->registered_files[ $path ] = $private_directory;
				return $path;
			}
			rmdir( $private_directory );
		}
		throw new OptimizationException( 'temporary_file_failed', 'A secure temporary file could not be created.' );
	}

	/** Removes one exact temporary file, never a directory tree.
	 *
	 * @param string $path Exact temporary path.
	 * @throws OptimizationException When cleanup fails.
	 */
	public function remove( string $path ): void {
		if ( ! isset( $this->registered_files[ $path ] ) ) {
			throw new OptimizationException( 'temporary_cleanup_failed', 'The temporary path was not created by this manager.' );
		}
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
			throw new OptimizationException( 'temporary_cleanup_failed', 'A temporary path changed into an unexpected filesystem object.' );
		}
		if ( is_file( $path ) && ! unlink( $path ) ) {
			throw new OptimizationException( 'temporary_cleanup_failed', 'A temporary file could not be removed.' );
		}
		$directory = $this->registered_files[ $path ];
		if ( is_dir( $directory ) && ! is_link( $directory ) && ! rmdir( $directory ) ) {
			throw new OptimizationException( 'temporary_cleanup_failed', 'A private temporary directory could not be removed.' );
		}
		unset( $this->registered_files[ $path ] );
	}

	/** Forgets a staging path removed by the atomic promoter.
	 *
	 * @param string $path Promoted temporary path.
	 * @throws OptimizationException When the path was not registered.
	 */
	public function forget( string $path ): void {
		if ( ! isset( $this->registered_files[ $path ] ) ) {
			throw new OptimizationException( 'temporary_cleanup_failed', 'The temporary path was not created by this manager.' );
		}
		unset( $this->registered_files[ $path ] );
	}
}
