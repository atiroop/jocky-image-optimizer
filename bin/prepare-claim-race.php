<?php
/** Prepares one synthetic queue row and a database barrier for two WP-CLI workers. */

global $wpdb;

$attachment_id = (int) getenv( 'JIO_CLI_ATTACHMENT_ID' );
$target        = ( new \Jocky\ImageOptimizer\Media\AttachmentTargetProvider() )->target( $attachment_id, 'full' );
if ( ! is_array( $target ) ) {
	fwrite( STDERR, "Missing queue-claim attachment fixture.\n" );
	exit( 1 );
}
$jobs_table = \Jocky\ImageOptimizer\Queue\QueueSchema::jobs_table();
$wpdb->query( $wpdb->prepare( "UPDATE {$jobs_table} SET status = 'cancelled' WHERE attachment_id = %d AND status IN ('pending','running')", $attachment_id ) );
$variant = 'size:race' . bin2hex( random_bytes( 4 ) );
$inserted = ( new \Jocky\ImageOptimizer\Queue\JobRepository() )->enqueue_many(
	array( array( 'attachment_id' => $attachment_id, 'variant_key' => $variant, 'output_format' => 'image/webp', 'source_fingerprint' => $target['fingerprint'] ) )
);
if ( 1 !== $inserted ) {
	fwrite( STDERR, "Could not prepare exactly one queue claim candidate.\n" );
	exit( 1 );
}
update_option( 'jio_test_claim_arrivals', 0, false );
update_option( 'jio_test_claim_start', 0, false );
echo "Prepared one candidate for concurrent independent claims.\n";
