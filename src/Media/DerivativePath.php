<?php
/**
 * Derives content-generation-specific, collision-resistant output paths.
 *
 * @package Jocky\ImageOptimizer\Media
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Media;

/** Keeps same-stem sources and regenerated source generations on separate paths. */
final class DerivativePath {

	/** Build a deterministic output path for one current source generation.
	 *
	 * @param array{relative_path:string,fingerprint:string} $target Confined source target.
	 * @param string                                         $mime Supported derivative MIME type.
	 * @return string Upload-relative derivative path, or empty when invalid.
	 */
	public static function for_target( array $target, string $mime ): string {
		$extension = match ( $mime ) {
			'image/webp' => 'webp',
			'image/avif' => 'avif',
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			default      => '',
		};
		$relative_path = wp_normalize_path( $target['relative_path'] );
		$fingerprint   = $target['fingerprint'];
		if ( '' === $extension || '' === $relative_path || 1 !== preg_match( '/\\A[a-f0-9]{64}\\z/', $fingerprint ) ) {
			return '';
		}
		$directory = pathinfo( $relative_path, PATHINFO_DIRNAME );
		$filename  = pathinfo( $relative_path, PATHINFO_FILENAME );
		$identity  = substr( hash( 'sha256', $relative_path . "\0" . $fingerprint ), 0, 16 );
		$filename .= '.jio-' . $identity . '.' . $extension;
		return '.' === $directory ? $filename : $directory . '/' . $filename;
	}

	/**
	 * Accepts current names and legacy names for already registered derivatives.
	 *
	 * @param array{relative_path:string,fingerprint:string} $target Confined source target.
	 * @param string                                         $mime Supported derivative MIME type.
	 * @param string                                         $relative_path Registered upload-relative derivative path.
	 */
	public static function matches_target( array $target, string $mime, string $relative_path ): bool {
		$current = self::for_target( $target, $mime );
		if ( '' !== $current && hash_equals( $current, $relative_path ) ) {
			return true;
		}
		$extension = match ( $mime ) {
			'image/webp' => 'webp',
			'image/avif' => 'avif',
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			default      => '',
		};
		if ( '' === $extension ) {
			return false;
		}
		$directory = pathinfo( $target['relative_path'], PATHINFO_DIRNAME );
		$legacy    = pathinfo( $target['relative_path'], PATHINFO_FILENAME ) . '.jio.' . $extension;
		$legacy    = '.' === $directory ? $legacy : $directory . '/' . $legacy;
		return hash_equals( $legacy, $relative_path );
	}
}
