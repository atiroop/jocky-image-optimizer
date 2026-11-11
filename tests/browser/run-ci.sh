#!/usr/bin/env bash

set -euo pipefail

: "${RUNNER_TEMP:?RUNNER_TEMP is required}"
: "${GITHUB_WORKSPACE:?GITHUB_WORKSPACE is required}"
: "${JIO_VERSION:?JIO_VERSION is required}"

readonly WP_VERSION='7.1.4'
readonly WP_CORE_SHA1='b552c39cd95d89895b4e7a55af53a03687aff4f6'
readonly ELEMENTOR_VERSION='4.4.0'
readonly ELEMENTOR_SHA256='EA023ED8F86106BC862E91269190FE95A725A865049C53A0035F0CCC668D69C2'
readonly WP_CLI_VERSION='2.12.0'
readonly WP_CLI_PHAR="$RUNNER_TEMP/wp-cli-${WP_CLI_VERSION}.phar"
readonly WP_CORE_DIR="$RUNNER_TEMP/jio-browser-wordpress"
readonly WRAPPER_DIR="$RUNNER_TEMP/jio-encoder-wrappers"
readonly ENCODER_MARKER="$RUNNER_TEMP/jio-encoder-invocations.log"
readonly SITE_URL='http://127.0.0.1:8080'

cd "$GITHUB_WORKSPACE"
printf '%s\n' "PHP runtime: $(php -r 'echo PHP_VERSION;')"
[[ "$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" == '8.3' ]]

# Keep frontend code free of full-file hashes, encoder managers, and process calls.
if grep -R -nE 'hash_file|ProcessRunner|OptimizationManager|proc_open|shell_exec|exec\(' src/Delivery; then
	echo 'Frontend delivery contains file hashing or process execution references.' >&2
	exit 1
fi

# Build the exact production ZIP consumed by this disposable site.
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php scripts/package.php
composer test:package
readonly PACKAGE_ZIP="build/jocky-image-optimizer-${JIO_VERSION}.zip"
unzip -t "$PACKAGE_ZIP"

# Download pinned upstream artifacts and verify their published/pinned digests.
curl --fail --silent --show-error --location --output "$WP_CLI_PHAR" "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"
curl --fail --silent --show-error --location --output "$RUNNER_TEMP/wp-cli-${WP_CLI_VERSION}.phar.sha512" "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar.sha512"
printf '%s  %s\n' "$(cat "$RUNNER_TEMP/wp-cli-${WP_CLI_VERSION}.phar.sha512")" "$WP_CLI_PHAR" | sha512sum --check --status

readonly ELEMENTOR_ZIP="$RUNNER_TEMP/elementor-${ELEMENTOR_VERSION}.zip"
readonly ELEMENTOR_METADATA="$RUNNER_TEMP/elementor-wordpress-org.json"
curl --fail --silent --show-error --location --get \
	--data-urlencode 'action=plugin_information' \
	--data-urlencode 'request[slug]=elementor' \
	--data-urlencode 'request[fields][versions]=1' \
	--output "$ELEMENTOR_METADATA" \
	'https://api.wordpress.org/plugins/info/1.2/'
curl --fail --silent --show-error --location --output "$ELEMENTOR_ZIP" "https://downloads.wordpress.org/plugin/elementor.${ELEMENTOR_VERSION}.zip"
printf '%s  %s\n' "$ELEMENTOR_SHA256" "$ELEMENTOR_ZIP" | sha256sum --check --status
php tests/browser/verify-elementor.php "$ELEMENTOR_METADATA" "$ELEMENTOR_VERSION" "$WP_VERSION" 8.3 "$ELEMENTOR_ZIP"

# Install a clean WordPress 7.1.4 site and verify core checksums.
readonly WP_CORE_ARCHIVE="$RUNNER_TEMP/wordpress-${WP_VERSION}.zip"
curl --fail --silent --show-error --location --output "$WP_CORE_ARCHIVE" "https://wordpress.org/wordpress-${WP_VERSION}.zip"
printf '%s  %s\n' "$WP_CORE_SHA1" "$WP_CORE_ARCHIVE" | sha1sum --check --status
unzip -tq "$WP_CORE_ARCHIVE"
unzip -q "$WP_CORE_ARCHIVE" -d "$RUNNER_TEMP"
mv "$RUNNER_TEMP/wordpress" "$WP_CORE_DIR"
mkdir -p "$WP_CORE_DIR/wp-content/plugins" "$WP_CORE_DIR/wp-content/mu-plugins"
unzip -q "$PACKAGE_ZIP" -d "$WP_CORE_DIR/wp-content/plugins"
unzip -q "$ELEMENTOR_ZIP" -d "$WP_CORE_DIR/wp-content/plugins"
grep -q "Version: ${ELEMENTOR_VERSION}" "$WP_CORE_DIR/wp-content/plugins/elementor/elementor.php"
cp tests/browser/jio-browser-fixture.php "$WP_CORE_DIR/wp-content/mu-plugins/jio-browser-fixture.php"

php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" config create \
	--dbname=wordpress_browser --dbuser=root --dbpass=root --dbhost=127.0.0.1:3306 \
	--dbprefix=jio_browser_ --skip-check
php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" core install \
	--url="$SITE_URL" --title='JIO Disposable Browser Acceptance' \
	--admin_user=jio_browser_admin --admin_password='disposable-browser-password' \
	--admin_email=jio-browser@example.invalid --skip-email
php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option update permalink_structure ''
php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option update thumbnail_size_w 150
php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option update thumbnail_size_h 100
php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option update thumbnail_crop 0
php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option update medium_size_w 400
php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option update medium_size_h 0
php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option update large_size_w 1000
php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option update large_size_h 0
php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" plugin activate elementor jocky-image-optimizer

[[ "$(php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" core version)" == "$WP_VERSION" ]]
[[ "$(php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" plugin get elementor --field=version)" == "$ELEMENTOR_VERSION" ]]
[[ "$(php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" plugin get jocky-image-optimizer --field=version)" == "$JIO_VERSION" ]]

# Wrap only trusted system encoder paths so the test can prove frontend requests do not execute them.
mkdir -p "$WRAPPER_DIR"
: > "$ENCODER_MARKER"
export JIO_ENCODER_MARKER="$ENCODER_MARKER"
for encoder in cwebp avifenc; do
	encoder_path="$(command -v "$encoder")"
	cat > "$WRAPPER_DIR/$encoder" <<EOF
#!/usr/bin/env bash
printf '%s\\n' '$encoder' >> "\$JIO_ENCODER_MARKER"
exec '$encoder_path' "\$@"
EOF
	chmod 0755 "$WRAPPER_DIR/$encoder"
done
export PATH="$WRAPPER_DIR:$PATH"

php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" eval-file "$GITHUB_WORKSPACE/tests/browser/setup-site.php"
readonly FIXTURE_JSON="$(php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option get jio_browser_fixture_data --format=json)"
readonly ENCODER_COUNT_BEFORE="$(wc -l < "$ENCODER_MARKER" | tr -d '[:space:]')"

# This is a disposable local HTTP origin, with no page cache or external services.
php -S 127.0.0.1:8080 -t "$WP_CORE_DIR" "$GITHUB_WORKSPACE/tests/browser/php-router.php" > "$RUNNER_TEMP/jio-browser-php-server.log" 2>&1 &
readonly PHP_SERVER_PID=$!
cleanup() {
	kill "$PHP_SERVER_PID" 2>/dev/null || true
}
trap cleanup EXIT
for attempt in $(seq 1 30); do
	if curl --fail --silent "$SITE_URL/" >/dev/null; then
		break
	fi
	sleep 1
done
curl --fail --silent "$SITE_URL/" >/dev/null

cd "$GITHUB_WORKSPACE/tests/browser"
npm ci --no-audit --no-fund
npm audit
npx playwright install --with-deps chromium
JIO_BROWSER_FIXTURE="$FIXTURE_JSON" JIO_BROWSER_BASE_URL="$SITE_URL" node run-browser-tests.js

readonly ENCODER_COUNT_AFTER="$(wc -l < "$ENCODER_MARKER" | tr -d '[:space:]')"
[[ "$ENCODER_COUNT_BEFORE" == "$ENCODER_COUNT_AFTER" ]]
echo "Frontend browser requests executed no cwebp/avifenc process ($ENCODER_COUNT_BEFORE encoder invocations before and after)."
