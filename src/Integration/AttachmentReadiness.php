<?php
/**
 * Versioned attachment optimization completion contract for integrations.
 *
 * @package Jocky\ImageOptimizer\Integration
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Integration;

use Jocky\ImageOptimizer\Queue\AttachmentJobFactory;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reads current durable queue state from a trusted table name.

/** Publishes deterministic terminal state only after every expected job finishes. */
final class AttachmentReadiness {
	public const CONTRACT_VERSION = 1;

	/** Return current attachment readiness, or a pending state while any work is active.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array<string, mixed> Current contract state.
	 */
	public static function state( int $attachment_id ): array {
		global $wpdb;
		$expected     = ( new AttachmentJobFactory() )->jobs_for_attachment( $attachment_id );
		$expected_map = array();
		foreach ( $expected as $item ) {
			$expected_map[ $item['variant_key'] . '|' . $item['output_format'] ] = $item;
		}
		$rows             = $wpdb->get_results( $wpdb->prepare( 'SELECT variant_key,output_format,source_fingerprint,status FROM ' . $wpdb->prefix . 'jio_jobs WHERE attachment_id = %d ORDER BY id ASC LIMIT 1000', $attachment_id ), ARRAY_A );
		$latest           = array();
		$stale_generation = false;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$key = (string) $row['variant_key'] . '|' . (string) $row['output_format'];
			if ( isset( $expected_map[ $key ] ) ) {
				if ( hash_equals( (string) $expected_map[ $key ]['source_fingerprint'], (string) $row['source_fingerprint'] ) ) {
					$status         = (string) $row['status'];
					$latest[ $key ] = in_array( $status, array( 'pending', 'running', 'completed', 'skipped', 'failed', 'cancelled' ), true ) ? $status : 'pending';
				} else {
					$stale_generation = true;
				}
			}
		}
		$invalidated = $stale_generation && count( $latest ) < count( $expected_map );
		$states      = array();
		foreach ( $expected_map as $key => $_item ) {
			$states[ $key ] = $latest[ $key ] ?? ( $invalidated ? 'invalidated' : 'pending' );
		}
		$outcome    = self::classify( $states, $invalidated );
		$generation = hash( 'sha256', (string) wp_json_encode( array_values( $expected_map ) ) );
		$canonical  = array();
		foreach ( $states as $key => $status ) {
			$canonical[] = array( $key, $status );
		}
		$registry = $wpdb->get_results(
			$wpdb->prepare( 'SELECT record_key,variant_key,source_fingerprint,derivative_relative_path,output_format,derivative_sha256,validation_status,lifecycle_status FROM ' . $wpdb->prefix . 'jio_derivatives WHERE attachment_id = %d ORDER BY record_key ASC LIMIT 100', $attachment_id ),
			ARRAY_A
		);
		$state    = array(
			'contract_version' => self::CONTRACT_VERSION,
			'attachment_id'    => $attachment_id,
			'generation'       => $generation,
			'expected'         => array_keys( $expected_map ),
			'completed'        => array_keys( array_filter( $states, static fn ( string $status ): bool => 'completed' === $status ) ),
			'outcome'          => $outcome,
			'terminal'         => in_array( $outcome, array( 'completed', 'partial', 'failed', 'skipped', 'invalidated' ), true ),
			'revision'         => hash(
				'sha256',
				(string) wp_json_encode(
					array(
						'jobs'     => $canonical,
						'registry' => is_array( $registry ) ? $registry : array(),
					)
				)
			),
		);
		return $state;
	}

	/** Classify expected job statuses, independently of WordPress/database state.
	 *
	 * @param array<string,string> $states Expected job states.
	 * @param bool                 $invalidated Whether old source rows invalidate the current generation.
	 * @return string Stable contract outcome.
	 */
	public static function classify( array $states, bool $invalidated = false ): string {
		if ( $invalidated ) {
			return 'invalidated';
		}
		if ( array() === $states ) {
			return 'skipped';
		}
		if ( in_array( 'running', $states, true ) ) {
			return 'running';
		}
		if ( in_array( 'pending', $states, true ) ) {
			return 'pending';
		}
		$completed = count( array_intersect( $states, array( 'completed' ) ) );
		$failed    = count( array_intersect( $states, array( 'failed' ) ) );
		$skipped   = count( array_intersect( $states, array( 'skipped', 'cancelled' ) ) );
		if ( $failed > 0 && 0 === $completed ) {
			return 'failed';
		}
		if ( 0 === $completed && 0 === $failed && $skipped > 0 ) {
			return 'skipped';
		}
		return $failed > 0 || $skipped > 0 ? 'partial' : ( $completed > 0 ? 'completed' : 'pending' );
	}

	/** Emit a terminal event after durable worker state has been committed.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 */
	public static function publish_if_terminal( int $attachment_id ): void {
		try {
			$state = self::state( $attachment_id );
			if ( ! empty( $state['terminal'] ) ) {
				do_action( 'jio_attachment_optimization_terminal_v1', $state );
			}
		} catch ( \Throwable ) {
			// Completion integrations must never interrupt the durable queue worker.
			return;
		}
	}
}
