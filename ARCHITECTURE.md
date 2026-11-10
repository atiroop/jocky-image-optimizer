# Architecture

## Current foundation

- `jocky-image-optimizer.php`: WordPress plugin header, guarded entry point, Composer autoload, lifecycle hooks, and deferred bootstrap.
- `src/Plugin.php`: lifecycle and admin-only composition root.
- `src/Admin/AdminPages.php`: capability-gated Dashboard and Settings pages, Settings API registration, and escaped diagnostics output.
- `src/Settings.php`: centralized defaults and deterministic input validation.
- `src/CapabilityDetector.php`: checks loaded PHP libraries and their format support, and searches PATH for known encoder executables without invoking them.
- `src/Optimization/OptimizationManager.php`: explicit-only orchestration for JPEG/PNG inputs, bounded validation, separate outputs, no-benefit handling, and measured results.
- `src/Optimization/*Encoder.php`: lossless `jpegtran`/`oxipng` and lossy MozJPEG/`pngquant` command adapters. Binary resolution accepts only known IDs and trusted configured paths.
- `src/System/ProcessRunner.php`: argv-array process execution with shell bypass, bounded output capture, timeout, and a restricted inherited environment. Windows uses private temporary capture files because process pipes can block despite nonblocking mode; Linux uses drained pipes.
- `src/Optimization/ImageValidator.php`: bounded streaming PNG chunk/order/CRC validation, JPEG marker/dimension validation, and APNG detection.
- `scripts/package.php`: creates a WordPress-installable ZIP with a `jocky-image-optimizer/` root and includes Composer's production autoloader.
- `tests/integration/PluginIntegrationTest.php`: exercises the packaged plugin against the WordPress PHPUnit runtime, including lifecycle, admin pages, settings, diagnostics, and media fixture preservation.
- `bin/install-wp-tests.sh`: pinned WP-CLI scaffold installer for an isolated WordPress core and test library; it must use a disposable database and temporary paths.

The application has no third-party PHP runtime dependencies. Composer provides PSR-4 autoload metadata. Deployment/package creation runs Composer first and ships `vendor/` so WordPress does not need Composer.

## Safety boundaries

There are no upload hooks, attachment replacement, thumbnail mutation, queues, or uninstall deletion behavior. The explicit API writes only a separate destination beneath configured roots. Staging uses a private mode-0700 temporary directory; after validation, the engine promotes the file with same-filesystem hard-link creation that does not replace an existing path. Filesystems without hard links fail closed. This assumes the configured managed roots and their parent directories are trusted and not writable by hostile local users; PHP has no portable openat-style directory-handle API to eliminate all path replacement races. Concurrency is one operation per caller. Activation preserves an existing option.

## Planned processing boundary

The current orchestration and adapters intentionally remain separate from WordPress media handling. PNG validation verifies every chunk CRC and basic ordering/critical-chunk rules, but actual IDAT decompression validation is delegated to the selected encoder. JPEG marker parsing does not itself decode entropy-coded scan data; encoders must fully decode input and output validation is supplemented by their exit status and structural checks. Process timeouts terminate the direct child process; portable PHP does not provide a cross-platform process-tree kill primitive, so the adapter set is restricted to known encoders that do not launch persistent child workers. Lossy adapters reject metadata-preserving requests; `jpegtran` and `oxipng` request metadata retention and compare detected metadata categories after encoding, not exact metadata bytes. Quality values are encoder-specific and not directly comparable. Storage, backup, job persistence, and delivery concerns remain separate from encoder implementations.

## Deployment notes

The PHP process must have access to the target uploads filesystem and required libraries. Linux binary paths, executable permissions, PHP-FPM PATH, memory limits, and process restrictions can differ from CLI and from Windows. Verify diagnostics as the actual web/PHP-FPM user. Do not auto-install system packages or auto-configure cron.

## Integration testing

GitHub Actions runs production ZIP validation and installs that ZIP into temporary WordPress cores for integration tests against WordPress 6.6.11 and 7.1.4 with PHP 8.3 and MySQL 8.0. The test package is required from the isolated `WP_PLUGIN_DIR`; the Composer loader prioritizes that copy so tests cannot silently exercise the checkout source. Local tests use the same pinned installer script, a temporary core/test-library directory, and a disposable database.
