# Changelog

## Workflow repair — 2026-11-09

- Fixed the WordPress integration workflow's pre-job validation error by moving `runner.temp` expressions from job-level to step-level environment configuration.
- Updated the integration matrix to verified WordPress 6.6.11 and 7.1.4 releases and matching test-suite tags.
- Set Composer's dependency-resolution platform to PHP 8.3 so locked development tools remain installable on the CI runtime.

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
