#!/usr/bin/env bash

set -euo pipefail

: "${WP_CORE_DIR:?WP_CORE_DIR is required}"
: "${WP_CLI_PHAR:?WP_CLI_PHAR is required}"
: "${JIO_WPCLI_DB_NAME:?JIO_WPCLI_DB_NAME is required}"
: "${JIO_WPCLI_DB_USER:?JIO_WPCLI_DB_USER is required}"
: "${JIO_WPCLI_DB_PASSWORD:?JIO_WPCLI_DB_PASSWORD is required}"
: "${JIO_WPCLI_DB_HOST:?JIO_WPCLI_DB_HOST is required}"

umask 077
cat > "$WP_CORE_DIR/wp-config.php" <<'PHP'
<?php
define( 'DB_NAME', getenv( 'JIO_WPCLI_DB_NAME' ) );
define( 'DB_USER', getenv( 'JIO_WPCLI_DB_USER' ) );
define( 'DB_PASSWORD', getenv( 'JIO_WPCLI_DB_PASSWORD' ) );
define( 'DB_HOST', getenv( 'JIO_WPCLI_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', false );
$table_prefix = 'jio_cli_';
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP

wp_cli() {
	php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" "$@"
}

wp_cli core install \
	--url=http://jio-cli-test.invalid \
	--title='JIO CLI Disposable Test' \
	--admin_user=jio_cli_test \
	--admin_password='disposable-cli-password' \
	--admin_email=jio-cli-test@example.invalid \
	--skip-email
wp_cli plugin activate jocky-image-optimizer

status="$(wp_cli jio recovery status)"
printf '%s\n' "$status"
grep -q 'Cleanup batch: none' <<< "$status"

preview="$(wp_cli jio recovery bulk --action=preview)"
printf '%s\n' "$preview"
grep -q 'Registered active candidates: 0 files' <<< "$preview"

scan="$(wp_cli jio recovery scan --batch-size=10)"
printf '%s\n' "$scan"
grep -q 'complete=yes' <<< "$scan"
