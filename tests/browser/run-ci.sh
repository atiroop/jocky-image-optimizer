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
# WP-CLI can derive a subdirectory URL from the temporary --path. Pin and verify
# both persisted WordPress URLs so the filesystem directory never becomes public.
readonly DETECTED_HOME="$(php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option get home)"
readonly DETECTED_SITEURL="$(php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option get siteurl)"
printf 'WP-CLI initially persisted home=%s siteurl=%s\n' "$DETECTED_HOME" "$DETECTED_SITEURL"
php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option update home "$SITE_URL"
php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option update siteurl "$SITE_URL"
readonly ACTUAL_HOME="$(php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option get home)"
readonly ACTUAL_SITEURL="$(php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option get siteurl)"
if [[ "$ACTUAL_HOME" != "$SITE_URL" || "$ACTUAL_SITEURL" != "$SITE_URL" ]]; then
	echo "WordPress origin mismatch: expected home/siteurl $SITE_URL; got home=$ACTUAL_HOME siteurl=$ACTUAL_SITEURL" >&2
	exit 1
fi
printf 'Verified persisted WordPress home and siteurl: %s\n' "$SITE_URL"
readonly RUNTIME_ORIGINS="$(php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" eval 'echo home_url() . "\n" . site_url();')"
if [[ "$RUNTIME_ORIGINS" != "$SITE_URL"$'\n'"$SITE_URL" ]]; then
	echo "WordPress runtime URL helpers disagree with the persisted options: $RUNTIME_ORIGINS" >&2
	exit 1
fi
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
export JIO_EXPECTED_DOCUMENT_ROOT="$WP_CORE_DIR"
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
readonly HOME_STATUS="$(curl --silent --dump-header "$RUNNER_TEMP/jio-home-preflight.headers" \
	--output "$RUNNER_TEMP/jio-home-preflight.html" --write-out '%{http_code}' "$SITE_URL/" 2>/dev/null || true)"
if [[ "$HOME_STATUS" != '200' ]] || ! grep -qi '^X-JIO-Test-Document-Root: match' "$RUNNER_TEMP/jio-home-preflight.headers"; then
	echo "Disposable HTTP origin preflight failed at $SITE_URL or its document root did not match the WordPress installation." >&2
	tail -n 30 "$RUNNER_TEMP/jio-browser-php-server.log" >&2
	exit 1
fi

readonly GUTENBERG_PAGE_ID="$(php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" option get jio_browser_gutenberg_page)"
readonly GUTENBERG_URL="$(php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" eval "echo get_permalink(${GUTENBERG_PAGE_ID});")"
readonly GUTENBERG_FIXTURE_URL="$(php -r '$f=json_decode(stream_get_contents(STDIN), true); echo $f["gutenberg_url"] ?? "";' <<< "$FIXTURE_JSON")"
readonly PNG_ATTACHMENT_ID="$(php -r '$f=json_decode(stream_get_contents(STDIN), true); echo $f["attachments"]["png"]["id"] ?? "";' <<< "$FIXTURE_JSON")"
readonly JPEG_ATTACHMENT_ID="$(php -r '$f=json_decode(stream_get_contents(STDIN), true); echo $f["attachments"]["jpeg"]["id"] ?? "";' <<< "$FIXTURE_JSON")"
readonly ELEMENTOR_URL="$(php -r '$f=json_decode(stream_get_contents(STDIN), true); echo $f["elementor_url"] ?? "";' <<< "$FIXTURE_JSON")"
readonly ELEMENTOR_PREFLIGHT_URL="$(php -r '$f=json_decode(stream_get_contents(STDIN), true); echo $f["elementor_preflight_url"] ?? "";' <<< "$FIXTURE_JSON")"
readonly ELEMENTOR_TOKEN="$(php -r '$f=json_decode(stream_get_contents(STDIN), true); echo $f["elementor_token"] ?? "";' <<< "$FIXTURE_JSON")"
readonly CLI_ELEMENTOR_ACTIVE="$(php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" eval 'echo class_exists("\\Elementor\\Plugin") ? "yes" : "no";')"

for url in "$GUTENBERG_URL" "$GUTENBERG_FIXTURE_URL" "$ELEMENTOR_URL" "$ELEMENTOR_PREFLIGHT_URL"; do
	if [[ "$url" != "$SITE_URL/"* ]]; then
		echo "Fixture URL is outside the intended origin $SITE_URL: $url" >&2
		exit 1
	fi
done
[[ "$GUTENBERG_URL" == "$GUTENBERG_FIXTURE_URL" ]] || { echo 'get_permalink() and fixture Gutenberg URL disagree.' >&2; exit 1; }
[[ "$CLI_ELEMENTOR_ACTIVE" == 'yes' ]] || { echo 'Elementor plugin is not active in the disposable WordPress runtime.' >&2; exit 1; }

check_http_200() {
	local description="$1"
	local url="$2"
	local output_file="$3"
	shift 3
	local status
	status="$(curl --silent --output "$output_file" --write-out '%{http_code}' "$@" "$url" 2>/dev/null || true)"
	if [[ "$status" != '200' ]]; then
		echo "$description preflight failed: HTTP ${status:-no response} at the expected local origin." >&2
		tail -n 30 "$RUNNER_TEMP/jio-browser-php-server.log" >&2
		return 1
	fi
}

check_http_200 'Gutenberg fixture' "$GUTENBERG_URL" "$RUNNER_TEMP/jio-gutenberg-preflight.html"
for attachment_id in "$PNG_ATTACHMENT_ID" "$JPEG_ATTACHMENT_ID"; do
	if ! grep -Fq "wp-image-${attachment_id}" "$RUNNER_TEMP/jio-gutenberg-preflight.html"; then
		readonly POST_STATUS="$(php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" post get "$GUTENBERG_PAGE_ID" --field=post_status)"
		readonly POST_CONTENT="$(php "$WP_CLI_PHAR" --allow-root --path="$WP_CORE_DIR" post get "$GUTENBERG_PAGE_ID" --field=post_content)"
		readonly CONTENT_HAS_IMAGE="$(grep -Fq "wp-image-${attachment_id}" <<< "$POST_CONTENT" && echo yes || echo no)"
		echo "Gutenberg HTTP 200 page omitted wp-image-${attachment_id}; page_id=$GUTENBERG_PAGE_ID status=$POST_STATUS stored_content_has_image=$CONTENT_HAS_IMAGE URL=$GUTENBERG_URL" >&2
		echo 'Stored Gutenberg content prefix:' >&2
		printf '%s' "$POST_CONTENT" | head -c 1200 >&2
		echo >&2
		echo 'Server response body prefix:' >&2
		head -c 800 "$RUNNER_TEMP/jio-gutenberg-preflight.html" >&2
		echo >&2
		exit 1
	fi
done
readonly ELEMENTOR_STATUS="$(curl --silent --dump-header "$RUNNER_TEMP/jio-elementor-preflight.headers" \
	--output "$RUNNER_TEMP/jio-elementor-preflight.html" --write-out '%{http_code}' \
	-H "X-JIO-Browser-Token: $ELEMENTOR_TOKEN" "$ELEMENTOR_PREFLIGHT_URL" 2>/dev/null || true)"
if [[ "$ELEMENTOR_STATUS" != '200' ]] || ! grep -qi "^X-JIO-Elementor-Version: ${ELEMENTOR_VERSION}" "$RUNNER_TEMP/jio-elementor-preflight.headers"; then
	echo "Elementor fixture preflight failed: expected HTTP 200 and runtime version ${ELEMENTOR_VERSION}." >&2
	tail -n 30 "$RUNNER_TEMP/jio-browser-php-server.log" >&2
	exit 1
fi

for asset in png jpeg; do
	image_url="$(php -r '$f=json_decode(stream_get_contents(STDIN), true); echo $f["attachments"][$argv[1]]["url"] ?? "";' "$asset" <<< "$FIXTURE_JSON")"
	image_type='image/png'
	[[ "$asset" == 'jpeg' ]] && image_type='image/jpeg'
	image_result="$(curl --silent --output /dev/null --write-out '%{http_code} %{content_type}' "$image_url" 2>/dev/null || true)"
	if [[ "$image_result" != "200 $image_type" ]]; then
		echo "Synthetic $asset image preflight failed: expected HTTP 200 $image_type; got ${image_result:-no response}." >&2
		exit 1
	fi
done
printf 'Preflight passed: Gutenberg page %s (%s), Elementor %s, and both synthetic originals are HTTP-accessible from %s.\n' "$GUTENBERG_PAGE_ID" "$GUTENBERG_URL" "$ELEMENTOR_VERSION" "$SITE_URL"

cd "$GITHUB_WORKSPACE/tests/browser"
npm ci --no-audit --no-fund
npm audit
npx playwright install --with-deps chromium
JIO_BROWSER_FIXTURE="$FIXTURE_JSON" JIO_BROWSER_BASE_URL="$SITE_URL" node run-browser-tests.js

readonly ENCODER_COUNT_AFTER="$(wc -l < "$ENCODER_MARKER" | tr -d '[:space:]')"
[[ "$ENCODER_COUNT_BEFORE" == "$ENCODER_COUNT_AFTER" ]]
echo "Frontend browser requests executed no cwebp/avifenc process ($ENCODER_COUNT_BEFORE encoder invocations before and after)."
