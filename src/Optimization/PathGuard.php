<?php
/**
 * Filesystem confinement for explicit optimization paths.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

/** Restricts image reads and writes to trusted, existing managed directories. */
final class PathGuard {

	/** Canonical managed directory roots.
	 *
	 * @var string[]
	 */
	private array $roots = array();

	/** Configures existing allowed roots.
	 *
	 * @param string[] $allowed_roots Managed directories.
	 * @throws OptimizationException When a root is invalid.
	 */
	public function __construct( array $allowed_roots ) {
		foreach ( $allowed_roots as $root ) {
			if ( ! is_string( $root ) || is_link( $root ) || ! is_dir( $root ) ) {
				throw new OptimizationException( 'invalid_root', 'A configured path root is not a regular directory.' );
			}
			$canonical = realpath( $root );
			if ( false === $canonical ) {
				throw new OptimizationException( 'invalid_root', 'A configured path root cannot be resolved.' );
			}
			$trimmed       = rtrim( $canonical, DIRECTORY_SEPARATOR );
			$this->roots[] = '' === $trimmed ? DIRECTORY_SEPARATOR : $trimmed;
		}
		if ( array() === $this->roots ) {
			throw new OptimizationException( 'invalid_root', 'At least one managed path root is required.' );
		}
	}

	/** Resolves a readable, non-symlink source file inside a managed root.
	 *
	 * @param string $path Source file path.
	 * @return string Canonical source path.
	 * @throws OptimizationException When the path is invalid or outside managed roots.
	 */
	public function source( string $path ): string {
		if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new OptimizationException( 'invalid_source_path', 'The source must be a readable regular file, not a symlink.' );
		}
		$canonical = realpath( $path );
		if ( false === $canonical || ! $this->contains( $canonical ) ) {
			throw new OptimizationException( 'path_outside_root', 'The source is outside the configured managed directories.' );
		}
		return $canonical;
	}

	/** Validates a new destination path and returns its canonical-parent path.
	 *
	 * @param string $path   Requested destination path.
	 * @param string $source Canonical source path.
	 * @return string Confined destination path.
	 * @throws OptimizationException When the path escapes the allowed roots or exists.
	 */
	public function destination( string $path, string $source ): string {
		if ( is_link( $path ) || file_exists( $path ) ) {
			throw new OptimizationException( 'destination_exists', 'The destination already exists or is a symlink.' );
		}
		$directory = dirname( $path );
		if ( is_link( $directory ) || ! is_dir( $directory ) ) {
			throw new OptimizationException( 'invalid_destination_path', 'The destination directory must already exist and cannot be a symlink.' );
		}
		$canonical_directory = realpath( $directory );
		$name                = basename( $path );
		if ( false === $canonical_directory || '' === $name || '.' === $name || '..' === $name || ! $this->contains( $canonical_directory ) ) {
			throw new OptimizationException( 'path_outside_root', 'The destination is outside the configured managed directories.' );
		}
		$destination = $canonical_directory . DIRECTORY_SEPARATOR . $name;
		if ( $this->normalize( $destination ) === $this->normalize( $source ) ) {
			throw new OptimizationException( 'path_alias', 'The source and destination paths must be different.' );
		}
		return $destination;
	}

	/** Checks whether a canonical path is contained by an allowed root.
	 *
	 * @param string $path Candidate canonical path.
	 * @return bool Whether the path is within a configured root.
	 */
	private function contains( string $path ): bool {
		$path = $this->normalize( $path );
		foreach ( $this->roots as $root ) {
			$root   = $this->normalize( $root );
			$prefix = str_ends_with( $root, DIRECTORY_SEPARATOR ) ? $root : $root . DIRECTORY_SEPARATOR;
			if ( $path === $root || str_starts_with( $path, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/** Applies platform-correct case normalization for comparisons.
	 *
	 * @param string $path Path to normalize.
	 * @return string Normalized path.
	 */
	private function normalize( string $path ): string {
		return 'Windows' === PHP_OS_FAMILY ? strtolower( str_replace( '/', '\\', $path ) ) : $path;
	}
}
