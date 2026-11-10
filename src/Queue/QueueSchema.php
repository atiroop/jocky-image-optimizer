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

	public const VERSION = 2;
	public const OPTION  = 'jio_queue_schema_version';

	/** Runs the controlled migration only when the stored schema version is old. */
	public static function maybe_install(): void {
		if ( ! isset( $GLOBALS['wpdb'] ) ) {
			return;
		}
		if ( (int) get_option( self::OPTION, 0 ) < self::VERSION || ! self::tables_exist() ) {
			self::install();
			return;
		}
		self::seed_slots();
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
		$collate         = $wpdb->get_charset_collate();
		$jobs            = $wpdb->prefix . 'jio_jobs';
		$slots           = $wpdb->prefix . 'jio_worker_slots';
		$derivatives     = $wpdb->prefix . 'jio_derivatives';
		$recovery_events = $wpdb->prefix . 'jio_recovery_events';
		$cleanup_batches = $wpdb->prefix . 'jio_cleanup_batches';
		$jobs_sql        = "CREATE TABLE {$jobs} (
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
		$slots_sql       = "CREATE TABLE {$slots} (
			slot_id tinyint(3) unsigned NOT NULL,
			lock_token char(64) NULL,
			locked_at datetime NULL,
			PRIMARY KEY  (slot_id)
		) {$collate} ENGINE=InnoDB;";
		$derivatives_sql = "CREATE TABLE {$derivatives} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			record_key char(64) NOT NULL,
			attachment_id bigint(20) unsigned NOT NULL,
			variant_key varchar(80) NOT NULL,
			source_relative_path varchar(512) NOT NULL,
			source_mime varchar(64) NOT NULL,
			source_bytes bigint(20) unsigned NOT NULL,
			source_sha256 char(64) NOT NULL,
			source_fingerprint char(64) NOT NULL,
			metadata_hash char(64) NOT NULL,
			derivative_relative_path varchar(512) NOT NULL,
			output_format varchar(20) NOT NULL,
			derivative_sha256 char(64) NOT NULL,
			input_bytes bigint(20) unsigned NOT NULL,
			output_bytes bigint(20) unsigned NOT NULL,
			encoder_id varchar(32) NOT NULL,
			encoder_parameters text NOT NULL,
			validation_status varchar(24) NOT NULL DEFAULT 'valid',
			lifecycle_status varchar(24) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY record_key (record_key),
			KEY attachment_status (attachment_id,lifecycle_status),
			KEY status_id (lifecycle_status,id)
		) {$collate} ENGINE=InnoDB;";
		$events_sql      = "CREATE TABLE {$recovery_events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			derivative_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(32) NOT NULL,
			status varchar(24) NOT NULL,
			details text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id,id),
			KEY action_status (action,status,id)
		) {$collate} ENGINE=InnoDB;";
		$cleanup_sql     = "CREATE TABLE {$cleanup_batches} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status varchar(20) NOT NULL DEFAULT 'pending',
			last_derivative_id bigint(20) unsigned NOT NULL DEFAULT 0,
			eligible_count bigint(20) unsigned NOT NULL DEFAULT 0,
			processed_count bigint(20) unsigned NOT NULL DEFAULT 0,
			cleaned_count bigint(20) unsigned NOT NULL DEFAULT 0,
			conflict_count bigint(20) unsigned NOT NULL DEFAULT 0,
			requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
			lock_token char(64) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_updated (status,updated_at)
		) {$collate} ENGINE=InnoDB;";
		dbDelta( $jobs_sql );
		dbDelta( $slots_sql );
		dbDelta( $derivatives_sql );
		dbDelta( $events_sql );
		dbDelta( $cleanup_sql );
		if ( ! self::tables_exist() ) {
			return;
		}
		self::seed_slots();
		update_option( self::OPTION, self::VERSION, false );
	}

	/** Checks the required queue tables using the active WordPress prefix. */
	private static function tables_exist(): bool {
		global $wpdb;
		$jobs   = self::jobs_table();
		$slots  = self::slots_table();
		$tables = array( $jobs, $slots, self::derivatives_table(), self::events_table(), self::cleanup_table() );
		foreach ( $tables as $table ) {
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** Restores any missing bounded worker and scanner slot rows idempotently. */
	private static function seed_slots(): void {
		global $wpdb;
		$table = self::slots_table();
		$wpdb->query( "INSERT IGNORE INTO {$table} (slot_id) VALUES (0),(1),(2),(3),(4)" );
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

	/** Returns the safely prefixed derivative ownership table. */
	public static function derivatives_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'jio_derivatives';
	}

	/** Returns the safely prefixed persistent recovery audit table. */
	public static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'jio_recovery_events';
	}

	/** Returns the safely prefixed bulk cleanup state table. */
	public static function cleanup_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'jio_cleanup_batches';
	}
}
