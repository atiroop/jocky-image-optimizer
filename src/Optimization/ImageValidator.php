<?php
/**
 * Bounded JPEG, PNG, WebP, and AVIF validation.
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
			[ $width, $height, $metadata, $animated, $alpha ] = $this->inspect_png( $path, $bytes );
			$mime = 'image/png';
		} elseif ( str_starts_with( $header, "\xff\xd8" ) ) {
			[ $width, $height, $metadata ] = $this->inspect_jpeg( $path, $bytes );
			$animated                      = false;
			$mime                          = 'image/jpeg';
			$alpha                         = false;
		} elseif ( str_starts_with( $header, 'RIFF' ) && 'WEBP' === substr( $header, 8, 4 ) ) {
			[ $width, $height, $alpha, $animated ] = $this->inspect_webp( $path, $bytes );
			$metadata                              = array();
			$mime                                  = 'image/webp';
		} elseif ( str_contains( substr( $header, 4, 24 ), 'ftypavif' ) || str_contains( substr( $header, 4, 24 ), 'ftypmif1' ) ) {
			[ $width, $height, $animated, $alpha ] = $this->inspect_avif( $path, $bytes );
			$metadata                              = array();
			$mime                                  = 'image/avif';
		} else {
			throw new OptimizationException( 'unsupported_format', 'The input is not a supported JPEG, PNG, WebP, or AVIF image.' );
		}
		if ( $width < 1 || $height < 1 || $width > intdiv( $request->maximum_pixels, $height ) ) {
			throw new OptimizationException( 'pixel_limit', 'The image dimensions are invalid or exceed the pixel limit.' );
		}
		return new ImageInfo( $mime, $width, $height, $bytes, $metadata, $animated, $alpha );
	}

	/** Validates the RIFF container and still-image WebP dimensions.
	 *
	 * @param string $path  Image path.
	 * @param int    $bytes Measured file size.
	 * @return array{int, int, bool, bool} Dimensions, alpha presence, and animation flag.
	 * @throws OptimizationException When the container is invalid.
	 */
	private function inspect_webp( string $path, int $bytes ): array {
		if ( $bytes < 20 || $bytes > 4294967304 ) {
			throw new OptimizationException( 'invalid_image', 'The WebP file size is invalid.' );
		}
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new OptimizationException( 'invalid_image', 'The WebP file could not be opened.' );
		}
		$signature = fread( $handle, 4 );
		$declared  = unpack( 'V', fread( $handle, 4 ) )[1] + 8;
		if ( 'RIFF' !== $signature || $declared !== $bytes || 'WEBP' !== fread( $handle, 4 ) ) {
			fclose( $handle );
			throw new OptimizationException( 'invalid_image', 'The WebP RIFF header or length is invalid.' );
		}
		$width     = 0;
		$height    = 0;
		$alpha     = false;
		$animated  = false;
		$has_frame = false;
		$offset    = 12;
		try {
			while ( $offset + 8 <= $bytes ) {
				$chunk = fread( $handle, 8 );
				if ( 8 !== strlen( $chunk ) ) {
					break; }
				$info   = unpack( 'a4type/Vlength', $chunk );
				$length = (int) $info['length'];
				if ( $length > $bytes - $offset - 8 ) {
					throw new OptimizationException( 'invalid_image', 'A WebP chunk exceeds the file boundary.' ); }
				$data = fread( $handle, min( $length, 10 ) );
				if ( strlen( $data ) !== min( $length, 10 ) ) {
					throw new OptimizationException( 'invalid_image', 'A WebP image chunk is truncated.' ); }
				switch ( $info['type'] ) {
					case 'VP8X':
						if ( $length < 10 ) {
							throw new OptimizationException( 'invalid_image', 'The WebP extended header is invalid.' ); }
						$flags    = ord( $data[0] );
						$animated = 0 !== ( $flags & 0x02 );
						$alpha    = 0 !== ( $flags & 0x10 );
						$width    = 1 + unpack( 'V', substr( $data, 4, 3 ) . "\0" )[1];
						$height   = 1 + unpack( 'V', substr( $data, 7, 3 ) . "\0" )[1];
						break;
					case 'VP8L':
						$has_frame = true;
						if ( $length < 5 || "\x2f" !== $data[0] ) {
							throw new OptimizationException( 'invalid_image', 'The WebP lossless header is invalid.' ); }
						$bits   = unpack( 'V', substr( $data, 1, 4 ) )[1];
						$width  = ( $bits & 0x3fff ) + 1;
						$height = ( ( $bits >> 14 ) & 0x3fff ) + 1;
						$alpha  = 0 !== ( $bits & ( 1 << 28 ) );
						break;
					case 'VP8 ':
						$has_frame = true;
						if ( $length < 10 || "\x9d\x01\x2a" !== substr( $data, 3, 3 ) ) {
							throw new OptimizationException( 'invalid_image', 'The WebP lossy frame header is invalid.' ); }
						$dimensions = unpack( 'vwidth/vheight', substr( $data, 6, 4 ) );
						$width      = $dimensions['width'] & 0x3fff;
						$height     = $dimensions['height'] & 0x3fff;
						break;
				}
				$offset += 8 + $length + ( $length % 2 );
				fseek( $handle, $offset );
			}
		} finally {
			fclose( $handle ); }
		if ( $offset !== $bytes || ! $has_frame || 0 === $width || 0 === $height ) {
			throw new OptimizationException( 'invalid_image', 'The WebP image frame is missing.' ); }
		return array( $width, $height, $alpha, $animated );
	}

	/** Validates a bounded ISO-BMFF AVIF still-image container and its primary dimensions.
	 *
	 * @param string $path  Image path.
	 * @param int    $bytes Measured file size.
	 * @return array{int, int, bool, bool} Dimensions, animation flag, and alpha presence.
	 * @throws OptimizationException When the container is invalid.
	 */
	private function inspect_avif( string $path, int $bytes ): array {
		if ( $bytes < 32 || $bytes > 52428800 ) {
			throw new OptimizationException( 'invalid_image', 'The AVIF file is truncated or exceeds the output validation limit.' ); }
		$data = file_get_contents( $path );
		if ( ! is_string( $data ) ) {
			throw new OptimizationException( 'invalid_image', 'The AVIF data could not be read.' ); }
		$offset   = 0;
		$ftyp     = false;
		$meta     = false;
		$mdat     = false;
		$animated = false;
		while ( $offset + 8 <= $bytes ) {
			$box_size    = unpack( 'N', substr( $data, $offset, 4 ) )[1];
			$type        = substr( $data, $offset + 4, 4 );
			$header_size = 8;
			if ( 1 === $box_size ) {
				if ( $offset + 16 > $bytes ) {
					break;
				} $box_size  = (int) unpack( 'J', substr( $data, $offset + 8, 8 ) )[1];
				$header_size = 16; } elseif ( 0 === $box_size ) {
				$box_size = $bytes - $offset; }
				if ( $box_size < $header_size || $box_size > $bytes - $offset ) {
					throw new OptimizationException( 'invalid_image', 'An AVIF box exceeds the file boundary.' ); }
				$payload = substr( $data, $offset + $header_size, $box_size - $header_size );
				if ( 'ftyp' === $type ) {
					$ftyp     = str_contains( $payload, 'avif' );
					$animated = str_contains( $payload, 'avis' ); }
				if ( 'meta' === $type ) {
					$meta = true; }
				if ( 'mdat' === $type ) {
					$mdat = true; }
				$offset += $box_size;
		}
		if ( $offset !== $bytes || ! $ftyp || ! $meta || ! $mdat ) {
			throw new OptimizationException( 'invalid_image', 'The AVIF container is incomplete or has invalid structure.' ); }
		$position = strpos( $data, 'ispe' );
		if ( false === $position || $position + 16 > $bytes ) {
			throw new OptimizationException( 'invalid_image', 'AVIF primary image dimensions are missing.' ); }
		$dimensions = unpack( 'Nwidth/Nheight', substr( $data, $position + 8, 8 ) );
		$alpha      = str_contains( $data, 'urn:mpeg:mpegB:cicp:systems:auxiliary:alpha' );
		return array( (int) $dimensions['width'], (int) $dimensions['height'], $animated, $alpha );
	}

	/** Validates PNG chunks and computes CRC values in a bounded stream.
	 *
	 * @param string $path  PNG path.
	 * @param int    $bytes Measured file size.
	 * @return array{int, int, array<string, bool>, bool, bool} Width, height, metadata, animation flag, and alpha presence.
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
		$alpha         = false;
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
					$alpha        = in_array( $color_type, array( 4, 6 ), true );
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
					if ( 'tRNS' === $type ) {
						$alpha = true; }
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
		return array( $width, $height, $metadata, $animated, $alpha );
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
