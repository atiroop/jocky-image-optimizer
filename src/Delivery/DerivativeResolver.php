<?php
/**
 * Resolves registered derivatives for safe frontend use.
 *
 * @package Jocky\ImageOptimizer\Delivery
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Delivery;

use Jocky\ImageOptimizer\Media\AttachmentTargetProvider;
use Jocky\ImageOptimizer\Queue\QueueSchema;
use Jocky\ImageOptimizer\Recovery\DerivativeRepository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This bounded read follows existing plugin-owned registry patterns; request-local memoization avoids duplicate queries.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived solely from the trusted WordPress prefix.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Dynamic table identifiers are trusted; all submitted values are prepared.

/** Read-only, bounded resolver. Unverified legacy records deliberately fall back. */
final class DerivativeResolver {

	public const MAX_ATTACHMENT_LOOKUPS = 80;

	/**
	 * Valid resolver results, cached for this request.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private array $memo = array();

	/** Number of unique attachment registry lookups made by this request.
	 *
	 * @var int
	 */
	private int $lookup_count = 0;

	/** Creates a resolver with the current WordPress attachment target provider.
	 *
	 * @param AttachmentTargetProvider $targets Source attachment resolver.
	 */
	public function __construct( private AttachmentTargetProvider $targets = new AttachmentTargetProvider() ) {}

	/** Lists currently safe active records for one attachment, capped to the target variant bound.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<int, array<string, mixed>> Safe records.
	 */
	public function records( int $attachment_id ): array {
		if ( isset( $this->memo[ $attachment_id ] ) ) {
			return $this->memo[ $attachment_id ];
		}
		if ( $this->lookup_count >= self::MAX_ATTACHMENT_LOOKUPS ) {
			return array();
		}
		++$this->lookup_count;
		global $wpdb;
		$records       = ( new DerivativeRepository() )->has_active_optimization( $attachment_id ) ? array() : (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . QueueSchema::derivatives_table() . " WHERE attachment_id = %d AND lifecycle_status = 'active' AND validation_status = 'valid' ORDER BY id DESC LIMIT %d", $attachment_id, AttachmentTargetProvider::MAX_VARIANTS ),
			ARRAY_A
		);
		$targets       = $this->targets->targets( $attachment_id );
		$metadata      = wp_get_attachment_metadata( $attachment_id );
		$metadata_hash = hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'attached_file' => get_post_meta( $attachment_id, '_wp_attached_file', true ),
					'metadata'      => is_array( $metadata ) ? $metadata : array(),
				)
			)
		);
		$root          = wp_upload_dir();
		$valid         = array();
		foreach ( $records as $record ) {
			$key      = (string) ( $record['variant_key'] ?? '' );
			$target   = $targets[ $key ] ?? null;
			$format   = (string) ( $record['output_format'] ?? '' );
			$mime     = 'image/webp' === $format ? 'image/webp' : ( 'image/avif' === $format ? 'image/avif' : '' );
			$relative = (string) ( $record['derivative_relative_path'] ?? '' );
			if ( null === $target || '' === $mime || $target['relative_path'] !== (string) $record['source_relative_path'] || ! hash_equals( $target['fingerprint'], (string) $record['source_fingerprint'] ) || $target['mime'] !== (string) $record['source_mime'] || ! hash_equals( $metadata_hash, (string) $record['metadata_hash'] ) || $relative !== $this->expected_path( $target['relative_path'], $mime ) || empty( $root['basedir'] ) || ! empty( $root['error'] ) ) {
				continue;
			}
			$path = trailingslashit( $root['basedir'] ) . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			if ( ! $this->has_symlink_component( $root['basedir'], $relative ) || ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$real_root = realpath( $root['basedir'] );
			$real_path = realpath( $path );
			$stat      = stat( $path );
			$identity  = false !== $stat ? implode( ':', array( (int) $stat['dev'], (int) $stat['ino'], (int) $stat['size'], (int) $stat['mtime'] ) ) : '';
			if ( false === $real_root || false === $real_path || false === $stat || ! $this->is_within_root( $real_root, $real_path ) || '' === (string) ( $record['derivative_identity'] ?? '' ) || ! hash_equals( (string) $record['derivative_identity'], $identity ) || (int) $record['output_bytes'] !== (int) $stat['size'] || wp_get_image_mime( $real_path ) !== $mime ) {
				continue;
			}
			$valid[] = array(
				'id'       => (int) $record['id'],
				'format'   => $mime,
				'path'     => $real_path,
				'relative' => $relative,
				'variant'  => $key,
				'bytes'    => (int) $stat['size'],
			);
		}
		$this->memo[ $attachment_id ] = $valid;
		return $valid;
	}

	/** Computes the one deterministic derivative path allowed for a source and format.
	 *
	 * @param string $source Upload-relative source path.
	 * @param string $mime   Supported derivative MIME type.
	 * @return string Expected upload-relative derivative path.
	 */
	private function expected_path( string $source, string $mime ): string {
		$directory = pathinfo( $source, PATHINFO_DIRNAME );
		$filename  = pathinfo( $source, PATHINFO_FILENAME ) . ( 'image/webp' === $mime ? '.jio.webp' : '.jio.avif' );
		return '.' === $directory ? $filename : $directory . '/' . $filename;
	}

	/** Rejects a derivative path containing any symlink component.
	 *
	 * @param string $root     Canonical upload root.
	 * @param string $relative Upload-relative path.
	 * @return bool Whether the path components are ordinary filesystem entries.
	 */
	private function has_symlink_component( string $root, string $relative ): bool {
		$current = rtrim( $root, '/\\' );
		foreach ( explode( '/', $relative ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part || str_contains( $part, '\\' ) ) {
				return false;
			}
			$current .= DIRECTORY_SEPARATOR . $part;
			if ( is_link( $current ) ) {
				return false;
			}
		}
		return true;
	}

	/** Confines a canonical file path beneath the canonical upload directory.
	 *
	 * @param string $root Canonical upload root.
	 * @param string $path Canonical candidate path.
	 * @return bool Whether the path is beneath the root.
	 */
	private function is_within_root( string $root, string $path ): bool {
		$root = rtrim( wp_normalize_path( $root ), '/' ) . '/';
		$path = wp_normalize_path( $path );
		if ( 'Windows' === PHP_OS_FAMILY ) {
			return str_starts_with( strtolower( $path ), strtolower( $root ) );
		}
		return str_starts_with( $path, $root );
	}
}
