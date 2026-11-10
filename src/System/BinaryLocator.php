<?php
/**
 * Locates explicitly supported image encoder executables.
 *
 * @package Jocky\ImageOptimizer\System
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\System;

use Jocky\ImageOptimizer\Optimization\OptimizationException;

/** Resolves only known binary identifiers and trusted configured paths. */
final class BinaryLocator {
	private const BINARIES = array(
		'cjpeg'    => array( 'cjpeg', 'cjpeg-moz', 'mozjpeg' ),
		'djpeg'    => array( 'djpeg', 'djpeg-moz' ),
		'jpegtran' => array( 'jpegtran' ),
		'pngquant' => array( 'pngquant' ),
		'oxipng'   => array( 'oxipng' ),
		'cwebp'    => array( 'cwebp' ),
		'avifenc'  => array( 'avifenc' ),
		'dwebp'    => array( 'dwebp' ),
		'avifdec'  => array( 'avifdec' ),
	);

	/** Paths explicitly configured by an administrator or deployer.
	 *
	 * @var array<string, string>
	 */
	private array $configured_paths;

	/** Creates a locator with trusted configured paths.
	 *
	 * @param array<string, string> $configured_paths Absolute paths keyed by known binary ID.
	 */
	public function __construct( array $configured_paths = array() ) {
		$this->configured_paths = $configured_paths;
	}

	/** Resolves a known executable to an absolute path.
	 *
	 * @param string $id Known binary identifier.
	 * @return string|null Absolute executable path when found.
	 * @throws OptimizationException When the identifier is not supported.
	 */
	public function locate( string $id ): ?string {
		if ( ! isset( self::BINARIES[ $id ] ) ) {
			throw new OptimizationException( 'unknown_encoder', 'The requested executable is not supported.' );
		}
		if ( isset( $this->configured_paths[ $id ] ) ) {
			$path = $this->configured_paths[ $id ];
			if ( $this->is_executable( $path ) ) {
				$resolved_path = realpath( $path );
				return false === $resolved_path ? null : $resolved_path;
			}
			return null;
		}
		$path_value = getenv( 'PATH' );
		if ( ! is_string( $path_value ) || '' === $path_value ) {
			return null;
		}
		$extensions = 'Windows' === PHP_OS_FAMILY ? array( '.exe', '.com' ) : array( '' );
		foreach ( explode( PATH_SEPARATOR, $path_value ) as $directory ) {
			if ( '' === $directory ) {
				continue;
			}
			foreach ( self::BINARIES[ $id ] as $name ) {
				foreach ( $extensions as $extension ) {
					$candidate = rtrim( $directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $name . $extension;
					if ( $this->is_executable( $candidate ) ) {
						$resolved_path = realpath( $candidate );
						return false === $resolved_path ? null : $resolved_path;
					}
				}
			}
		}
		return null;
	}

	/** Checks that a candidate is a readable executable regular file.
	 *
	 * @param string $path Candidate executable path.
	 * @return bool Whether the candidate is usable.
	 */
	private function is_executable( string $path ): bool {
		return ! is_link( $path ) && is_file( $path ) && is_readable( $path ) && ( 'Windows' === PHP_OS_FAMILY || is_executable( $path ) );
	}
}
