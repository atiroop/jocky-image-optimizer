<?php
/**
 * Detects image libraries and encoder executables available to PHP.
 *
 * @package Jocky\ImageOptimizer
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer;

/** Reports real runtime capabilities without invoking external tools. */
final class CapabilityDetector {

	/** Detects installed PHP libraries, formats, and known encoder binaries.
	 *
	 * @return array<string, array{available: bool, detail: string}> Capability statuses.
	 */
	public function detect(): array {
		$gd      = extension_loaded( 'gd' );
		$imagick = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
		$webp    = ( $gd && function_exists( 'imagewebp' ) ) || ( $imagick && $this->imagick_supports( 'WEBP' ) );
		$avif    = ( $gd && function_exists( 'imageavif' ) ) || ( $imagick && $this->imagick_supports( 'AVIF' ) );

		return array(
			'gd'       => $this->result( $gd, $gd ? 'PHP GD extension is loaded.' : 'PHP GD extension is not loaded.' ),
			'imagick'  => $this->result( $imagick, $imagick ? 'Imagick extension is loaded.' : 'Imagick extension is not loaded.' ),
			'webp'     => $this->result( $webp, $webp ? 'A loaded PHP image library reports WebP encoding support.' : 'No loaded PHP image library reports WebP encoding support.' ),
			'avif'     => $this->result( $avif, $avif ? 'A loaded PHP image library reports AVIF encoding support.' : 'No loaded PHP image library reports AVIF encoding support.' ),
			'mozjpeg'  => $this->binary_result( array( 'cjpeg', 'cjpeg-moz', 'mozjpeg' ) ),
			'djpeg'    => $this->binary_result( array( 'djpeg', 'djpeg-moz' ) ),
			'jpegtran' => $this->binary_result( array( 'jpegtran' ) ),
			'pngquant' => $this->binary_result( array( 'pngquant' ) ),
			'oxipng'   => $this->binary_result( array( 'oxipng' ) ),
			'cwebp'    => $this->binary_result( array( 'cwebp' ) ),
			'avifenc'  => $this->binary_result( array( 'avifenc' ) ),
		);
	}

	/** Reports whether one of the named binaries is on PATH.
	 *
	 * @param string[] $names Executable names to locate.
	 * @return array{available: bool, detail: string} Detection result.
	 */
	private function binary_result( array $names ): array {
		$path = $this->find_executable( $names );
		if ( null !== $path ) {
			return $this->result( true, sprintf( 'Found executable at %s.', $path ) );
		}
		return $this->result( false, sprintf( 'Executable not found on PATH (%s).', implode( ', ', $names ) ) );
	}

	/** Finds an executable file without launching it.
	 *
	 * @param string[] $names Executable names to locate.
	 * @return string|null Absolute executable path, if found.
	 */
	private function find_executable( array $names ): ?string {
		$path_value = getenv( 'PATH' );
		if ( ! is_string( $path_value ) || '' === $path_value ) {
			return null;
		}
		$path_extensions = (string) getenv( 'PATHEXT' );
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$extensions = array_filter( explode( ';', $path_extensions ) );
			if ( empty( $extensions ) ) {
				$extensions = array( '.EXE', '.BAT', '.CMD', '.COM' );
			}
		} else {
			$extensions = array( '' );
		}
		foreach ( explode( PATH_SEPARATOR, $path_value ) as $directory ) {
			if ( '' === $directory ) {
				continue;
			}
			foreach ( $names as $name ) {
				foreach ( $extensions as $extension ) {
					$candidate = rtrim( $directory, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $name . $extension;
					if ( is_file( $candidate ) && ( 'Windows' === PHP_OS_FAMILY || is_executable( $candidate ) ) ) {
						return $candidate;
					}
				}
			}
		}
		return null;
	}

	/** Checks whether Imagick reports support for an output format.
	 *
	 * @param string $format Format identifier.
	 * @return bool Whether the format is supported.
	 */
	private function imagick_supports( string $format ): bool {
		try {
			return array() !== \Imagick::queryFormats( $format );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/** Creates a normalized capability result.
	 *
	 * @param bool   $available Whether the capability is available.
	 * @param string $detail    Human-readable diagnostic.
	 * @return array{available: bool, detail: string} Capability result.
	 */
	private function result( bool $available, string $detail ): array {
		return array(
			'available' => $available,
			'detail'    => $detail,
		);
	}
}
