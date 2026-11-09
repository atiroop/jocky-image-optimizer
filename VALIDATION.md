# Milestone 1.5 Validation

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
| WordPress integration suite | Implemented, not executed | `composer test:integration` targets the WordPress PHPUnit runtime. The local machine lacks WordPress test core/library and a disposable database. The suite tests the installed ZIP, not checkout source. |
| GitHub Actions | Follow-up required | Run `42551315621` failed before jobs because `${{ runner.temp }}` was invalid in job-level `env`. Moving it to step scope allowed jobs to start. Run `42551808229` exposed a Composer lock incompatible with PHP 8.3; dependency resolution now targets 8.3. Run `42551944275` exposed an eager menu include. Run `42552142217` then exposed a Composer loader collision and admin lifecycle setup issues. Run `42552797970` passed static/unit and installation but found mismatched test expectations around settings sanitizer invocation and page capability callback dispatch, plus a WordPress 6.6 frame header in CLI. The ZIP loader has a unique suffix; tests now invoke the registered sanitizer and protected callback and suppress both core headers during simulated admin requests. The latest changes await CI. |
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
