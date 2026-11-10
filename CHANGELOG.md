# Changelog

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
