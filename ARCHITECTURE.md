# Architecture

## Current foundation

- `jocky-image-optimizer.php`: WordPress plugin header, guarded entry point, Composer autoload, lifecycle hooks, and deferred bootstrap.
- `src/Plugin.php`: lifecycle and admin-only composition root.
- `src/Admin/AdminPages.php`: capability-gated Dashboard and Settings pages, Settings API registration, and escaped diagnostics output.
- `src/Settings.php`: centralized defaults and deterministic input validation.
- `src/CapabilityDetector.php`: checks loaded PHP libraries and their format support, and searches PATH for known encoder executables without invoking them.
- `src/Optimization/OptimizationManager.php`: explicit-only orchestration for JPEG/PNG inputs, bounded validation, separate outputs, no-benefit handling, and measured results.
- `src/Optimization/*Encoder.php`: lossless `jpegtran`/`oxipng` and lossy MozJPEG/`pngquant` command adapters. Binary resolution accepts only known IDs and trusted configured paths.
- `src/Optimization/CwebpEncoder.php` and `AvifEncoder.php`: explicit JPEG/PNG-to-still-WebP/AVIF conversion using bounded argv execution, configurable quality/mode, and separate outputs. AVIF uses at most two threads and configurable speed.
- `src/System/ProcessRunner.php`: argv-array process execution with shell bypass, bounded output capture, timeout, and a restricted inherited environment. Windows uses private temporary capture files because process pipes can block despite nonblocking mode; Linux uses drained pipes.
- `src/Optimization/ImageValidator.php`: bounded streaming PNG chunk/order/CRC validation, JPEG marker/dimension validation, RIFF WebP chunk/dimension/alpha validation, bounded ISO-BMFF AVIF box/dimension checks, and animation detection.
- `scripts/package.php`: creates a WordPress-installable ZIP with a `jocky-image-optimizer/` root and includes Composer's production autoloader.
- `tests/integration/PluginIntegrationTest.php`: exercises the packaged plugin against the WordPress PHPUnit runtime, including lifecycle, admin pages, settings, diagnostics, and media fixture preservation.
- `bin/install-wp-tests.sh`: pinned WP-CLI scaffold installer for an isolated WordPress core and test library; it must use a disposable database and temporary paths.

Milestone 3 verification: GitHub Actions run [42735752617](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42735752617) passed on PHP 8.3, including actual cwebp/avifenc conversions, independent dwebp/avifdec decoding, and the packaged-plugin WordPress 6.6.11/7.1.4 matrix. The run caught a WebP RIFF-size offset defect that was fixed before the passing run. It confirms behavior in the CI runner only; production PHP-FPM paths, binaries, policies, and resource limits still require environment-specific checks. See `VALIDATION.md` for the actual measurements and full limitations.

The application has no third-party PHP runtime dependencies. Composer provides PSR-4 autoload metadata. Deployment/package creation runs Composer first and ships `vendor/` so WordPress does not need Composer.

## Safety boundaries

The explicit engine API writes only a separate destination beneath configured roots. Staging uses a private mode-0700 temporary directory; after validation, the engine promotes the file with same-filesystem hard-link creation that does not replace an existing path. Filesystems without hard links fail closed. This assumes the configured managed roots and their parent directories are trusted and not writable by hostile local users; PHP has no portable openat-style directory-handle API to eliminate all path replacement races. Activation preserves existing settings.

## Milestone 4 queue and media flow

`QueueSchema` installs versioned `{prefix}jio_jobs` and `{prefix}jio_worker_slots` InnoDB tables with `dbDelta`; activation and normal bootstrap both check for schema upgrades. Unique job keys prevent duplicate requests for the same attachment variant, output format, and source fingerprint. Claims and worker/scanner leases use transactions, row locks, and ownership tokens. Jobs have bounded retries with exponential backoff and stale lease recovery. Deactivation preserves tables, jobs, and derivatives.

`MediaIntegration` exposes a Media Library row action and optionally listens to `wp_generate_attachment_metadata` only to enqueue after core generates sizes; it returns the input metadata unchanged. `AttachmentTargetProvider` uses attachment APIs and current upload-root checks to accept local JPEG/PNG originals and registered sizes. It refuses paths changed by storage filters, symlinks, escapes, missing files, and unsupported media. `AttachmentProcessor` reuses the existing optimizer and writes separate deterministic `.jio.*` destinations. A hidden `_jio_derivative_<hash>` record stores relative paths, fingerprint, MIME, byte counts, and timestamp. Derivatives are validated and remain inactive for frontend delivery.

`BulkScanner` uses an attachment-ID cursor and bounded pages, with a dedicated slot preventing overlapping scans. The worker handles one job per cron tick, and WP-CLI accepts batches up to ten. WP-Cron depends on site traffic; operators can schedule `wp jio worker run --batch-size=10` externally. The plugin does not install cron or run a daemon. Direct uncached SQL is required for transactional queue claims; values are prepared and table identifiers come from the trusted `$wpdb->prefix`.

The resolver assumes local files under the active site's upload base directory. Filtered/offloaded storage is refused. Multisite uses the current site's `wp_upload_dir()` root but has not been tested in a multisite suite. Portable PHP cannot eliminate every filesystem TOCTOU race when parent directories are writable by hostile local users. Cancellation affects pending jobs only. If metadata persistence fails after a valid derivative is written, the retry will not overwrite that derivative; operator cleanup may be needed.

## Planned processing boundary

The current orchestration and adapters intentionally remain separate from WordPress media handling. PNG validation verifies every chunk CRC and basic ordering/critical-chunk rules, but actual IDAT decompression validation is delegated to the selected encoder. JPEG marker parsing does not itself decode entropy-coded scan data; encoders must fully decode input and output validation is supplemented by their exit status and structural checks. Process timeouts terminate the direct child process; portable PHP does not provide a cross-platform process-tree kill primitive, so the adapter set is restricted to known encoders that do not launch persistent child workers. Lossy adapters reject metadata-preserving requests; `jpegtran` and `oxipng` request metadata retention and compare detected metadata categories after encoding, not exact metadata bytes. Quality values are encoder-specific and not directly comparable. Storage, backup, job persistence, and delivery concerns remain separate from encoder implementations.

## Deployment notes

Cross-format conversion requires `cwebp` or `avifenc` on the PHP process PATH (not required for activation). AVIF defaults to speed 6 and at most two threads; lossy AVIF keeps alpha at lossless quality. The request accepts quality 1–100 and passes it to cwebp quality. The adapter detects libavif version and uses `--qcolor`/`--qalpha` on 1.2+, or maps to `--min`/`--max` quantizers and lossless alpha on earlier releases. Encoder quality scales are not directly comparable. Conversion rejects EXIF-bearing sources because orientation cannot be guaranteed. Other detected metadata requires opt-out and may be stripped. Output dimension/format/alpha checks are structural; runtime does not compare rendered pixels or exact color appearance. Independent decoding is part of the encoder integration test, not a runtime dependency. Animated formats are rejected. The PHP process must have access to the target uploads filesystem and required libraries. Linux binary paths, executable permissions, PHP-FPM PATH, memory limits, and process restrictions can differ from CLI and from Windows. Verify diagnostics as the actual web/PHP-FPM user. Do not auto-install system packages or auto-configure cron.

## Integration testing

GitHub Actions runs production ZIP validation and installs that ZIP into temporary WordPress cores for integration tests against WordPress 6.6.11 and 7.1.4 with PHP 8.3 and MySQL 8.0. The test package is required from the isolated `WP_PLUGIN_DIR`; the Composer loader prioritizes that copy so tests cannot silently exercise the checkout source. Local tests use the same pinned installer script, a temporary core/test-library directory, and a disposable database.
