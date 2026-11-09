# Architecture

## Current foundation

- `jocky-image-optimizer.php`: WordPress plugin header, guarded entry point, Composer autoload, lifecycle hooks, and deferred bootstrap.
- `src/Plugin.php`: lifecycle and admin-only composition root.
- `src/Admin/AdminPages.php`: capability-gated Dashboard and Settings pages, Settings API registration, and escaped diagnostics output.
- `src/Settings.php`: centralized defaults and deterministic input validation.
- `src/CapabilityDetector.php`: checks loaded PHP libraries and their format support, and searches PATH for known encoder executables without invoking them.
- `scripts/package.php`: creates a WordPress-installable ZIP with a `jocky-image-optimizer/` root and includes Composer's production autoloader.
- `tests/integration/PluginIntegrationTest.php`: exercises the packaged plugin against the WordPress PHPUnit runtime, including lifecycle, admin pages, settings, diagnostics, and media fixture preservation.
- `bin/install-wp-tests.sh`: pinned WP-CLI scaffold installer for an isolated WordPress core and test library; it must use a disposable database and temporary paths.

The application has no third-party PHP runtime dependencies. Composer provides PSR-4 autoload metadata. Deployment/package creation runs Composer first and ships `vendor/` so WordPress does not need Composer.

## Safety boundaries

There are no upload hooks, filesystem mutation paths, external process launches, queues, or uninstall deletion behavior in Milestone 1. Settings fields represent planned controls only. Concurrency is fixed at one. Activation preserves an existing option.

## Planned processing boundary

Future processing should introduce an orchestration service and encoder interface, with independent adapters for MozJPEG-equivalent, pngquant/oxipng, libvips, GD, and Imagick. WordPress hooks should call the orchestrator, not encoders directly. Capability detection must remain advisory and failures must preserve the input. Storage, backup, job persistence, and delivery concerns should remain separate from encoder implementations.

## Deployment notes

The PHP process must have access to the target uploads filesystem and required libraries. Linux binary paths, executable permissions, PHP-FPM PATH, memory limits, and process restrictions can differ from CLI and from Windows. Verify diagnostics as the actual web/PHP-FPM user. Do not auto-install system packages or auto-configure cron.

## Integration testing

GitHub Actions runs production ZIP validation and installs that ZIP into temporary WordPress cores for integration tests against WordPress 6.6.10 and 7.1.3 with PHP 8.3 and MySQL 8.0. The test package is required from the isolated `WP_PLUGIN_DIR`; the Composer loader prioritizes that copy so tests cannot silently exercise the checkout source. Local tests use the same pinned installer script, a temporary core/test-library directory, and a disposable database.
