<?php
/**
 * Expiring, attachment-scoped exclusion for controlled queue maintenance.
 *
 * @package Jocky\ImageOptimizer\Queue
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Queue;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Maintenance lease acquisition and release require compare-and-swap semantics; writes explicitly invalidate the options cache.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The options table identifier is supplied by trusted $wpdb metadata and every value is prepared.

/** Temporarily prevents global workers from consuming unrelated queue jobs. */
final class QueueMaintenanceWindow {

	public const OPTION_NAME   = 'jio_queue_scoped_maintenance';
	public const LEASE_SECONDS = 900;

	/** Starts an expiring scope after confirming no worker currently owns work.
	 *
	 * @param array<int,int> $attachment_ids Authorized attachment IDs.
	 * @param JobRepository  $jobs Queue state reader.
	 * @return string|\WP_Error Owner token or a safe refusal reason.
	 */
	public static function begin( array $attachment_ids, JobRepository $jobs ): string|\WP_Error {
		global $wpdb;
		if ( QueueState::is_paused() ) {
			return new \WP_Error( 'queue_paused', 'The optimization queue is paused; scoped work cannot bypass that control.' );
		}
		if ( array() === $attachment_ids ) {
			return new \WP_Error( 'empty_scope', 'At least one attachment ID is required.' );
		}
		$attachment_ids = array_values( array_unique( array_map( 'intval', $attachment_ids ) ) );
		sort( $attachment_ids );
		$value = array(
			'token'          => bin2hex( random_bytes( 24 ) ),
			'attachment_ids' => $attachment_ids,
			'expires_at'     => time() + self::LEASE_SECONDS,
		);
		if ( ! add_option( self::OPTION_NAME, $value, '', false ) ) {
			$option_table = $wpdb->options;
			$raw          = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$option_table} WHERE option_name = %s", self::OPTION_NAME ) );
			$existing     = maybe_unserialize( $raw );
			if ( is_array( $existing ) && isset( $existing['expires_at'] ) && (int) $existing['expires_at'] > time() ) {
				return new \WP_Error( 'maintenance_active', 'Another scoped queue maintenance window is active.' );
			}
			$changed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$option_table} SET option_value = %s, autoload = 'no' WHERE option_name = %s AND option_value = %s",
					maybe_serialize( $value ),
					self::OPTION_NAME,
					(string) $raw
				)
			);
			if ( 1 !== $changed ) {
				return new \WP_Error( 'maintenance_race', 'Could not safely acquire the scoped maintenance window; retry after checking worker status.' );
			}
			wp_cache_delete( self::OPTION_NAME, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		if ( $jobs->has_active_worker() ) {
			self::release( $value['token'] );
			return new \WP_Error( 'worker_active', 'A worker is already active. No scoped work was started; retry after it finishes.' );
		}
		return $value['token'];
	}

	/** Whether an unexpired scoped maintenance window blocks global workers. */
	public static function is_active(): bool {
		$value = get_option( self::OPTION_NAME, array() );
		return is_array( $value ) && isset( $value['token'], $value['expires_at'] ) && (int) $value['expires_at'] > time();
	}

	/** Whether a scoped worker still owns the exact requested scope.
	 *
	 * @param string         $token Lease owner token.
	 * @param array<int,int> $attachment_ids Exact authorized attachment IDs.
	 */
	public static function owns( string $token, array $attachment_ids ): bool {
		$value = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $value ) || ! isset( $value['token'], $value['attachment_ids'], $value['expires_at'] ) || (int) $value['expires_at'] <= time() || ! hash_equals( (string) $value['token'], $token ) ) {
			return false;
		}
		$expected = array_values( array_unique( array_map( 'intval', $attachment_ids ) ) );
		$actual   = array_values( array_unique( array_map( 'intval', (array) $value['attachment_ids'] ) ) );
		sort( $expected );
		sort( $actual );
		return $expected === $actual;
	}

	/** Renews the short lease while a bounded scoped batch is active.
	 *
	 * @param string         $token Lease owner token.
	 * @param array<int,int> $attachment_ids Exact authorized attachment IDs.
	 */
	public static function renew( string $token, array $attachment_ids ): bool {
		if ( ! self::owns( $token, $attachment_ids ) ) {
			return false;
		}
		$value               = get_option( self::OPTION_NAME, array() );
		$value['expires_at'] = time() + self::LEASE_SECONDS;
		update_option( self::OPTION_NAME, $value, false );
		return self::owns( $token, $attachment_ids );
	}

	/** Releases only the lease held by this invocation.
	 *
	 * @param string $token Lease owner token.
	 */
	public static function release( string $token ): void {
		global $wpdb;
		$option_table = $wpdb->options;
		$raw          = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$option_table} WHERE option_name = %s", self::OPTION_NAME ) );
		if ( ! is_string( $raw ) ) {
			return;
		}
		$value = maybe_unserialize( $raw );
		if ( ! is_array( $value ) || ! isset( $value['token'] ) || ! hash_equals( (string) $value['token'], $token ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$option_table} WHERE option_name = %s AND option_value = %s", self::OPTION_NAME, (string) $raw ) );
		wp_cache_delete( self::OPTION_NAME, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
