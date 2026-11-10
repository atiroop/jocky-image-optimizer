<?php
/**
 * Bounded JPEG and PNG validation.
 *
 * @package Jocky\ImageOptimizer\Optimization
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Optimization;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native local streams keep validation memory bounded and permit incremental PNG CRC checks; WP_Filesystem reads whole files and may use non-local transports.

/** Validates image signatures, structure, dimensions, and resource limits. */
final class ImageValidator {
	/** Inspects and validates a supported image within the request limits.
	 *
	 * @param string              $path    Image file path.
	 * @param OptimizationRequest $request Resource limits.
	 * @return ImageInfo Validated image facts.
	 * @throws OptimizationException When the file is invalid or unsupported.
	 */
	public function inspect( string $path, OptimizationRequest $request ): ImageInfo {
		if ( ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			throw new OptimizationException( 'invalid_image', 'The image must be a readable regular file.' );
		}
		$bytes = filesize( $path );
		if ( false === $bytes || $bytes < 1 || $bytes > $request->maximum_input_bytes ) {
			throw new OptimizationException( 'input_size_limit', 'The image is empty or exceeds the configured input size limit.' );
		}
		$header = file_get_contents( $path, false, null, 0, 32 );
		if ( ! is_string( $header ) ) {
			throw new OptimizationException( 'invalid_image', 'The image header could not be read.' );
		}
		if ( str_starts_with( $header, "\x89PNG\r\n\x1a\n" ) ) {
			[ $width, $height, $metadata, $animated ] = $this->inspect_png( $path, $bytes );
			$mime                                     = 'image/png';
		} elseif ( str_starts_with( $header, "\xff\xd8" ) ) {
			[ $width, $height, $metadata ] = $this->inspect_jpeg( $path, $bytes );
			$animated                      = false;
			$mime                          = 'image/jpeg';
		} else {
			throw new OptimizationException( 'unsupported_format', 'Only structurally valid JPEG and PNG images are supported.' );
		}
		if ( $width < 1 || $height < 1 || $width > intdiv( $request->maximum_pixels, $height ) ) {
			throw new OptimizationException( 'pixel_limit', 'The image dimensions are invalid or exceed the pixel limit.' );
		}
		return new ImageInfo( $mime, $width, $height, $bytes, $metadata, $animated );
	}

	/** Validates PNG chunks and computes CRC values in a bounded stream.
	 *
	 * @param string $path  PNG path.
	 * @param int    $bytes Measured file size.
	 * @return array{int, int, array<string, bool>, bool} Width, height, metadata, and animation flag.
	 * @throws OptimizationException When PNG structure or CRC validation fails.
	 */
	private function inspect_png( string $path, int $bytes ): array {
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new OptimizationException( 'invalid_image', 'The PNG could not be opened.' );
		}
		$width         = 0;
		$height        = 0;
		$metadata      = array();
		$animated      = false;
		$seen_ihdr     = false;
		$seen_iend     = false;
		$seen_idat     = false;
		$finished_idat = false;
		$seen_plte     = false;
		$color_type    = null;
		$offset        = 8;
		fseek( $handle, 8 );
		try {
			while ( $offset + 12 <= $bytes ) {
				$head = fread( $handle, 8 );
				if ( ! is_string( $head ) || 8 !== strlen( $head ) ) {
					break;
				}
				$chunk  = unpack( 'Nlength/a4type', $head );
				$length = (int) $chunk['length'];
				$type   = $chunk['type'];
				if ( 1 !== preg_match( '/^[A-Za-z]{4}$/', $type ) || ctype_lower( $type[2] ) ) {
					throw new OptimizationException( 'invalid_image', 'A PNG chunk type is invalid.' );
				}
				if ( $length > $bytes - $offset - 12 || ( ! $seen_ihdr && 'IHDR' !== $type ) ) {
					throw new OptimizationException( 'invalid_image', 'A PNG chunk length exceeds the file boundary.' );
				}
				$crc_context = hash_init( 'crc32b' );
				hash_update( $crc_context, $type );
				if ( 'IHDR' === $type ) {
					if ( $seen_ihdr || 13 !== $length || 8 !== $offset ) {
						throw new OptimizationException( 'invalid_image', 'The PNG header chunk is malformed.' );
					}
					$data = fread( $handle, 13 );
					if ( ! is_string( $data ) || 13 !== strlen( $data ) ) {
						throw new OptimizationException( 'invalid_image', 'The PNG header is truncated.' );
					}
					hash_update( $crc_context, $data );
					$values       = unpack( 'Nwidth/Nheight/Cbit_depth/Ccolor_type/Ccompression/Cfilter/Cinterlace', $data );
					$width        = (int) $values['width'];
					$height       = (int) $values['height'];
					$color_type   = (int) $values['color_type'];
					$valid_depths = array(
						0 => array( 1, 2, 4, 8, 16 ),
						2 => array( 8, 16 ),
						3 => array( 1, 2, 4, 8 ),
						4 => array( 8, 16 ),
						6 => array( 8, 16 ),
					);
					if ( ! isset( $valid_depths[ $values['color_type'] ] ) || ! in_array( $values['bit_depth'], $valid_depths[ $values['color_type'] ], true ) || 0 !== $values['compression'] || 0 !== $values['filter'] || ! in_array( $values['interlace'], array( 0, 1 ), true ) ) {
						throw new OptimizationException( 'invalid_image', 'The PNG header contains unsupported encoding parameters.' );
					}
					$seen_ihdr = true;
				} else {
					if ( 'IDAT' === $type ) {
						if ( $finished_idat ) {
							throw new OptimizationException( 'invalid_image', 'PNG image-data chunks are not contiguous.' );
						}
						$seen_idat = true;
					} elseif ( $seen_idat ) {
						$finished_idat = true;
					}
					if ( 'PLTE' === $type ) {
						$palette_entries = intdiv( $length, 3 );
						$maximum_entries = null === $color_type ? 0 : ( 1 << $values['bit_depth'] );
						if ( $seen_plte || $seen_idat || ! in_array( $color_type, array( 2, 3, 6 ), true ) || 0 === $length || $length > 768 || 0 !== $length % 3 || ( 3 === $color_type && $palette_entries > $maximum_entries ) ) {
							throw new OptimizationException( 'invalid_image', 'The PNG palette chunk is malformed or misplaced.' );
						}
						$seen_plte = true;
					}
					if ( 'IHDR' === $type || ( ord( $type[0] ) >= 65 && ord( $type[0] ) <= 90 && ! in_array( $type, array( 'PLTE', 'IDAT', 'IEND' ), true ) ) ) {
						throw new OptimizationException( 'invalid_image', 'The PNG contains an unknown or misplaced critical chunk.' );
					}
					$category = match ( $type ) {
						'iCCP' => 'icc',
						'eXIf' => 'exif',
						'tEXt', 'zTXt', 'iTXt' => 'text',
						'gAMA', 'cHRM', 'sRGB', 'sBIT' => 'color',
						'acTL', 'fcTL', 'fdAT' => 'animation',
						default => null,
					};
					if ( null !== $category ) {
						$metadata[ $category ] = true;
						if ( 'animation' === $category ) {
							$animated = true;
						}
					}
				}
				if ( 'IHDR' !== $type ) {
					$remaining = $length;
					while ( $remaining > 0 ) {
						$chunk_data = fread( $handle, min( 8192, $remaining ) );
						if ( ! is_string( $chunk_data ) || '' === $chunk_data ) {
							throw new OptimizationException( 'invalid_image', 'A PNG chunk payload is truncated.' );
						}
						hash_update( $crc_context, $chunk_data );
						$remaining -= strlen( $chunk_data );
					}
				}
				$crc = fread( $handle, 4 );
				if ( ! is_string( $crc ) || 4 !== strlen( $crc ) ) {
					throw new OptimizationException( 'invalid_image', 'A PNG chunk is truncated.' );
				}
				$expected_crc = unpack( 'N', $crc )[1];
				$actual_crc   = hexdec( hash_final( $crc_context ) );
				if ( $expected_crc !== $actual_crc ) {
					throw new OptimizationException( 'png_crc_mismatch', 'A PNG chunk failed its CRC integrity check.' );
				}
				$offset += 12 + $length;
				if ( 'IEND' === $type ) {
					if ( 0 !== $length || ! $seen_idat || ( 3 === $color_type && ! $seen_plte ) || $offset !== $bytes ) {
						throw new OptimizationException( 'invalid_image', 'The PNG end chunk is malformed or followed by trailing data.' );
					}
					$seen_iend = true;
					break;
				}
			}
		} finally {
			fclose( $handle );
		}
		if ( ! $seen_ihdr || ! $seen_idat || ! $seen_iend ) {
			throw new OptimizationException( 'invalid_image', 'The PNG is incomplete.' );
		}
		return array( $width, $height, $metadata, $animated );
	}

	/** Reads JPEG frame dimensions and known marker metadata.
	 *
	 * @param string $path  JPEG path.
	 * @param int    $bytes Measured file size.
	 * @return array{int, int, array<string, bool>} Width, height, and metadata.
	 * @throws OptimizationException When JPEG marker structure is invalid.
	 */
	private function inspect_jpeg( string $path, int $bytes ): array {
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new OptimizationException( 'invalid_image', 'The JPEG could not be opened.' );
		}
		$width    = 0;
		$height   = 0;
		$metadata = array();
		try {
			fseek( $handle, 2 );
			while ( (int) ftell( $handle ) + 4 < $bytes ) {
				$prefix = fread( $handle, 1 );
				if ( "\xff" !== $prefix ) {
					throw new OptimizationException( 'invalid_image', 'The JPEG marker stream is malformed.' );
				}
				do {
					$marker_data = fread( $handle, 1 );
				} while ( "\xff" === $marker_data );
				if ( ! is_string( $marker_data ) || 1 !== strlen( $marker_data ) ) {
					break;
				}
				$marker = ord( $marker_data );
				if ( 0xd9 === $marker || 0xda === $marker ) {
					break;
				}
				$length_data = fread( $handle, 2 );
				if ( ! is_string( $length_data ) || 2 !== strlen( $length_data ) ) {
					break;
				}
				$length      = unpack( 'n', $length_data )[1];
				$segment_end = (int) ftell( $handle ) + $length - 2;
				if ( $length < 2 || $segment_end > $bytes ) {
					throw new OptimizationException( 'invalid_image', 'A JPEG segment length exceeds the file boundary.' );
				}
				$payload_length = $length - 2;
				if ( in_array( $marker, array( 0xe1, 0xe2, 0xfe ), true ) ) {
					$metadata[ 0xe1 === $marker ? 'exif_xmp' : ( 0xe2 === $marker ? 'icc' : 'comment' ) ] = true;
				}
				if ( in_array( $marker, array( 0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf ), true ) && $payload_length >= 5 ) {
					$sof = fread( $handle, 5 );
					if ( ! is_string( $sof ) || 5 !== strlen( $sof ) ) {
						throw new OptimizationException( 'invalid_image', 'The JPEG frame header is truncated.' );
					}
					$dimensions = unpack( 'nheight/nwidth', substr( $sof, 1, 4 ) );
					// The SOF dimensions start after the precision byte.
					fseek( $handle, $payload_length - 5, SEEK_CUR );
					$height = (int) $dimensions['height'];
					$width  = (int) $dimensions['width'];
				} else {
					fseek( $handle, $payload_length, SEEK_CUR );
				}
			}
			fseek( $handle, -2, SEEK_END );
			if ( "\xff\xd9" !== fread( $handle, 2 ) ) {
				throw new OptimizationException( 'invalid_image', 'The JPEG end marker is missing.' );
			}
		} finally {
			fclose( $handle );
		}
		if ( $width < 1 || $height < 1 ) {
			throw new OptimizationException( 'invalid_image', 'The JPEG dimensions could not be validated.' );
		}
		return array( $width, $height, $metadata );
	}
}
