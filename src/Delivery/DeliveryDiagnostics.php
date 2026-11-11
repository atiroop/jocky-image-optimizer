<?php
/**
 * Bounded registry summary for administrators.
 *
 * @package Jocky\ImageOptimizer\Delivery
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Delivery;

use Jocky\ImageOptimizer\Queue\QueueSchema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only aggregate over plugin-owned registry; no upload traversal or file hashing occurs.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name uses the trusted WordPress prefix.

/** Reports persisted state without traversing uploads or hashing image files. */
final class DeliveryDiagnostics {

	/** Returns registry counts without claiming live filesystem validity.
	 *
	 * @return array{total:int,registered_valid:int,registry_invalid:int} Aggregate counts.
	 */
	public function summary(): array {
		global $wpdb;
		$table     = QueueSchema::derivatives_table();
		$posts     = $wpdb->posts;
		$totals    = $wpdb->get_row( "SELECT COUNT(*) AS total, SUM(validation_status = 'valid' AND lifecycle_status = 'active') AS registered_valid, SUM(validation_status <> 'valid' OR lifecycle_status <> 'active') AS registry_invalid FROM {$table}", ARRAY_A );
		$eligible  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts} WHERE post_type = %s AND post_mime_type IN (%s,%s)", 'attachment', 'image/jpeg', 'image/png' ) );
		$sample    = (array) $wpdb->get_results( "SELECT id,attachment_id,source_relative_path,derivative_relative_path,output_format,output_bytes,derivative_identity,validation_status,lifecycle_status FROM {$table} WHERE lifecycle_status = 'active' ORDER BY id DESC LIMIT 25", ARRAY_A );
		$resolver  = new DerivativeResolver();
		$valid_ids = array();
		foreach ( array_unique( array_map( 'intval', wp_list_pluck( $sample, 'attachment_id' ) ) ) as $attachment_id ) {
			foreach ( $resolver->records( $attachment_id ) as $record ) {
				$valid_ids[ $record['id'] ] = true;
			}
		}
		$missing = 0;
		$valid   = 0;
		foreach ( $sample as $record ) {
			if ( isset( $valid_ids[ (int) $record['id'] ] ) ) {
				++$valid;
			} elseif ( $this->safely_missing( $record ) ) {
				++$missing;
			}
		}
		$summary = is_array( $totals ) ? array_map( 'intval', $totals ) : array(
			'total'            => 0,
			'registered_valid' => 0,
			'registry_invalid' => 0,
		);
		return array_merge(
			$summary,
			array(
				'eligible_mime_attachments' => $eligible,
				'sample_size'               => count( $sample ),
				'sample_valid'              => $valid,
				'sample_invalid_or_stale'   => count( $sample ) - $valid - $missing,
				'sample_missing'            => $missing,
				'checked_at'                => current_time( 'mysql' ),
			)
		);
	}

	/** Checks missing status only after validating the registry path components.
	 *
	 * @param array<string, mixed> $record Active registry row.
	 * @return bool Whether the exact expected local derivative is missing.
	 */
	private function safely_missing( array $record ): bool {
		$source    = (string) $record['source_relative_path'];
		$format    = (string) $record['output_format'];
		$extension = 'image/webp' === $format ? '.jio.webp' : ( 'image/avif' === $format ? '.jio.avif' : '' );
		if ( '' === $extension || str_contains( $source, '\\' ) || preg_match( '#(^|/)\.\.(/|$)#', $source ) ) {
			return false;
		}
		$directory = pathinfo( $source, PATHINFO_DIRNAME );
		$expected  = ( '.' === $directory ? '' : $directory . '/' ) . pathinfo( $source, PATHINFO_FILENAME ) . $extension;
		if ( $expected !== (string) $record['derivative_relative_path'] ) {
			return false;
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}
		$path = trailingslashit( $uploads['basedir'] ) . str_replace( '/', DIRECTORY_SEPARATOR, $expected );
		return ! is_link( $path ) && ! file_exists( $path );
	}
}
