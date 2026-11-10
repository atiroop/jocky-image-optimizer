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
define( 'WP_ENVIRONMENT_TYPE', 'local' );
if ( '1' === getenv( 'JIO_ENABLE_DESTRUCTIVE_RECOVERY' ) ) {
	define( 'JIO_ENABLE_DESTRUCTIVE_RECOVERY', true );
}
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

fixture="$RUNNER_TEMP/jio-cli-recovery-fixture.png"
php -r '$chunk = static function ($type, $data) { return pack("N", strlen($data)) . $type . $data . pack("H*", hash("crc32b", $type . $data)); }; $png = "\x89PNG\r\n\x1a\n" . $chunk("IHDR", pack("NNCCCCC", 1, 1, 8, 6, 0, 0, 0)) . $chunk("IDAT", gzcompress("\0\xff\0\0\0")) . $chunk("IEND", ""); if (file_put_contents($argv[1], $png) === false) { exit(1); }' "$fixture"
attachment_id="$(wp_cli media import "$fixture" --porcelain)"
[[ "$attachment_id" =~ ^[0-9]+$ ]]
export JIO_CLI_ATTACHMENT_ID="$attachment_id"
source_path="$(wp_cli eval 'echo get_attached_file((int) getenv("JIO_CLI_ATTACHMENT_ID"));')"
derivative_path="${source_path%.*}.jio.webp"
cwebp "$source_path" -quiet -o "$derivative_path"

record_script="$RUNNER_TEMP/jio-cli-register-fixture.php"
cat > "$record_script" <<'PHP'
<?php
$attachment_id = (int) getenv( 'JIO_CLI_ATTACHMENT_ID' );
$targets = ( new \Jocky\ImageOptimizer\Media\AttachmentTargetProvider() )->targets( $attachment_id );
$target = $targets['full'] ?? null;
if ( ! is_array( $target ) ) {
	fwrite( STDERR, "Could not resolve the synthetic attachment source.\n" );
	exit( 1 );
}
$uploads = wp_upload_dir();
$root = realpath( $uploads['basedir'] );
$relative = ltrim( str_replace( DIRECTORY_SEPARATOR, '/', substr( $target['source_path'], strlen( $root ) ) ), '/' );
$derivative = dirname( $target['source_path'] ) . DIRECTORY_SEPARATOR . pathinfo( $target['source_path'], PATHINFO_FILENAME ) . '.jio.webp';
$derivative_relative = ltrim( str_replace( DIRECTORY_SEPARATOR, '/', substr( $derivative, strlen( $root ) ) ), '/' );
$source_hash = hash_file( 'sha256', $target['source_path'] );
$derivative_hash = hash_file( 'sha256', $derivative );
$metadata = wp_get_attachment_metadata( $attachment_id );
$metadata_hash = hash( 'sha256', (string) wp_json_encode( array( 'attached_file' => get_post_meta( $attachment_id, '_wp_attached_file', true ), 'metadata' => is_array( $metadata ) ? $metadata : array() ) ) );
$record_id = ( new \Jocky\ImageOptimizer\Recovery\DerivativeRepository() )->record(
	array(
		'attachment_id' => $attachment_id,
		'variant_key' => 'full',
		'source_relative_path' => $relative,
		'source_mime' => $target['mime'],
		'source_bytes' => filesize( $target['source_path'] ),
		'source_sha256' => $source_hash,
		'source_fingerprint' => $target['fingerprint'],
		'metadata_hash' => $metadata_hash,
		'derivative_relative_path' => $derivative_relative,
		'output_format' => 'image/webp',
		'derivative_sha256' => $derivative_hash,
		'input_bytes' => filesize( $target['source_path'] ),
		'output_bytes' => filesize( $derivative ),
		'encoder_id' => 'cwebp',
		'encoder_parameters' => '{}',
	)
);
if ( false === $record_id ) {
	fwrite( STDERR, "Could not register the synthetic derivative fixture.\n" );
	exit( 1 );
}
echo $derivative;
PHP
registered_derivative="$(wp_cli eval-file "$record_script")"
[[ "$registered_derivative" == "$derivative_path" ]]
verify="$(wp_cli jio recovery verify --attachment-id="$attachment_id")"
printf '%s\n' "$verify"
grep -q 'integrity: valid' <<< "$verify"
preview="$(wp_cli jio recovery preview --attachment-id="$attachment_id")"
printf '%s\n' "$preview"
grep -q 'Eligible files: 1' <<< "$preview"
if wp_cli jio recovery cleanup --attachment-id="$attachment_id"; then
	echo 'Cleanup without --confirm unexpectedly succeeded.' >&2
	exit 1
fi
test -f "$source_path"
test -f "$derivative_path"
if wp_cli jio recovery cleanup --attachment-id=999999999 --confirm; then
	echo 'Cleanup accepted an invalid attachment ID.' >&2
	exit 1
fi
if wp_cli jio recovery cleanup --attachment-id="$attachment_id" --confirm; then
	echo 'Cleanup succeeded while the fail-closed default was active.' >&2
	exit 1
fi
if wp_cli jio recovery bulk --action=start --confirm; then
	echo 'Bulk cleanup started while the fail-closed default was active.' >&2
	exit 1
fi
status="$(wp_cli jio recovery status)"
grep -q 'Cleanup batch: none' <<< "$status"
test -f "$source_path"
test -f "$derivative_path"
export JIO_ENABLE_DESTRUCTIVE_RECOVERY=1
wp_cli eval-file "$PWD/bin/test-recovery-concurrency.php"
wp_cli eval-file "$PWD/bin/prepare-claim-race.php"
claim_output_one="$RUNNER_TEMP/jio-claim-worker-one.txt"
claim_output_two="$RUNNER_TEMP/jio-claim-worker-two.txt"
JIO_CLAIM_TOKEN="$(php -r 'echo bin2hex(random_bytes(16));')" wp_cli eval-file "$PWD/bin/test-claim-race-worker.php" > "$claim_output_one" &
claim_pid_one=$!
JIO_CLAIM_TOKEN="$(php -r 'echo bin2hex(random_bytes(16));')" wp_cli eval-file "$PWD/bin/test-claim-race-worker.php" > "$claim_output_two" &
claim_pid_two=$!
wp_cli eval-file "$PWD/bin/release-claim-race.php" > "$RUNNER_TEMP/jio-claim-barrier.txt" &
claim_barrier_pid=$!
wait "$claim_pid_one"
wait "$claim_pid_two"
wait "$claim_barrier_pid"
cat "$claim_output_one" "$claim_output_two"
claimed_count="$(awk '/^CLAIMED:/ { count++ } END { print count + 0 }' "$claim_output_one" "$claim_output_two")"
empty_count="$(awk '$0 == "EMPTY" { count++ } END { print count + 0 }' "$claim_output_one" "$claim_output_two")"
[[ "$claimed_count" == '1' && "$empty_count" == '1' ]]
echo 'Concurrent independent WP-CLI queue claim barrier passed.'
cleanup="$(wp_cli jio recovery cleanup --attachment-id="$attachment_id" --confirm)"
printf '%s\n' "$cleanup"
grep -q 'removed 1 derivatives' <<< "$cleanup"
test -f "$source_path"
test ! -e "$derivative_path"
repeat="$(wp_cli jio recovery cleanup --attachment-id="$attachment_id" --confirm)"
printf '%s\n' "$repeat"
grep -q 'removed 0 derivatives' <<< "$repeat"

wp_debug_output="$(wp_cli eval 'echo defined("WP_DEBUG") ? "WP_DEBUG_DEFINED\n" : "WP_DEBUG_MISSING\n";' 2>&1)"
if grep -q 'Constant WP_DEBUG already defined' <<< "$wp_debug_output"; then
	echo "$wp_debug_output" >&2
	exit 1
fi
grep -q 'WP_DEBUG_DEFINED' <<< "$wp_debug_output"
