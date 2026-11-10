# Changelog

## Unreleased — Milestone 5 recovery implementation (CI verified 2026-11-10)

- Added schema-v2 derivative ownership, persistent recovery audit, and resumable bounded cleanup-batch records while preserving the existing queue tables and atomic queue operations.
- Added bounded streaming source/derivative SHA-256 verification, safe registry-only cleanup with path/symlink/inode checks, operation locks, explicit confirmation, cleanup preview, and legacy JIO metadata discovery.
- Added paginated Recovery and Optimization History admin pages, Media Library recovery links, and `wp jio recovery` status, verify, preview, cleanup, scan, and bulk controls.
- Added integration coverage for derivative registry creation, corruption detection, queue/lock conflict handling, cleanup audit, and preservation of originals, thumbnails, and attachment metadata. All five jobs passed in [GitHub Actions run 42775341373](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42775341373) on commit `5e7497af9f7be78c2f693cbb9f4cfe5a0e90e871`.
- Recovery preview rechecks conflicted and interrupted-removal records so detected corruption remains visible and repaired files become eligible only after fresh validation. Queue savings aggregation now uses signed arithmetic when an output is larger than its input.
- Known limits: 50 MiB per-file and 100 MiB per-operation hash bounds; trust in non-hostile local uploads-directory writers due to portable PHP unlink TOCTOU limits; MySQL advisory locks are connection scoped; original restoration requires an external backup.

## 0.4.0-beta — First installable beta

- Set the plugin header and runtime version to `0.4.0-beta` and generate a correspondingly versioned production ZIP.
- Add strict ZIP integrity checks for a single `jocky-image-optimizer/` root, matching plugin version, required runtime autoloader, and absence of development tools and repository/test files.
- Extend PHP 8.3 CI to install and activate the production ZIP against disposable WordPress 6.6.11 and 7.1.4 databases, including schema/worker-slot/default-setting checks and real PNG/JPEG-to-WebP attachment processing.
- Publish the tested installable ZIP as the `jocky-image-optimizer-0.4.0-beta` GitHub Actions artifact after all validation jobs pass.
- Document Dashboard installation, encoder prerequisites, and beta limitations.

## Milestone 3 — WebP and AVIF conversion (implemented; CI verified 2026-11-10)

- Added explicit cwebp and avifenc adapters for JPEG/PNG input, lossy/lossless modes, encoder-specific quality, bounded AVIF speed/thread settings, and optional retention of larger valid derivatives.
- Added WebP RIFF and AVIF ISO-BMFF structural validation, dimension checks, transparency detection, animation rejection, independent decoder integration coverage, and fixed-fixture benchmark reporting.
- Conversion requires explicit metadata opt-out; detected metadata is rejected when preservation is requested because color-profile and orientation fidelity are not guaranteed. No WordPress media integration was added.
- Fixed WebP RIFF parsing to read the size field after the `RIFF` signature; real encoder CI caught and verified the correction.
- GitHub Actions run 42735752617 passed static/unit, both WordPress package integration jobs, and real encoder integration on PHP 8.3. Measured fixture outputs are recorded in `VALIDATION.md`.
- Local real-encoder and WordPress integration checks remain unavailable on the Windows workspace; no production files or user media were accessed.

## Milestone 2 — 2026-11-10

- Added an explicit local JPEG/PNG optimization API with lossless jpegtran/oxipng and lossy MozJPEG/pngquant adapters.
- Added path confinement, bounded inputs and dimensions, time-limited argv-based processes, temporary-file cleanup, post-encode validation, and no-clobber output promotion.
- Rejects APNG and lossy metadata-preserving requests; no WordPress media hooks or attachment mutations were added.
- Added PNG CRC/order/corruption tests, private temporary staging, and reproducible real-encoder integration coverage in CI. GitHub CI has not yet run for these changes.

## Milestone 2 remediation — 2026-11-10

- Reduced WPCS from 215 errors and 34 warnings to 0 errors and 0 warnings; added justified scoped exceptions for native streaming I/O, atomic no-clobber promotion, and argv-based process launching.
- Added an Ubuntu 24.04/PHP 8.3 encoder job using pinned MozJPEG source, Ubuntu pngquant, and locked oxipng, plus real JPEG/PNG, alpha, failure, timeout, cleanup, and source-preservation tests.

## Workflow repair — 2026-11-09

- Fixed the WordPress integration workflow's pre-job validation error by moving `runner.temp` expressions from job-level to step-level environment configuration.
- Updated the integration matrix to verified WordPress 6.6.11 and 7.1.4 releases and matching test-suite tags.
- Set Composer's dependency-resolution platform to PHP 8.3 so locked development tools remain installable on the CI runtime.
- Removed an eager admin-menu include from the PHPUnit bootstrap; integration tests now initialize admin menus through WordPress hooks.
- Isolated the packaged Composer autoloader's generated global class suffix from the PHPUnit development autoloader and corrected WordPress test hook reset, sanitizer, permission, and output buffering assumptions.

## 0.1.0-dev — 2026-11-09

- Established WordPress plugin bootstrap and Composer PSR-4 structure.
- Added activation defaults, safe deactivation, settings validation, and basic admin pages.
- Added runtime encoder/library diagnostics, standalone tests, CI, documentation, and development ZIP packaging.
- Image processing is not implemented in this release.

## Milestone 1 validation — 2026-11-09

- Added WPCS development tooling, a PSR-4-aware ruleset, and CI linting.
- Corrected production source to pass WordPress Coding Standards and expanded stub-based lifecycle/admin/settings checks.
- Tightened Git exclusions and made ZIP generation require production-only Composer dependencies.
- Recorded unavailable WordPress/PHP 8.3 integration checks in `VALIDATION.md`.

## Milestone 1.5 — 2026-11-09

- Added real WordPress PHPUnit integration tests that install and exercise the production ZIP.
- Added isolated WordPress/MySQL CI matrix for PHP 8.3 and WordPress 6.6.10/7.1.3.
- Pinned the test-suite installer and GitHub Actions; added ZIP validation and local test instructions.
- Updated vulnerable dev dependencies after `composer audit` identified advisories in prior PHPCS/WPCS/PHPUnit versions.
# Milestone 4 — WordPress media queue (implemented; CI pending)

- Added versioned prefixed InnoDB queue/lease tables, atomic claims, job deduplication, bounded retry/backoff, stale recovery, and ownership checks.
- Added local attachment/thumbnail discovery, individual Media Library queue action, optional off-by-default upload enqueueing, paginated bulk scan, queue controls, persisted progress, and bounded WP-CLI worker.
- Processing creates separate validated `.jio.webp`/`.jio.avif` files with hidden structured attachment metadata. Originals and core attachment metadata are preserved; derivatives are inactive for frontend delivery.
- Added packaged-plugin integration cases for image processing, source preservation, queue lifecycle, scanner pagination, and authorization. PHP 8.3 CI results are pending until the workflow runs.
- Replaced nested explicit transactions with single-statement atomic queue/lease claims to preserve WordPress PHPUnit test isolation; corrected same-second worker lease renewal and added idempotent missing-slot recovery.
- Added minimal `*.php text eol=lf` policy for consistent Windows and CI lint behavior.
