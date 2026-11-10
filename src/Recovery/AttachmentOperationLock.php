<?php
/**
 * Per-attachment advisory lock shared by optimization and recovery operations.
 *
 * @package Jocky\ImageOptimizer\Recovery
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Recovery;

/** Uses a bounded MariaDB advisory lock to prevent optimizer/cleanup overlap. */
final class AttachmentOperationLock {

	/** Attempts to acquire the lock for one attachment without waiting.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function acquire( int $attachment_id ): bool {
		global $wpdb;
		if ( $attachment_id < 1 ) {
			return false;
		}
		$name = 'jio_' . substr( hash( 'sha256', $wpdb->prefix . ':' . $attachment_id ), 0, 48 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-scoped advisory lock is required to serialize optimizer and cleanup filesystem operations.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
	}

	/** Releases the lock acquired by this connection.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function release( int $attachment_id ): void {
		global $wpdb;
		if ( $attachment_id < 1 ) {
			return;
		}
		$name = 'jio_' . substr( hash( 'sha256', $wpdb->prefix . ':' . $attachment_id ), 0, 48 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases this connection's advisory lock before the request returns.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
}
