# Jocky Image Optimizer

Jocky Image Optimizer is a self-hosted WordPress plugin for local image optimization. The development release includes explicit JPEG/PNG optimization and JPEG/PNG-to-WebP/AVIF conversion APIs. Every operation writes a separate output file; uploads, attachments, thumbnails, and metadata are not hooked or modified.

Milestone 3’s WebP/AVIF implementation passed its PHP 8.3 GitHub Actions validation, including real encoders and WordPress package integration against WordPress 6.6.11 and 7.1.4. See [VALIDATION.md](VALIDATION.md) for the run link, measured fixture results, and remaining limits. This verification does not include live website/PHP-FPM encoder availability or automatic WordPress media delivery.

## Requirements

- WordPress 6.6 or later
- PHP 8.3 or later
- Composer 2 for development/package creation
- PHP `ZipArchive` for ZIP packaging
- PHP extensions `mysqli`, `mbstring`, `xml`, and `zip` for WordPress integration tests
- Disposable MySQL 8.0/MariaDB test database for WordPress integration tests
- A Linux server is recommended for future command-line encoder integrations. Windows is supported for development, but server encoder availability and behavior must be checked on the target Linux host.

Image processing requires locally installed `jpegtran`, `djpeg` plus MozJPEG-compatible `cjpeg`, `pngquant`, `oxipng`, `cwebp`, or `avifenc`, depending on the selected adapter. Independent test decoding uses `dwebp` and `avifdec`. No encoder is required to activate the plugin. The dashboard reports detected libraries and binaries. It never installs system packages.

## Development installation

1. For development, run `composer install --no-interaction`. To build a production ZIP, run `composer install --no-dev --no-interaction --optimize-autoloader` first. The package includes the Composer autoloader and runtime dependencies; development tools are excluded.
2. Copy the `jocky-image-optimizer` directory to `wp-content/plugins/` (or use the generated ZIP).
3. Activate **Jocky Image Optimizer** from WordPress Plugins.
4. Open **Image Optimizer** for runtime diagnostics and **Image Optimizer → Settings** to configure stored preferences.

To produce an installable ZIP, install production dependencies as above, then run `php scripts/package.php`. Packaging stops if Composer development dependencies are present. The archive is written to `build/jocky-image-optimizer-0.1.0-dev.zip` with the expected top-level plugin directory. Build artifacts are excluded from Git.

## Current behavior

Settings are registered through the WordPress Settings API, capability-gated with `manage_options`, nonce-protected by `settings_fields()`, and sanitized on save. Automatic optimization is off by default. The Media Library row action can queue a selected attachment, and the dashboard can scan the library in pages, pause/resume, cancel pending jobs, retry failures, and show persisted results. Queue rows and worker/scanner leases are stored in prefixed InnoDB tables; schema versioning runs on activation and subsequent plugin loads. WP-Cron processes one job per tick and depends on site traffic unless an external scheduler calls WordPress cron. For controlled execution, use `wp jio worker run --batch-size=10` from WP-CLI. The concurrency setting caps simultaneous workers at four and defaults to one. See `ARCHITECTURE.md` and `VALIDATION.md` for implementation and verification status.

The upload hook only enqueues after WordPress generates attachment metadata; encoding runs later in the worker. Eligible local JPEG/PNG originals and registered sizes are processed into separate `.jio.webp`/`.jio.avif` derivatives, recorded as hidden plugin-owned attachment metadata, and left inactive for frontend delivery. `_wp_attached_file`, core image metadata, source files, and thumbnails are not replaced. Attachments whose effective file path is filtered/offloaded or outside the current uploads root are skipped. This includes safe refusal for remote storage until the R2 milestone.

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
unzip -q build/jocky-image-optimizer-0.1.0-dev.zip -d "$WP_CORE_DIR/wp-content/plugins"
export JIO_PACKAGE_PATH="$PWD/build/jocky-image-optimizer-0.1.0-dev.zip"
composer test:integration
```

The installer puts WordPress core and test files under `TMPDIR`; remove that uniquely created temporary directory and drop only the disposable `wordpress_test` database after testing. The installer refuses to recursively remove pre-existing core or test-library paths. The installer script is vendored from a pinned WP-CLI scaffold-command revision. GitHub Actions provisions and tears down its own temporary MySQL service and tests WordPress 6.6.11 and 7.1.4 on PHP 8.3.

## Security and scope

The processing API executes only known local encoder binaries when explicitly called. It does not use shell command strings, auto-install tools, or accept executable paths from request data. Cross-format conversion rejects EXIF-bearing sources because it cannot guarantee orientation handling; other detected metadata requires an explicit opt-out and may be stripped. APNG and animated WebP/AVIF are rejected. AVIF structural validation checks bounded ISO-BMFF box structure and primary dimensions; independent decoder acceptance is an integration-test check, not runtime verification. Runtime validation cannot establish perceptual equivalence. Cloudflare R2, bulk jobs, upload hooks, backups/restoration, and automatic media mutation remain deferred.

## License

GPL-2.0-or-later.
