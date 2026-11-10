<?php
/**
 * Resolves local JPEG/PNG attachment variants through WordPress metadata.
 *
 * @package Jocky\ImageOptimizer\Media
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Media;

/** Returns confined local source records without trusting HTTP-supplied paths. */
final class AttachmentTargetProvider {

	public const MAX_VARIANTS = 128;

	/** Lists the original, attached full-size file, and registered image sizes.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array<string, array{variant_key:string,source_path:string,relative_path:string,fingerprint:string,bytes:int,mime:string}> Supported local image variants.
	 */
	public function targets( int $attachment_id ): array {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type || ! in_array( get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png' ), true ) ) {
			return array();
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || is_link( $uploads['basedir'] ) ) {
			return array();
		}
		$root = realpath( $uploads['basedir'] );
		if ( false === $root ) {
			return array();
		}
		$attached = get_attached_file( $attachment_id, true );
		$filtered = get_attached_file( $attachment_id );
		if ( ! is_string( $attached ) || ! is_string( $filtered ) ) {
			return array();
		}
		$attached_real = $this->safe_local_file( $attached, $root );
		$filtered_real = $this->safe_local_file( $filtered, $root );
		if ( null === $attached_real || $attached_real !== $filtered_real ) {
			return array();
		}
		$metadata   = wp_get_attachment_metadata( $attachment_id );
		$candidates = array( 'full' => $attached_real );
		if ( is_array( $metadata ) && isset( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) && $this->valid_basename( $metadata['original_image'] ) ) {
			$original = $this->safe_local_file( dirname( $attached_real ) . DIRECTORY_SEPARATOR . $metadata['original_image'], $root );
			if ( null !== $original ) {
				$candidates = array( 'original' => $original ) + $candidates;
			}
		}
		if ( is_array( $metadata ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				$key = sanitize_key( (string) $size_name );
				if ( '' === $key || 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', $key ) || ! is_array( $size_data ) || ! isset( $size_data['file'] ) || ! is_string( $size_data['file'] ) || ! $this->valid_basename( $size_data['file'] ) ) {
					continue;
				}
				$path = $this->safe_local_file( dirname( $attached_real ) . DIRECTORY_SEPARATOR . $size_data['file'], $root );
				if ( null !== $path ) {
					$candidates[ 'size:' . $key ] = $path;
				}
			}
		}
		if ( count( $candidates ) > self::MAX_VARIANTS ) {
			return array();
		}
		$targets = array();
		$seen    = array();
		foreach ( $candidates as $variant_key => $path ) {
			if ( isset( $seen[ $path ] ) ) {
				continue;
			}
			$mime = wp_get_image_mime( $path );
			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
				continue;
			}
			$stat = stat( $path );
			if ( false === $stat ) {
				continue;
			}
			$relative = $this->relative_path( $root, $path );
			if ( null === $relative ) {
				continue;
			}
			$fingerprint             = hash( 'sha256', $mime . ':' . $stat['size'] . ':' . $stat['mtime'] . ':' . $stat['ino'] );
			$targets[ $variant_key ] = array(
				'variant_key'   => $variant_key,
				'source_path'   => $path,
				'relative_path' => $relative,
				'fingerprint'   => $fingerprint,
				'bytes'         => (int) $stat['size'],
				'mime'          => $mime,
			);
			$seen[ $path ]           = true;
		}
		return $targets;
	}

	/** Resolves one previously persisted variant key against current WP metadata.
	 *
	 * @param int    $attachment_id WordPress attachment ID.
	 * @param string $variant_key   Stored symbolic variant identifier.
	 * @return array{variant_key:string,source_path:string,relative_path:string,fingerprint:string,bytes:int,mime:string}|null Current local source or null.
	 */
	public function target( int $attachment_id, string $variant_key ): ?array {
		$targets = $this->targets( $attachment_id );
		return $targets[ $variant_key ] ?? null;
	}

	/** Confines an existing non-symlink file beneath the current site's upload root.
	 *
	 * @param string $path Candidate local path.
	 * @param string $root Canonical uploads root.
	 * @return string|null Canonical path when local and safe.
	 */
	private function safe_local_file( string $path, string $root ): ?string {
		if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$real = realpath( $path );
		if ( false === $real || null === $this->relative_path( $root, $real ) ) {
			return null;
		}
		return $real;
	}

	/** Rejects path separators and traversal tokens across operating systems.
	 *
	 * @param string $filename Candidate metadata filename.
	 * @return bool Whether it is one safe filename component.
	 */
	private function valid_basename( string $filename ): bool {
		return '' !== $filename && '.' !== $filename && '..' !== $filename && ! str_contains( $filename, '/' ) && ! str_contains( $filename, '\\' ) && ! str_contains( $filename, "\0" );
	}

	/** Produces a normalized path relative to the site-specific upload root.
	 *
	 * @param string $root Canonical uploads root.
	 * @param string $path Canonical candidate path.
	 * @return string|null Relative path or null for an outside path.
	 */
	private function relative_path( string $root, string $path ): ?string {
		$root      = rtrim( wp_normalize_path( $root ), '/' );
		$path      = wp_normalize_path( $path );
		$test_root = 'Windows' === PHP_OS_FAMILY ? strtolower( $root ) : $root;
		$test_path = 'Windows' === PHP_OS_FAMILY ? strtolower( $path ) : $path;
		$prefix    = $test_root . '/';
		if ( ! str_starts_with( $test_path, $prefix ) ) {
			return null;
		}
		return substr( $path, strlen( $root ) + 1 );
	}
}
