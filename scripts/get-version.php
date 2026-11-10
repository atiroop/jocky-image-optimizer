<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'package-version.php';

try {
	echo jio_package_version( dirname( __DIR__ ) ) . PHP_EOL;
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . PHP_EOL );
	exit( 1 );
}
