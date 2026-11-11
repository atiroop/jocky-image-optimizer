<?php

$request_path = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
if ( preg_match( '/\.jio\.webp$/i', $request_path ) ) {
	header( 'Content-Type: image/webp' );
} elseif ( preg_match( '/\.jio\.avif$/i', $request_path ) ) {
	header( 'Content-Type: image/avif' );
}

$file = rtrim( (string) $_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR ) . str_replace( '/', DIRECTORY_SEPARATOR, $request_path );
if ( is_file( $file ) ) {
	return false;
}

require rtrim( (string) $_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'index.php';
