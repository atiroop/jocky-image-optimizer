# Jocky Image Optimizer

Jocky Image Optimizer is a self-hosted WordPress plugin for local image optimization. This development release implements the plugin foundation, WordPress integration validation, and a developer-facing JPEG/PNG processing API. Processing is explicit and writes a separate output file; uploads, attachments, thumbnails, and metadata are not hooked or modified.

## Requirements

- WordPress 6.6 or later
- PHP 8.3 or later
- Composer 2 for development/package creation
- PHP `ZipArchive` for ZIP packaging
- PHP extensions `mysqli`, `mbstring`, `xml`, and `zip` for WordPress integration tests
- Disposable MySQL 8.0/MariaDB test database for WordPress integration tests
- A Linux server is recommended for future command-line encoder integrations. Windows is supported for development, but server encoder availability and behavior must be checked on the target Linux host.

Image processing requires locally installed `jpegtran`, `djpeg` plus MozJPEG-compatible `cjpeg`, `pngquant`, or `oxipng`, depending on the selected adapter. No encoder is required to activate the plugin. The dashboard reports detected libraries and binaries. It never installs system packages.

## Development installation

1. For development, run `composer install --no-interaction`. To build a production ZIP, run `composer install --no-dev --no-interaction --optimize-autoloader` first. The package includes the Composer autoloader and runtime dependencies; development tools are excluded.
2. Copy the `jocky-image-optimizer` directory to `wp-content/plugins/` (or use the generated ZIP).
3. Activate **Jocky Image Optimizer** from WordPress Plugins.
4. Open **Image Optimizer** for runtime diagnostics and **Image Optimizer → Settings** to configure stored preferences.

To produce an installable ZIP, install production dependencies as above, then run `php scripts/package.php`. Packaging stops if Composer development dependencies are present. The archive is written to `build/jocky-image-optimizer-0.1.0-dev.zip` with the expected top-level plugin directory. Build artifacts are excluded from Git.

## Current behavior

Settings are registered through the WordPress Settings API, capability-gated with `manage_options`, nonce-protected by `settings_fields()`, and sanitized on save. Worker concurrency is deliberately pinned to one. Displayed capability information comes from the running PHP process and filesystem PATH; it is not simulated. `OptimizationManager` offers an explicit source-to-separate-output API with path confinement, size/pixel/time bounds, encoder argv execution without shell interpolation, output validation, no-clobber promotion, and measured result reporting. It is not connected to WordPress media hooks or admin controls.

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

`composer test:encoders` runs deterministic real-image integration checks and requires `cjpeg`, `djpeg`, `jpegtran`, `pngquant`, `oxipng`, and PHP GD. The isolated `encoder-integration` GitHub job builds MozJPEG from an exact upstream commit, installs `pngquant` from Ubuntu 24.04 packages, and installs a locked oxipng release through Cargo. It does not download prebuilt encoder binaries. Run this test only on a disposable development/CI runner; it writes temporary fixtures and outputs.

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

The processing API executes only known local encoder binaries when explicitly called. It does not use shell command strings, auto-install tools, or accept executable paths from request data. Lossy adapters require metadata preservation to be disabled because they cannot guarantee retention. APNG is rejected. Cloudflare R2, bulk jobs, upload hooks, backups/restoration, and automatic media mutation remain deferred.

## License

GPL-2.0-or-later.
