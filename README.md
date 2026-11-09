# Jocky Image Optimizer

Jocky Image Optimizer is a self-hosted WordPress plugin foundation for local image optimization. This development release implements **Milestone 1 only**: safe activation, settings storage, admin diagnostics, documentation, checks, and packaging. It does not process images, change attachment metadata, create derivatives, or alter uploads.

## Requirements

- WordPress 6.6 or later
- PHP 8.3 or later
- Composer 2 for development/package creation
- PHP `ZipArchive` for ZIP packaging
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
composer lint
composer test
composer install --no-dev --no-interaction --optimize-autoloader
php scripts/package.php
```

See [VALIDATION.md](VALIDATION.md) for checks actually run and unresolved limits. Local validation used PHP 8.5; PHP 8.3 and real WordPress activation/admin integration were unavailable. GitHub Actions is configured to run on PHP 8.3.

## Security and scope

This release does not execute shell commands or accept file paths. Do not treat configured format/quality settings as active processing controls until a later milestone implements and verifies those behaviors. Cloudflare R2, bulk jobs, upload hooks, backups/restoration, and image processing are deferred.

## License

GPL-2.0-or-later.
