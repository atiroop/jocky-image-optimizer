<?php
/**
 * Bounded streaming SHA-256 helper for attachment recovery records.
 *
 * @package Jocky\ImageOptimizer\Recovery
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Recovery;

/** Hashes regular files without loading image contents into memory. */
final class IntegrityHasher {

	public const MAX_BYTES    = 52428800;
	private const CHUNK_BYTES = 1048576;

	/** Returns a stable digest only when the opened file remains unchanged.
	 *
	 * @param string $path File path.
	 * @param int    $limit Maximum bytes to read.
	 * @return array{sha256:string,bytes:int,dev:int,ino:int,size:int,mtime:int}|null Stable digest and identity, or null for unsafe/oversized files.
	 */
	public function hash_file( string $path, int $limit = self::MAX_BYTES ): ?array {
		if ( $limit < 1 || is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$before = lstat( $path );
		if ( false === $before || ! isset( $before['size'], $before['ino'], $before['dev'] ) || $before['size'] < 1 || $before['size'] > $limit ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem does not expose streaming reads or inode identity required for bounded hashing.
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return null;
		}
		$opened = fstat( $handle );
		if ( false === $opened || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev'] || $opened['size'] !== $before['size'] ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the stream used for bounded hash validation.
			fclose( $handle );
			return null;
		}
		$context = hash_init( 'sha256' );
		$bytes   = 0;
		while ( ! feof( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read fixed-size chunks instead of loading the complete image into memory.
			$chunk = fread( $handle, self::CHUNK_BYTES );
			if ( false === $chunk || ( '' === $chunk && ! feof( $handle ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the stream after a bounded read failure.
				fclose( $handle );
				return null;
			}
			$bytes += strlen( $chunk );
			if ( $bytes > $limit ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the stream when the configured hashing limit is exceeded.
				fclose( $handle );
				return null;
			}
			hash_update( $context, $chunk );
		}
		$after_open = fstat( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the stream after stable before/after identity checks.
		fclose( $handle );
		$after_path = lstat( $path );
		if ( false === $after_open || false === $after_path || is_link( $path ) || $bytes !== $before['size'] || $after_open['ino'] !== $before['ino'] || $after_open['dev'] !== $before['dev'] || $after_open['size'] !== $before['size'] || $after_path['ino'] !== $before['ino'] || $after_path['dev'] !== $before['dev'] || $after_path['size'] !== $before['size'] || $after_path['mtime'] !== $before['mtime'] ) {
			return null;
		}
		$result = array(
			'sha256' => hash_final( $context ),
			'bytes'  => $bytes,
			'dev'    => (int) $before['dev'],
			'ino'    => (int) $before['ino'],
			'size'   => (int) $before['size'],
			'mtime'  => (int) $before['mtime'],
		);
		return $result;
	}
}
