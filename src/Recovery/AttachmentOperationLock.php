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
	/** Lock names held by this PHP request, including reentrant DB-session locks.
	 *
	 * @var array<string, true>
	 */
	private static array $held = array();

	/** Attempts to acquire the lock for one attachment without waiting.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function acquire( int $attachment_id ): bool {
		global $wpdb;
		if ( $attachment_id < 1 ) {
			return false;
		}
		$name = $this->lock_name( $attachment_id );
		if ( isset( self::$held[ $name ] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-scoped advisory lock is required to serialize optimizer and cleanup filesystem operations.
		$acquired = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		if ( $acquired ) {
			self::$held[ $name ] = true;
		}
		return $acquired;
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
		$name = $this->lock_name( $attachment_id );
		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases this connection's advisory lock before the request returns.
		$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		if ( '1' === (string) $released ) {
			unset( self::$held[ $name ] );
		}
	}

	/** Creates the stable lock name from the trusted WP table prefix and attachment ID.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function lock_name( int $attachment_id ): string {
		global $wpdb;
		return 'jio_' . substr( hash( 'sha256', $wpdb->prefix . ':' . $attachment_id ), 0, 48 );
	}
}
