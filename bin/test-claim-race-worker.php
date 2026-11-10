<?php
/** Waits at the disposable database barrier, then claims and completes one job. */

declare(strict_types=1);

global $wpdb;

$token = (string) getenv( 'JIO_CLAIM_TOKEN' );
if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
	fwrite( STDERR, "Invalid worker token.\n" );
	exit( 1 );
}
$options = $wpdb->options;
$wpdb->query( "UPDATE {$options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = 'jio_test_claim_arrivals'" );
$deadline = microtime( true ) + 15;
while ( true ) {
	$start = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$options} WHERE option_name = %s", 'jio_test_claim_start' ) );
	if ( '1' === $start ) {
		break;
	}
	if ( microtime( true ) > $deadline ) {
		fwrite( STDERR, "Timed out waiting at the concurrent claim barrier.\n" );
	exit( 1 );
	}
	usleep( 10000 );
}
$jobs = new \Jocky\ImageOptimizer\Queue\JobRepository();
$job  = $jobs->claim_next( $token );
if ( null === $job ) {
	echo "EMPTY\n";
	exit( 0 );
}
if ( ! $jobs->finish( (int) $job['id'], $token, 'completed', 1, 1 ) ) {
	fwrite( STDERR, "Claimed job could not be completed by its owner token.\n" );
	exit( 1 );
}
echo 'CLAIMED:' . (int) $job['id'] . "\n";
