<?php

$document_root = realpath( (string) ( $_SERVER['DOCUMENT_ROOT'] ?? '' ) );
$expected_root = realpath( (string) ( getenv( 'JIO_EXPECTED_DOCUMENT_ROOT' ) ?: '' ) );
if ( false === $document_root || false === $expected_root || $document_root !== $expected_root ) {
	http_response_code( 500 );
	header( 'Content-Type: text/plain; charset=UTF-8' );
	echo 'Disposable test server document root does not match the installed WordPress directory.';
	exit;
}
header( 'X-JIO-Test-Document-Root: match' );

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
