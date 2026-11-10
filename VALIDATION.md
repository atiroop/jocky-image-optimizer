# Milestone 1.5 Validation

## Milestone 3 implementation status — 2026-11-10

Milestone 3 work is on `feature/milestone-3-webp-avif`. Results in this section distinguish checks already run locally from the real-encoder and WordPress CI jobs that must run after the feature branch is pushed.

| Check | Result | Evidence / limitation |
|---|---|---|
| Foundation and branch | Passed | Started from clean local `main` and `origin/main` at Milestone 2 merge `bb27295515bac0b90f8f598ba27aac9214987a73`; created the requested feature branch. |
| PHP runtime | Partial | Local PHP 8.5.0; PHP 8.3 is configured in CI and unavailable locally. |
| WPCS | Passed locally after line-ending normalization | `composer lint --no-interaction` passed for the production source after temporarily normalizing PHP working files to LF for PHPCS. The checkout's Windows `core.autocrlf=true` mode presents CRLF and causes line-ending-only PHPCS failures on untouched files. Changed production files also pass direct PHPCS checks. Linux CI remains the canonical check. |
| Standalone tests | Passed locally | `composer test --no-interaction` passed all 30 existing assertions. |
| Composer validation/audit | Passed locally | `composer validate --no-check-publish` is valid and `composer audit --locked` reports no advisories. |
| Workflow YAML | Passed locally | `actionlint .github/workflows/ci.yml` passed. |
| PHP syntax | Passed locally | PHP 8.5.0 `php -l` passed for 33 PHP files, excluding `vendor/` and generated `build/` output. PHP 8.3 remains a CI check. |
| Real WebP/AVIF conversion | Not run locally | `cwebp`, `avifenc`, `dwebp`, and `avifdec` are absent from this Windows workspace. No encoder packages were installed locally. CI installs trusted Ubuntu packages and runs actual conversions plus independent decoder checks. |
| Production ZIP | Passed locally | After `composer install --no-dev`, `php scripts/package.php` and `composer test:package` passed; development dependencies were restored afterward. |
| WordPress integration | Pending | Must be verified by the feature branch workflow; no local Docker/WordPress database is available. |
| Benchmark | Pending | The real encoder integration script emits measured input/output bytes and elapsed milliseconds for each fixture conversion. No measurements are available until the CI encoder job runs. |

Known Milestone 3 limits: conversion is explicit only and writes a separate derivative. EXIF-bearing sources are always rejected because orientation cannot be guaranteed. Other metadata-bearing sources are rejected while `preserve_metadata` is true; callers can explicitly opt out, and those profiles/metadata may be stripped. Runtime AVIF checks validate bounded ISO-BMFF boxes and primary dimensions, and WebP checks the RIFF/chunk structure and dimensions; independent decoder acceptance is exercised in CI. These checks do not prove visual equivalence. Animated formats are unsupported. CI pins Ubuntu 24.04 WebP 1.3.2 and libavif 1.0.4 package versions to exercise the older AVIF quantizer CLI; package versions are trusted Ubuntu packages, though exact versions may disappear from the active repository in the future.

## Milestone 2 implementation check — 2026-11-10

Milestone 2 processing code has been added on `feature/milestone-2-image-engine`; this is an implementation status report, not a production-readiness claim.

| Check | Result | Evidence / limitation |
|---|---|---|
| PHP runtime | Not fully verified | Local PHP is 8.5.0; PHP 8.3 is unavailable in this workspace. |
| PHP syntax | Passed locally | `composer test` loaded the new API and exercised it on PHP 8.5.0. No PHP 8.3 execution was performed. |
| WPCS | Passed locally after remediation | Before: 215 errors and 34 warnings. After: `composer lint --no-interaction` reports 0 errors and 0 warnings. Scoped, explained WPCS exceptions cover native streaming filesystem calls, hard-link promotion, and `proc_open` argv execution because WordPress filesystem APIs do not provide these bounded/atomic local-file semantics. |
| Standalone tests | Passed locally | `composer test --no-interaction`: 30 assertions passed, including valid PNG CRCs, invalid CRC, truncated/invalid structure, APNG detection, unsupported/empty data, outside-root rejection, no-benefit handling, bounded stdout/stderr capture, argv exit-code capture, and enforced process timeout. The copy adapter test does not claim compression or savings. |
| Composer validation/audit | Passed locally | `composer validate --no-check-publish` is valid; `composer audit --locked` reports no advisories. |
| Real encoder processing | Not available locally; CI configured | `composer test:encoders` was attempted and stopped at the explicit preflight because `cjpeg` is unavailable; `djpeg`, `jpegtran`, `pngquant`, and `oxipng` are also absent. No system packages were installed. The new Ubuntu/PHP 8.3 encoder job builds MozJPEG from upstream commit `38f37cf900958c1004c2eea945a773764ba20716`, installs Ubuntu's `pngquant`, and installs oxipng `9.1.2` with Cargo `--locked`. It has not yet run on GitHub, so real optimization is not claimed as passed. |
| WordPress integration | Not run for this milestone | PHP 8.3, WordPress test core, and disposable database are unavailable locally. The Milestone 1.5 CI run documents the prior foundation only, not the new engine. |
| Installable ZIP | Passed locally | After remediation, `composer install --no-dev --no-interaction --optimize-autoloader`, `php scripts/package.php`, and `composer test:package` passed; development dependencies were restored afterward. |
| WordPress media safety | Scope review only | No upload/attachment hooks were added. Tests verify separate-output behavior with a copy adapter; no production media was accessed. |

Remaining engineering limitations: IDAT decompression and JPEG entropy stream validation are performed by the actual encoder rather than the PHP structure parser; metadata validation checks detected categories, not byte-for-byte metadata equality. The engine assumes managed root directories are trusted against hostile local users because portable PHP cannot remove every path TOCTOU race. Process timeouts terminate the direct child but cannot portably guarantee termination of descendants. Actual encoder behavior, PHP 8.3 execution, the new GitHub workflow, and this revision's WordPress package integration still need verification. No GitHub workflow was run for this milestone.

Status: **partially verified**. Validation performed on 2026-11-09 in the Windows development workspace. No production site, VPS, or user media was accessed.

| Check | Result | Evidence / limitation |
|---|---|---|
| Repository safety | Passed | Started clean on `main` at `49dd4ba10674fdc8add3caf89be6c576f5dd4015`; created `feature/milestone-1-5-integration`. No remote changes made. |
| Local environment | Observed | PHP 8.5.0 and Composer 2.8.12 available. PHP 8.3, WordPress, WP-CLI, Docker, Podman, and a local MySQL/MariaDB service are unavailable. |
| PHP syntax | Passed locally | All 10 project PHP files passed `php -l` on PHP 8.5.0. PHP 8.3 execution is configured in CI but has not run yet. |
| WordPress Coding Standards | Passed locally | PHP_CodeSniffer 3.13.6/WPCS 3.4.1: `composer lint` passed for the five production plugin files. PSR-4 class filename checks remain the documented narrow exception. |
| Unit tests | Passed locally | `composer test`: all 16 assertions passed. These are standalone settings, diagnostics, lifecycle, and mocked admin API checks, not WordPress integration tests. |
| Composer dependencies | Passed locally | `composer validate --no-check-publish` reports valid; `composer audit --locked` reports no security advisories. Dependency resolution targets PHP 8.3; this prevents selecting transitive dev packages that require a newer PHP than CI. |
| Production ZIP | Passed locally | Generated a 24-entry ZIP after `composer install --no-dev`; `composer test:package` passed and archive inspection found required entries, no dev dependencies, and the expected plugin root. |
| Test installer syntax | Passed locally | `bash -n bin/install-wp-tests.sh` passed using Git for Windows Bash. |
| WordPress integration suite | Passed in GitHub Actions | Run `42553509639`: WordPress 6.6.11 and 7.1.4, each with PHPUnit 9.6.38, passed all 6 tests and 72 assertions against the installed production ZIP. Local WordPress/database dependencies remain unavailable. |
| GitHub Actions | Passed | [Run 42553509639](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42553509639) completed successfully: static/unit and both WordPress integration jobs passed on PHP 8.3.36 and MySQL 8.0.46. Earlier pre-job validation, dependency compatibility, and test harness failures were corrected before this run. |
| Media preservation | Integration assertion implemented, not executed | Tests create a disposable PNG under the test WordPress uploads directory and compare its SHA-256 before/after lifecycle/admin operations. No user media is used. |

## Integration coverage implemented

- Real WordPress activation, repeated activation, deactivation, and stored option preservation.
- Admin menu/settings registration, `manage_options` enforcement, settings form nonce generation/verification, and sanitized option persistence.
- Rejection of script input and escaping of an injected executable path in diagnostics.
- Encoder discovery without executing the fake binary and graceful handling when PATH has no encoder.
- ZIP structure, Composer runtime autoloader, no-dev metadata, and activation from the extracted package directory.
- Upload fixture hash preservation across lifecycle and administration.

## Environment and CI procedure

`bin/install-wp-tests.sh` is based on WP-CLI scaffold-command commit `217de3cf5a5930f0aa64e5cb30915682a14ba07c`; recursive removal of existing core/test paths was replaced with refusal checks. Local procedure and temporary directory/database requirements are documented in `README.md`. The GitHub job creates a disposable MySQL service and temp WordPress core for each version in its matrix.

## Remaining verification before Milestone 1.5 is complete

- Run the branch's GitHub Actions workflow and confirm green results for both WordPress versions on PHP 8.3.
- Resolve any integration failures revealed by that run.
- Record the actual WordPress/PHP versions and test summaries here after execution; workflow configuration alone is not a pass.
- A direct HTTP POST to `wp-admin/options.php` as an unauthorized user is not exercised; assertions cover protected page callbacks, Settings API sanitization, and core nonce generation/verification.
