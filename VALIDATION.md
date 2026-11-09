# Milestone 1 Validation

Validation performed on 2026-11-09 in the Windows development workspace.

| Check | Result | Evidence / limitation |
|---|---|---|
| PHP syntax | Passed | All seven project PHP files passed `php -l` on PHP 8.5.0. PHP 8.3 is not installed locally. |
| WordPress Coding Standards | Passed | PHP_CodeSniffer 3.13.0 with WPCS 3.2.0 passed the production plugin sources via `composer lint` (5 files). The ruleset exempts WPCS filename rules because PSR-4 requires Composer class filenames matching their classes; all remaining selected WordPress rules apply. |
| Automated tests | Passed | `composer test`: 16 assertions for setting validation, capability result shape, activation/deactivation, admin hooks/menu capabilities, and sanitized settings persistence using WordPress API stubs. |
| Composer metadata | Passed | `composer validate --no-check-publish` reports valid. Runtime PHP dependency requirement is PHP >=8.3; no third-party production packages are required. |
| ZIP package | Passed | Built using production-only Composer dependencies. Checked ZIP root is `jocky-image-optimizer/`, plugin entry point and source are present, and Composer autoloader is included. Development packages, tests, and CI files are excluded. |
| WordPress installation/activation | Not verified | WordPress, WP-CLI, Docker, and Podman are unavailable locally. API-stub tests cover lifecycle and admin registration but do not replace a disposable WordPress environment. |
| Media preservation | Code-reviewed / stub-tested | Plugin has no upload hooks or media filesystem mutation code. Activation/deactivation tests confirm no media work is initiated. A real WordPress uploads-directory hash check was not possible without WordPress. |
| Git | Passed | Repository initialized with the requested `origin`; changes are committed locally. No push was performed. |

## Remaining validation before release

- Run integration tests on WordPress 6.6+ with PHP 8.3 and verify activation, settings save/reload, access control, and deactivation.
- Verify the package installs through the WordPress Plugins ZIP uploader in that environment.
- Hash a disposable uploads directory before/after activation and routine admin visits.
- Run PHPCS on Linux in CI; local PHPCS ran under Windows/PHP 8.5.
