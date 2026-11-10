<?php
/** Disposable WP-CLI checks for cross-connection queue, lock, and migration behavior. */

global $wpdb;

$attachment_id = (int) getenv( 'JIO_CLI_ATTACHMENT_ID' );
$target        = ( new \Jocky\ImageOptimizer\Media\AttachmentTargetProvider() )->target( $attachment_id, 'full' );
if ( ! is_array( $target ) ) {
	fwrite( STDERR, "Missing concurrency fixture target.\n" );
	exit( 1 );
}

$secondary        = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$secondary->prefix = $wpdb->prefix;
$jobs             = new \Jocky\ImageOptimizer\Queue\JobRepository();
$independent_jobs = new \Jocky\ImageOptimizer\Queue\JobRepository( $secondary );
$fingerprint      = $target['fingerprint'];
$jobs->enqueue_many(
	array(
		array( 'attachment_id' => $attachment_id, 'variant_key' => 'full', 'output_format' => 'image/avif', 'source_fingerprint' => $fingerprint ),
		array( 'attachment_id' => $attachment_id, 'variant_key' => 'size:concurrency', 'output_format' => 'image/webp', 'source_fingerprint' => $fingerprint ),
		array( 'attachment_id' => $attachment_id, 'variant_key' => 'original', 'output_format' => 'source', 'source_fingerprint' => $fingerprint ),
	)
);
$first_token  = bin2hex( random_bytes( 16 ) );
$second_token = bin2hex( random_bytes( 16 ) );
$first        = $jobs->claim_next( $first_token );
$second       = $independent_jobs->claim_next( $second_token );
if ( ! is_array( $first ) || ! is_array( $second ) || (int) $first['id'] === (int) $second['id'] || $jobs->finish( (int) $first['id'], $second_token, 'completed', 1, 1 ) || ! $jobs->finish( (int) $first['id'], $first_token, 'completed', 1, 1 ) || ! $jobs->finish( (int) $second['id'], $second_token, 'completed', 1, 1 ) ) {
	fwrite( STDERR, "Independent claims did not remain distinct and token owned.\n" );
	exit( 1 );
}

$third_token = bin2hex( random_bytes( 16 ) );
$third       = $jobs->claim_next( $third_token );
if ( ! is_array( $third ) ) {
	fwrite( STDERR, "Could not claim stale lease fixture.\n" );
	exit( 1 );
}
$jobs_table = \Jocky\ImageOptimizer\Queue\QueueSchema::jobs_table();
$wpdb->query( $wpdb->prepare( "UPDATE {$jobs_table} SET locked_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 601 SECOND) WHERE id = %d", (int) $third['id'] ) );
$lock_name = 'jio_' . substr( hash( 'sha256', $wpdb->prefix . ':' . $attachment_id ), 0, 48 );
if ( '1' !== (string) $secondary->get_var( $secondary->prepare( 'SELECT GET_LOCK(%s,0)', $lock_name ) ) ) {
	fwrite( STDERR, "Could not acquire synchronization lock.\n" );
	exit( 1 );
}
$jobs->recover_stale();
$still_running = $wpdb->get_row( $wpdb->prepare( "SELECT status,lock_token FROM {$jobs_table} WHERE id = %d", (int) $third['id'] ), ARRAY_A );
$secondary->get_var( $secondary->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
if ( ! is_array( $still_running ) || 'running' !== $still_running['status'] || $third_token !== $still_running['lock_token'] ) {
	fwrite( STDERR, "Stale recovery transferred ownership while an operation lock was active.\n" );
	exit( 1 );
}
$jobs->recover_stale();
$recovered = $wpdb->get_row( $wpdb->prepare( "SELECT status,lock_token FROM {$jobs_table} WHERE id = %d", (int) $third['id'] ), ARRAY_A );
if ( ! is_array( $recovered ) || 'pending' !== $recovered['status'] || null !== $recovered['lock_token'] ) {
	fwrite( STDERR, "Stale recovery did not safely recover after lock release.\n" );
	exit( 1 );
}

$cleanup_lock_name = $lock_name;
if ( '1' !== (string) $secondary->get_var( $secondary->prepare( 'SELECT GET_LOCK(%s,0)', $cleanup_lock_name ) ) ) {
	fwrite( STDERR, "Could not establish cleanup exclusion lock.\n" );
	exit( 1 );
}
$blocked_cleanup = ( new \Jocky\ImageOptimizer\Recovery\RecoveryService() )->cleanup_attachment( $attachment_id, true );
$secondary->get_var( $secondary->prepare( 'SELECT RELEASE_LOCK(%s)', $cleanup_lock_name ) );
$source     = get_attached_file( $attachment_id );
$derivative = dirname( $source ) . DIRECTORY_SEPARATOR . pathinfo( $source, PATHINFO_FILENAME ) . '.jio.webp';
if ( 'operation_locked' !== $blocked_cleanup['status'] || ! is_file( $source ) || ! is_file( $derivative ) ) {
	fwrite( STDERR, "A second cleanup did not fail closed while the attachment lock was held.\n" );
	exit( 1 );
}

$registry                 = \Jocky\ImageOptimizer\Queue\QueueSchema::derivatives_table();
$events                   = \Jocky\ImageOptimizer\Queue\QueueSchema::events_table();
$slots                    = \Jocky\ImageOptimizer\Queue\QueueSchema::slots_table();
$before_jobs              = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table}" );
$before_derivatives       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$registry} WHERE attachment_id = %d", $attachment_id ) );
$wpdb->query( "ALTER TABLE {$jobs_table} DROP INDEX status_available, DROP COLUMN error_code" );
$wpdb->query( "DROP TABLE {$events}" );
$wpdb->query( "DELETE FROM {$slots} WHERE slot_id = 4" );
update_option( \Jocky\ImageOptimizer\Queue\QueueSchema::OPTION, 1, false );
\Jocky\ImageOptimizer\Queue\QueueSchema::install();
update_option( \Jocky\ImageOptimizer\Queue\QueueSchema::OPTION, 1, false ); // Simulate interruption before schema-version persistence.
\Jocky\ImageOptimizer\Queue\QueueSchema::maybe_install();
$indexes = (array) $wpdb->get_col( "SHOW INDEX FROM {$jobs_table}", 2 );
$slot_ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT slot_id FROM {$slots} ORDER BY slot_id ASC" ) );
$event_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events ) );
$job_columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$jobs_table}", 0 );
if ( ! in_array( 'status_available', $indexes, true ) || ! in_array( 'attachment_status', $indexes, true ) || ! in_array( 'error_code', $job_columns, true ) || $events !== $event_table || $before_jobs !== (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table}" ) || $before_derivatives !== (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$registry} WHERE attachment_id = %d", $attachment_id ) ) || array( 0, 1, 2, 3, 4 ) !== $slot_ids || (int) get_option( \Jocky\ImageOptimizer\Queue\QueueSchema::OPTION ) !== \Jocky\ImageOptimizer\Queue\QueueSchema::VERSION ) {
	fwrite( STDERR, "Migration retry did not restore partial schema without losing records.\n" );
	exit( 1 );
}
$wpdb->query( $wpdb->prepare( "UPDATE {$jobs_table} SET status = 'cancelled' WHERE attachment_id = %d AND status IN ('pending','running')", $attachment_id ) );

$secondary->close();
echo "Independent claims, token ownership, stale recovery lock, cleanup exclusion and partial migration retry passed.\n";
