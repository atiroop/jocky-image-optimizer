# Jocky Image Optimizer

Jocky Image Optimizer is a self-hosted WordPress plugin foundation for local image optimization. This development release implements Milestone 1 and adds Milestone 1.5 validation tooling: safe activation, settings storage, admin diagnostics, WordPress integration tests, CI, and packaging. Image processing remains unimplemented; the plugin does not alter uploads.

## Requirements

- WordPress 6.6 or later
- PHP 8.3 or later
- Composer 2 for development/package creation
- PHP `ZipArchive` for ZIP packaging
- PHP extensions `mysqli`, `mbstring`, `xml`, and `zip` for WordPress integration tests
- Disposable MySQL 8.0/MariaDB test database for WordPress integration tests
- A Linux server is recommended for future command-line encoder integrations. Windows is supported for development, but server encoder availability and behavior must be checked on the target Linux host.

No image encoder is required to activate this milestone. The dashboard reports detected GD, Imagick, WebP/AVIF support, and selected encoder executables. It never installs system packages.

## Development installation

1. For development, run `composer install --no-interaction`. To build a production ZIP, run `composer install --no-dev --no-interaction --optimize-autoloader` first. The package includes the Composer autoloader and runtime dependencies; development tools are excluded.
2. Copy the `jocky-image-optimizer` directory to `wp-content/plugins/` (or use the generated ZIP).
3. Activate **Jocky Image Optimizer** from WordPress Plugins.
4. Open **Image Optimizer** for runtime diagnostics and **Image Optimizer → Settings** to configure stored preferences.

To produce an installable ZIP, install production dependencies as above, then run `php scripts/package.php`. Packaging stops if Composer development dependencies are present. The archive is written to `build/jocky-image-optimizer-0.1.0-dev.zip` with the expected top-level plugin directory. Build artifacts are excluded from Git.

## Current behavior

Settings are registered through the WordPress Settings API, capability-gated with `manage_options`, nonce-protected by `settings_fields()`, and sanitized on save. Worker concurrency is deliberately pinned to one. Displayed capability information comes from the running PHP process and filesystem PATH; it is not simulated. External tools are only located, never invoked in this milestone.

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

This release does not execute shell commands or accept file paths. Do not treat configured format/quality settings as active processing controls until a later milestone implements and verifies those behaviors. Cloudflare R2, bulk jobs, upload hooks, backups/restoration, and image processing are deferred.

## License

GPL-2.0-or-later.
