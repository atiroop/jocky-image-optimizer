<?php
/** Releases the database barrier only after both independent workers have arrived. */

$deadline = microtime( true ) + 15;
$wpdb    = $GLOBALS['wpdb'];
$options = $wpdb->options;
while ( 2 > (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$options} WHERE option_name = %s", 'jio_test_claim_arrivals' ) ) ) {
	if ( microtime( true ) > $deadline ) {
		fwrite( STDERR, "Timed out waiting for both claim workers.\n" );
	exit( 1 );
	}
	usleep( 10000 );
}
update_option( 'jio_test_claim_start', 1, false );
echo "Released concurrent claim barrier.\n";
