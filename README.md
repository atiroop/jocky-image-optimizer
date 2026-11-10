# Jocky Image Optimizer 0.5.0-beta

Jocky Image Optimizer is a self-hosted WordPress plugin for local image optimization. The first installable beta adds Media Library actions, a bounded background queue, and JPEG/PNG-to-WebP/AVIF derivatives. Original uploads and WordPress attachment metadata remain unchanged, and derivative files stay inactive for frontend delivery.

The `VERSION` file is the authoritative release version. Packaging and CI reject mismatches in the plugin header, runtime constant, this title, changelog release heading, ZIP metadata, filename, or artifact name. The beta ZIP is built and tested by GitHub Actions on PHP 8.3 with WordPress 6.6.11 and 7.1.4. See [VALIDATION.md](VALIDATION.md) for current validation evidence and limitations.

## Recovery and derivative lifecycle

Milestone 5 records source and derivative SHA-256 digests, uploads-relative paths, attachment metadata references, encoder details, and lifecycle/audit state in plugin-owned tables. Recovery verification, candidate previews, bounded legacy scans, and history are available. **Destructive per-attachment and bulk cleanup are disabled by default.** The admin UI and WP-CLI fail closed unless `JIO_ENABLE_DESTRUCTIVE_RECOVERY` is explicitly true and WordPress reports a `local` or `development` environment. This opt-in exists for disposable testing; do not enable cleanup on production or staging while WordPress core metadata/deletion paths remain outside the plugin lock. CLI cleanup also requires `--confirm`.

The plugin optimizer writes separate no-clobber derivatives and does not replace originals. Cleanup previews and verification are read-only apart from registry/audit updates. Before any explicitly enabled local/development cleanup, the backend rechecks attachment existence, current metadata, expected source/derivative paths, registry ownership, active queue work, file digests and file identity, and refuses paths currently managed by WordPress as original or thumbnail files. The plugin lock cannot prevent a hostile filesystem writer from changing a path after the last check, so the uploads tree must remain writable only by trusted site processes. Missing/corrupt originals require UpdraftPlus or another verified site backup; derivatives are never used to reconstruct originals. Frontend delivery remains inactive. See [ARCHITECTURE.md](ARCHITECTURE.md) and [VALIDATION.md](VALIDATION.md) for the safety boundary and test evidence.

## Requirements

- WordPress 6.6 or later
- PHP 8.3 or later
- Composer 2 for development/package creation
- PHP `ZipArchive` for ZIP packaging
- PHP extensions `mysqli`, `mbstring`, `xml`, and `zip` for WordPress integration tests
- Disposable MySQL 8.0/MariaDB test database for WordPress integration tests
- A Linux server is recommended for future command-line encoder integrations. Windows is supported for development, but server encoder availability and behavior must be checked on the target Linux host.

Image processing requires locally installed `jpegtran`, `djpeg` plus MozJPEG-compatible `cjpeg`, `pngquant`, `oxipng`, `cwebp`, or `avifenc`, depending on the selected adapter. Independent test decoding uses `dwebp` and `avifdec`. No encoder is required to activate the plugin. The dashboard reports detected libraries and binaries. It never installs system packages.

## Install the beta from the WordPress Dashboard

1. Open the successful release CI run linked in [VALIDATION.md](VALIDATION.md), then download the `jocky-image-optimizer-0.5.0-beta` artifact.
2. Extract the downloaded Actions artifact archive. It contains the installable `jocky-image-optimizer-0.5.0-beta.zip`.
3. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, select that inner plugin ZIP, and click **Install Now**.
4. Click **Activate Plugin** after installation completes.
5. Open **Image Optimizer** to review encoder availability and queue progress. Open **Image Optimizer → Settings** to choose output formats and other settings.

Activation creates the versioned queue tables and initializes the scanner and worker slots. Automatic optimization is off by default. Manual attachment queueing is available from the Media Library; encoding happens later through WP-Cron or the WP-CLI worker. The plugin does not install system packages. Install required encoders separately and confirm their status on the dashboard before queueing work.

### Build the installable ZIP from source

For development, run `composer install --no-interaction`. To produce the release ZIP, run `composer install --no-dev --no-interaction --optimize-autoloader`, then `php scripts/package.php` and `php scripts/validate-package.php`. The output is `build/jocky-image-optimizer-$(cat VERSION).zip`; it has exactly one `jocky-image-optimizer/` top-level directory, includes the matching `VERSION` metadata and production Composer autoloader, and excludes development tools. Build artifacts are excluded from Git. See [RELEASING.md](RELEASING.md) for the version bump and release workflow.

For staging, take a database and uploads backup first. The plugin writes separate derivatives and does not swap frontend URLs, replace media, or change Elementor markup. Review the prerequisites and known limitations below before queueing work.

## Current behavior

Settings are registered through the WordPress Settings API, capability-gated with `manage_options`, nonce-protected by `settings_fields()`, and sanitized on save. Automatic optimization is off by default. The Media Library row action can queue a selected attachment, and the dashboard can scan the library in pages, pause/resume, cancel pending jobs, retry failures, and show persisted results. Queue rows and worker/scanner leases are stored in prefixed InnoDB tables; schema versioning runs on activation and subsequent plugin loads. WP-Cron processes one job per tick and depends on site traffic unless an external scheduler calls WordPress cron. For controlled execution, use `wp jio worker run --batch-size=10` from WP-CLI. The concurrency setting caps simultaneous workers at four and defaults to one. See `ARCHITECTURE.md` and `VALIDATION.md` for implementation and verification status.

The optional upload hook only enqueues after WordPress generates attachment metadata; it does not process images in the upload request. Eligible local JPEG/PNG originals and registered sizes are processed into separate `.jio.webp`/`.jio.avif` derivatives, recorded as hidden plugin-owned attachment metadata, and left inactive for frontend delivery. `_wp_attached_file`, core image metadata, source files, and thumbnails are not replaced. Attachments whose effective file path is filtered/offloaded or outside the current uploads root are skipped. No Cloudflare R2 compatibility is claimed.

```php
use Jocky\ImageOptimizer\Optimization\OptimizationRequest;

$request = new OptimizationRequest(
    $jpeg_path,
    $webp_path,
    'cwebp',
    OptimizationRequest::MODE_LOSSY,
    78,
    60,
    false,
    52428800,
    40000000,
    120000,
    OptimizationRequest::OUTPUT_WEBP
);
$result = $optimization_manager->optimize( $request );
```

The constructor takes source and destination paths, encoder ID, mode, quality, minimum quality, metadata policy, input-byte and pixel limits, timeout milliseconds, output MIME type, larger-output policy, and AVIF speed in that order. Only paths beneath the manager's configured roots are accepted.

Activation adds defaults only when no settings option exists. Deactivation has no scheduled jobs to clean up. Uninstall behavior is not defined and no media, backups, or records are removed.

## Checks and validation

```sh
composer install --no-interaction
composer validate --no-check-publish
composer audit --locked
composer lint
composer test
composer install --no-dev --no-interaction --optimize-autoloader
php scripts/package.php
composer test:package
composer install --no-interaction
# After provisioning the disposable WordPress/MySQL environment below:
composer test:integration
```

`composer test` contains dependency-free unit checks with WordPress API stubs. `composer test:integration` runs against a real WordPress core and disposable database; it installs and activates the generated ZIP, not the development source. See [VALIDATION.md](VALIDATION.md) for actual execution status and limitations.

`composer test:encoders` runs deterministic real-image integration checks and requires `cjpeg`, `djpeg`, `jpegtran`, `pngquant`, `oxipng`, `cwebp`, `avifenc`, `dwebp`, `avifdec`, and PHP GD. The isolated CI job builds/installs encoders from trusted Linux package or upstream sources and uses independent decoders for generated output. It does not download arbitrary prebuilt binaries. The fixed fixtures contain gradients, sharp color boundaries, and transparent samples; benchmark numbers are reported by the test run and are not universal performance claims. Run this test only on a disposable development/CI runner; it writes temporary fixtures and outputs.

### Local WordPress integration setup

Use Linux or WSL2 with PHP 8.3, Composer, Git, curl, Subversion, tar/unzip, and a disposable MySQL/MariaDB database. Do not point this procedure at a production database. Create an empty database named `wordpress_test` (for example, `mysql -u root -p -e 'CREATE DATABASE wordpress_test'`), then:

```sh
composer install --no-interaction
export TMPDIR="$(mktemp -d)"
export WP_CORE_DIR="$TMPDIR/wordpress"
export WP_TESTS_DIR="$TMPDIR/wordpress-tests-lib"
export WP_INSTALL_TESTS_SKIP_UPDATE_CHECK=true
bash bin/install-wp-tests.sh wordpress_test root 'test-only-password' 127.0.0.1 6.6.11 true
composer install --no-dev --no-interaction --optimize-autoloader
php scripts/package.php
composer install --no-interaction
PACKAGE_ZIP="build/jocky-image-optimizer-$(cat VERSION).zip"
unzip -q "$PACKAGE_ZIP" -d "$WP_CORE_DIR/wp-content/plugins"
export JIO_PACKAGE_PATH="$PWD/$PACKAGE_ZIP"
composer test:integration
```

The installer puts WordPress core and test files under `TMPDIR`; remove that uniquely created temporary directory and drop only the disposable `wordpress_test` database after testing. The installer refuses to recursively remove pre-existing core or test-library paths. The installer script is vendored from a pinned WP-CLI scaffold-command revision. GitHub Actions provisions and tears down its own temporary MySQL service and tests WordPress 6.6.11 and 7.1.4 on PHP 8.3.

## Security and scope

The processing API executes only known local encoder binaries using argument arrays, never shell command strings. It does not install tools or accept executable paths from request data. Cross-format conversion rejects EXIF-bearing sources because orientation cannot be guaranteed; other detected metadata requires an explicit opt-out and may be stripped. APNG and animated WebP/AVIF are rejected. AVIF structural validation checks bounded ISO-BMFF box structure and primary dimensions; independent decoder acceptance is an integration-test check, not runtime verification. Runtime validation cannot establish perceptual equivalence. Cloudflare R2, offloaded storage, frontend URL replacement, Elementor markup changes, automatic cron setup, backup/restoration, and multisite validation are not included in this beta.

## License

GPL-2.0-or-later.
