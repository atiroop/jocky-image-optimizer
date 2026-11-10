<?php
/**
 * Installs the persistent optimization queue schema.
 *
 * @package Jocky\ImageOptimizer\Queue
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Queue;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dbDelta migrations and queue table seeding require direct, persisted database operations.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifiers derive only from the trusted WordPress table prefix; seed values use $wpdb->prepare().

/** Creates queue tables through WordPress's versioned dbDelta migration API. */
final class QueueSchema {

	public const VERSION = 1;
	public const OPTION  = 'jio_queue_schema_version';

	/** Runs the controlled migration only when the stored schema version is old. */
	public static function maybe_install(): void {
		if ( ! isset( $GLOBALS['wpdb'] ) ) {
			return;
		}
		if ( (int) get_option( self::OPTION, 0 ) >= self::VERSION ) {
			return;
		}
		self::install();
	}

	/** Creates or upgrades queue tables and records the schema version. */
	public static function install(): void {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}
		$collate   = $wpdb->get_charset_collate();
		$jobs      = $wpdb->prefix . 'jio_jobs';
		$slots     = $wpdb->prefix . 'jio_worker_slots';
		$jobs_sql  = "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			variant_key varchar(80) NOT NULL,
			output_format varchar(20) NOT NULL,
			source_fingerprint char(64) NOT NULL,
			job_key char(64) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			available_at datetime NOT NULL,
			lock_token char(64) NULL,
			locked_at datetime NULL,
			started_at datetime NULL,
			finished_at datetime NULL,
			input_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			output_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			bytes_saved bigint(20) NOT NULL DEFAULT 0,
			error_code varchar(64) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_key (job_key),
			KEY status_available (status,available_at),
			KEY attachment_status (attachment_id,status)
		) {$collate} ENGINE=InnoDB;";
		$slots_sql = "CREATE TABLE {$slots} (
			slot_id tinyint(3) unsigned NOT NULL,
			lock_token char(64) NULL,
			locked_at datetime NULL,
			PRIMARY KEY  (slot_id)
		) {$collate} ENGINE=InnoDB;";
		dbDelta( $jobs_sql );
		dbDelta( $slots_sql );
		$jobs_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $jobs ) );
		$slots_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $slots ) );
		if ( $jobs !== $jobs_exists || $slots !== $slots_exists ) {
			return;
		}
		for ( $slot = 0; $slot <= 4; ++$slot ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$slots} (slot_id) VALUES (%d)", $slot ) );
		}
		update_option( self::OPTION, self::VERSION, false );
	}

	/** Returns the safely prefixed queue table name.
	 *
	 * @return string Jobs table name.
	 */
	public static function jobs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'jio_jobs';
	}

	/** Returns the safely prefixed worker slot table name.
	 *
	 * @return string Worker slots table name.
	 */
	public static function slots_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'jio_worker_slots';
	}
}
