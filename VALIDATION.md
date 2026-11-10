# Validation

## Milestone 5 — final acceptance audit (CI verified; merge blocked)

The feature implementation is on `feature/milestone-5-backup-restore`, created from the clean verified beta branch at `40309db5cd8d84cc1820f232a74b492fbfaaa063`. The supplied Milestone 5 handoff ends mid-section 13 (“Recovery operations must not race with:”); work follows the complete requirements provided before that cutoff. No production/VPS access or media modification was performed.

The prior implementation passed GitHub Actions [run 42776251310](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42776251310) on commit `c9ade98b3f6a701784fe99dc0585d239bb18e92d`. The acceptance audit fixed the active-job status mismatch, normalized legacy scanner progress, distinguished missing and changed sources, and added regression coverage. Feature head `79a112b792f6cd2eb26cc1af70ca7aa9616a3676` passed all five jobs in [run 42780293142](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42780293142). Merge readiness is blocked by the remaining acceptance gaps below, not by CI.

| Check | Result | Evidence / limitation |
|---|---|---|
| WPCS | Passed locally | `composer lint`: 0 errors and 0 warnings after remediation. |
| Standalone tests | Passed locally | `composer test` passed all existing 30 checks. |
| Composer validation and audit | Passed locally | `composer validate --no-check-publish` valid; `composer audit --locked` found no advisories. |
| PHP syntax | Passed locally | `php -l` passed for 43 PHP files on PHP 8.5.0. PHP 8.3 is not installed locally. |
| Workflow YAML/schema | Passed locally | `actionlint .github/workflows/ci.yml` passed. |
| Production ZIP | Passed locally | Followed `composer install --no-dev`, built `build/jocky-image-optimizer-0.4.0-beta.zip`, and `composer test:package` confirmed its structure and production dependencies; development dependencies were restored afterward. |
| Real encoder integration | Passed in CI | `encoder-integration` installed trusted, pinned encoders and passed real fixtures. Local `composer test:encoders` stopped at the `cjpeg` preflight because encoder binaries are not installed locally. |
| WordPress integration | Passed in CI | Both PHP 8.3 disposable production-ZIP integration jobs passed on WordPress 6.6.11 and 7.1.4, including audit regressions. This Windows workspace has no local WP-CLI, Docker/Podman, disposable core, or database runtime. |
| Recovery WP-CLI | Passed smoke in CI | Checksum-verified WP-CLI 2.12.0 activated the packaged plugin and invoked `wp jio recovery status`, `bulk --action=preview`, and bounded `scan --batch-size=10` in both matrix jobs. Destructive and attachment-specific CLI paths were not invoked. |
| Original/media safety | Passed in CI | Integration coverage checked source bytes, core attachment metadata, thumbnails, source and derivative corruption/missing cases, pending/running queue conflicts, lock conflicts, migration preservation, and audit. No production media was accessed. |
| Artifact | Passed and downloaded | Artifact `jocky-image-optimizer-0.4.0-beta` (93,997 bytes outer artifact, ID `13745544255`) was downloaded. [Download artifact](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42780293142/artifacts/13745544255). Its inner plugin ZIP is 100,633 bytes, passes `php scripts/validate-package.php`, and has SHA-256 `30417c8bb53ecdb7cd7c300c35776b30fa52cfe50c55e2cf45d82f4d0cd4f6b6`. |
| Filesystem race boundary | Limitation documented | Uses canonical uploads confinement, symlink rejection, streamed digests, device/inode/size/mtime identity recheck, and shared MySQL advisory locks. PHP cannot eliminate the final unlink TOCTOU window against a hostile local writer; uploads directories must be writable only by trusted site processes. |

Implemented behavior includes schema-v2 derivative registry/audit/batch tables, bounded verification and legacy indexing, per-attachment cleanup preview/actions, paginated bulk candidate preview and persistent cleanup worker controls, history, and WP-CLI recovery commands. The digest manifest is not an independent backup: missing/corrupt originals require UpdraftPlus or another verified external backup. Hashing is limited to 50 MiB per file and 100 MiB per operation; ambiguous or over-limit files fail closed. The handoff truncation leaves unspecified race scenarios in section 13 unassessed; supported locking and remaining filesystem race limits are stated above.

### Requirements acceptance matrix

| Requirement | Implemented | Tested | Evidence | Remaining gap |
|---|---|---|---|---|
| 14. Frontend delivery readiness architecture | Yes, documentation only; delivery stays off | Document review | `ARCHITECTURE.md` describes registered derivative mapping, responsive `srcset`, `<picture>` browser fallback, Elementor, WP Rocket cache invalidation, and separate Cloudflare/R2 design | No frontend URL or markup integration is activated or tested; that is explicitly future work |
| 15. Duplicate `WP_DEBUG` warning and bulk scan progress | Progress normalization fixed; duplicate warning source not established | Progress regression passed CI; warning investigation read-only | `BulkScanner::state()` supplies all fields consumed by `assets/admin.js` and validates counters/status. No `WP_DEBUG` definition exists in tracked plugin/test sources or captured logs for the successful run | Warning not reproduced; cannot identify external `wp-config.php`/include cause without sanitized warning and relevant context. Production config was not accessed or changed |
| 16. Recovery integrity, source preservation, missing/corrupt files, cleanup, authorization, DB failures, concurrency, WP-CLI | Core paths implemented; test coverage partial | Recovery/migration/authorization tests and WP-CLI status, preview, scan smoke passed CI | `PluginIntegrationTest.php`; `RecoveryService`; admin handlers; `RecoveryCommand`; `bin/test-wp-cli.sh`; both WordPress matrix jobs | No injected DB failure test; no separate-connection advisory-lock race test; destructive and attachment-specific CLI paths are not smoke-tested |
| 17. CI preservation | Existing matrix preserved and WP-CLI smoke added | All five jobs passed on audit head | Run 42780293142 passed static/unit, WP 6.6.11, WP 7.1.4, real encoders, and package artifact on PHP 8.3 | CI does not verify production server-specific binaries/configuration |
| 18. Performance bounds | Yes | Prior CI exercises pagination/batch cleanup; audit review | Hashing streams 1 MiB blocks, max 50 MiB/file and 100 MiB/call; UI/keyset pages are bounded; bulk cleanup max ten records/tick; no whole-library hashing in frontend; WP-CLI handles batches | Large files require manual handling; actual production-scale timing/memory benchmarking is not done |
| 19. Production safety | Yes; no production operations performed in this audit | Activity review | No SSH, live files/database, media, server config, or site modifications | None for this audit; staging/deployment still requires separate authorization |
| 20. Documentation accuracy | Updated | Review | README and ARCHITECTURE explain original delivery vs byte restore, derivative ownership, migration, cleanup, external backups, and inactive delivery design | Complete section 13 text is still required to assess omitted race conditions |
| 21. Automated Git workflow | Existing branch scope followed | Git review | Authorized fixes are limited to `feature/milestone-5-backup-restore`; no merge is authorized | None; main merge remains explicitly unauthorized |
| 22. Original Milestone 5 definition of done | Partial | Per below | Sections 3–12 are assessed below; section 13 was truncated in the source handoff | Missing remainder of section 13 cannot be accepted without the complete text |
| 23. Final report | Complete | This audit | Findings and limits are recorded here and summarized in the response | See merge recommendation below |

### Original handoff acceptance criteria (sections 3–13)

| Section | Acceptance result | Evidence / gap |
|---|---|---|
| 3. Scope and frontend readiness | Partial | Recovery/ownership/history/admin/CLI functionality exists; frontend delivery remains intentionally inactive and its future design is documented. |
| 4. Backup architecture and original integrity manifest | Implemented | Bounded SHA-256 and WordPress references are recorded; no duplicate original backups; external backup restore is required for missing/corrupt bytes. |
| 5. Derivative ownership registry | Implemented | Dedicated v2 tables hold relative paths/digests/encoder/lifecycle; arbitrary filename-based adoption is rejected; legacy import requires current source relation and exact expected derivative path. |
| 6. Restore semantics | Implemented | Original delivery state is verified, not reconstructed; derivative cleanup is separate; missing/corrupt originals are referred to external backup. |
| 7. Per-attachment recovery | Implemented; partially tested | Capability rejection and service cleanup regressions passed CI; nonce, attachment validation, preview, integrity, lock, explicit confirmation and audit are in code. A bad-nonce handler test is absent. |
| 8. Safe deletion | Implemented with documented residual risk | Confined exact derivative path, symlink rejection, SHA-256/identity recheck, lifecycle transition and idempotent status; no quarantine. Portable PHP unlink TOCTOU against hostile local writers cannot be fully eliminated. |
| 9. Bulk recovery | Implemented | Dry-run summary, explicit start confirmation, persistent bounded cursor, pause/resume/cancel/retry, per-attachment locking and audit. CI integration coverage exercises bounded cleanup. |
| 10. Optimization history | Implemented; semantic coverage partial | Persisted job/derivative/history state shown; separate derivatives do not necessarily reduce disk usage. History pagination and all lifecycle labels do not have dedicated assertion coverage. |
| 11. Admin UI | Implemented; partially tested | Escaped/capability/nonce-protected pages and row link exist. Recovery capability rejection regression passed; no browser-level responsive UI or focused bad-nonce handler test. |
| 12. WP-CLI recovery commands | Implemented; smoke passed | Smoke invokes status, bulk preview, and bounded scan against the packaged plugin. Destructive cleanup and attachment-specific verification are service-tested, not CLI-invoked. |
| 13. Queue coordination | Implemented; configured tests passed | Shared per-attachment MySQL lock and actual `pending`/`running` queue checks passed tests. Same-connection lock conflicts are tested; cross-connection race coverage is absent. The handoff ends after “Recovery operations must not race with:” so omitted cases remain unknowable. |

### Warning investigation and migration/rollback notes

The reported duplicate `WP_DEBUG` warning is not present in checked-out plugin sources, test bootstrap, workflow setup, or captured logs for runs 42776251310 and 42780293142. The plugin does not define `WP_DEBUG`. Its root cause remains unconfirmed and is likely outside the plugin, but that attribution is inference only. No production `wp-config.php` or live server was inspected. To resolve it, provide the exact warning and two file/line contexts with secrets redacted.

The schema migration uses additive/idempotent `dbDelta` table creation and leaves existing queue tables, rows, settings, and legacy derivative postmeta in place. The audit adds a migration preservation regression. Rollback to an older plugin build leaves the new plugin tables and audit data orphaned but does not intentionally delete them; do not downgrade schema options manually. A completed derivative cleanup cannot be rolled back from plugin state because the derivative bytes are deleted; originals remain, but restore any needed derivative by rerunning optimization. Back up the database and uploads before any staging/production install.

## Milestone 4.5 beta release — CI verified

Target release: `0.4.0-beta`. The dedicated branch is `feature/milestone-4-5-beta-release`, based on verified main commit `092d5cfc26e6f83632c8a955ff46456fe14cc064`.

The first beta release validation completed successfully in [GitHub Actions run 42752210121](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42752210121), commit `8610c2e25f8a4e6a3bf9a98944002858f6a9ee6e`.

| Check | Result | Evidence / limitation |
|---|---|---|
| Plugin version | Passed | Header and `JIO_VERSION` are both `0.4.0-beta`; the package builder derives its archive filename from the matching values. |
| WPCS, PHP syntax, standalone tests | Passed | `static-and-unit` passed WPCS, PHP syntax, 30 standalone checks, Composer validation, and `composer audit --locked`. |
| Real encoder integration | Passed | `encoder-integration` built pinned MozJPEG, installed pngquant/oxipng, and passed the real fixture suite. |
| PHP 8.3 / WordPress 6.6.11 and 7.1.4 ZIP install | Passed | Both disposable WordPress/MySQL matrix jobs built and verified the production ZIP, installed it, activated it, and passed the WordPress integration suite, including database schema and worker-slot checks. |
| JPEG/PNG derivative behavior | Passed | WP jobs used GD to create a JPEG fixture and `cwebp` to create derivatives. Tests covered JPEG/PNG queue processing, bounded batches/progress, default-off automatic optimization, missing encoder diagnostics, source hashes, and unchanged core attachment paths/metadata. |
| Artifact publication | Passed | The gated `beta-package-artifact` job uploaded non-empty artifact `jocky-image-optimizer-0.4.0-beta` (69,999 bytes, Actions artifact ID `13734097455`). [Download artifact](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42752210121/artifacts/13734097455). |
| Downloaded production ZIP | Passed | Downloaded from the artifact and revalidated with `php scripts/validate-package.php`/ZipArchive `CHECKCONS`: one top-level root `jocky-image-optimizer/`, 52 entries, inner ZIP size 76,103 bytes, SHA-256 `237c7f9232bc9719f64b3f236141c378740350fbd8822ef3b30e5d21ea8c613c`. |
| Local PHP runtime | Partial | This Windows workspace has PHP 8.5.0, not PHP 8.3. CI is the authoritative PHP 8.3 and disposable WordPress/MySQL validation. |

## Milestone 4 implementation — CI verified

The feature branch contains WordPress Media Library queue integration. Its final implementation checks passed in the run recorded below.

| Check | Result | Evidence / limitation |
|---|---|---|
| Scope and branch | Implemented | Started from the clean Milestone 3 `main` commit `94994f208a32660434bdff45c1f1371acf747772` and created `feature/milestone-4-wordpress-media`. No production/VPS access. |
| WPCS | Passed locally | `composer lint` passed after applying the requested `*.php text eol=lf` policy. PHP files were normalized in the worktree; the `.gitattributes` rule prevents broad content diffs. |
| Standalone tests | Passed locally | `composer test`: all 30 assertions passed. This does not replace WordPress database integration. |
| Composer | Passed locally | `composer validate --no-check-publish` and `composer audit --locked` passed. |
| WordPress integration | Not run locally | This Windows environment has no PHP 8.3, disposable WordPress/MySQL test runtime, or Docker. The CI matrix is configured for PHP 8.3 and WordPress 6.6.11/7.1.4 and will exercise the generated production ZIP. |
| Real media encoder in Media Library | Not run locally | Linux `cwebp` is unavailable here. CI's disposable WordPress jobs install Ubuntu's `webp` package to run actual PNG-to-WebP attachment/thumbnail processing. Milestone 3's real encoder CI remains prior evidence for the engine, not this integration. |
| Package / ZIP | Passed locally | `composer test:package` passed for the existing production archive; CI rebuilds it from the pushed source before integration. |
| Latest GitHub Actions | Passed | [Run 42748365437](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42748365437), commit `0af6446ccde0fa5e089ac138f21b959e4995d810`: all four jobs passed, including real encoder integration, production ZIP builds, and WordPress 6.6.11 / 7.1.4 integration on PHP 8.3. |

### Confirmed queue investigation and current repair status

Inspection of WordPress's PHPUnit test base confirmed it starts a database transaction for each test (`SET autocommit=0; START TRANSACTION`) and rolls it back in teardown. The previous repository issued nested `START TRANSACTION` and `COMMIT` calls for claims and leases, which could commit the test's outer transaction and invalidate fixture isolation. Independently, `renew_slot()` treated the changed-row count as proof of ownership; MySQL reports zero changed rows when the lease timestamp update falls within the same UTC second, causing the worker to stop before claiming any job. The previous run's fixture diagnostic showed the real attachment provider returned targets, so attachment discovery itself was not the common cause.

The focused repair replaces explicit transaction blocks with atomic conditional InnoDB updates for slot claims, scanner leases, and job claims; renewal now checks the ownership token even when no timestamp value changed. Schema checks reseed missing slot rows even when the stored version is current, and PHPUnit schema installation is moved to class setup rather than repeated per-test DDL. Run 42747927921 confirmed that the old PNG fixture failed the production validator's chunk CRC check; a generated valid-CRC PNG allowed worker processing to complete. It also confirmed the upload hook was absent because a static test guard fired `plugins_loaded` only once, while WordPress PHPUnit resets hooks between tests. The test now boots the plugin composition root for every test and reads derivative records with the single-value metadata API. These fixes passed in run 42748365437 on both configured WordPress versions. Together, the evidence confirms the original transaction-boundary and same-second lease-renewal defects, the invalid integration PNG, and two integration-test harness defects; attachment discovery itself was not the common cause.

Implemented integration coverage includes individual attachment and thumbnail processing, auto enqueue off/on and deduplication, persistent claims/retries/stale recovery/pause/cancel, bounded scanner pagination, source and core metadata preservation, unsupported/deleted/missing media, authorization, dashboard data, and deactivation preservation. Known limits include local upload-root-only processing (remote/offloaded storage is refused), WP-Cron's traffic dependence, cancellation of pending work only, no multisite integration run, and possible operator cleanup if a valid derivative is written but postmeta persistence fails. See `ARCHITECTURE.md` for queue and filesystem details.

## Milestone 3 validation — 2026-11-10

Milestone 3 is implemented on `feature/milestone-3-webp-avif`. The latest code validation is GitHub Actions run [42735752617](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42735752617), commit `3c8ab941778f96d5aad205d53a4cb0f24181eca6`. All four jobs passed: static/unit, encoder integration, and WordPress integration against 6.6.11 and 7.1.4, using PHP 8.3. Earlier encoder runs exposed the WebP RIFF-size parsing defect; it was fixed before the successful run.

| Check | Result | Evidence / limitation |
|---|---|---|
| Foundation and branch | Passed | Started from clean local `main` and `origin/main` at Milestone 2 merge `bb27295515bac0b90f8f598ba27aac9214987a73`; created the requested feature branch. |
| PHP runtime | Partial | Local PHP 8.5.0; PHP 8.3 is configured in CI and unavailable locally. |
| WPCS | Passed locally after line-ending normalization | `composer lint --no-interaction` passed for the production source after temporarily normalizing PHP working files to LF for PHPCS. The checkout's Windows `core.autocrlf=true` mode presents CRLF and causes line-ending-only PHPCS failures on untouched files. Changed production files also pass direct PHPCS checks. Linux CI remains the canonical check. |
| Standalone tests | Passed locally | `composer test --no-interaction` passed all 30 existing assertions. |
| Composer validation/audit | Passed locally | `composer validate --no-check-publish` is valid and `composer audit --locked` reports no advisories. |
| Workflow YAML | Passed locally | `actionlint .github/workflows/ci.yml` passed. |
| PHP syntax | Passed locally | PHP 8.5.0 `php -l` passed for 33 PHP files, excluding `vendor/` and generated `build/` output. PHP 8.3 remains a CI check. |
| Real WebP/AVIF conversion | Passed in CI | Run 42735752617 installed trusted encoder packages and passed JPEG/PNG to WebP/AVIF conversions, alpha-preservation checks, structural validation, independent decoding, and measured output checks. The first CI run uncovered a RIFF size-field offset bug; subsequent run passed after correction. |
| Production ZIP | Passed locally | After `composer install --no-dev`, `php scripts/package.php` and `composer test:package` passed; development dependencies were restored afterward. |
| WordPress integration | Passed in CI | Run 42735752617 installed and exercised the generated production ZIP against disposable WordPress 6.6.11 and 7.1.4 cores and MySQL 8.0. Local Docker/WordPress database is unavailable. |
| Fixed-fixture conversion measurements | Passed in CI | Run 42735752617 recorded the measurements below. These are single-run fixture measurements, not universal performance claims. |

### CI benchmark output

| Fixture conversion | Input → output bytes | Elapsed |
|---|---:|---:|
| JPEG → WebP | 145,399 → 32,820 | 42.3 ms |
| JPEG → AVIF | 145,399 → 82,594 | 123.9 ms |
| PNG → lossy WebP | 262,488 → 2,588 | 23.2 ms |
| PNG → lossy AVIF | 262,488 → 2,565 | 103.9 ms |
| PNG → lossless WebP | 262,488 → 206 | 23.2 ms |
| PNG → lossless AVIF | 262,488 → 2,377 | 103.9 ms |

The runner also independently decoded each generated WebP/AVIF and confirmed the fixture dimensions; transparent PNG alpha was checked after conversion/decode. CI run results and job logs are available at the run link above.

Known Milestone 3 limits: conversion is explicit only and writes a separate derivative. EXIF-bearing sources are always rejected because orientation cannot be guaranteed. Other metadata-bearing sources are rejected while `preserve_metadata` is true; callers can explicitly opt out, and those profiles/metadata may be stripped. Runtime AVIF checks validate bounded ISO-BMFF boxes and primary dimensions, and WebP checks the RIFF/chunk structure and dimensions; independent decoder acceptance is exercised in CI. These checks do not prove visual equivalence. Animated formats are unsupported. CI pins Ubuntu 24.04 WebP 1.3.2 and libavif 1.0.4 package versions to exercise the older AVIF quantizer CLI; exact package versions may disappear from the active repository in the future. Local PHP 8.3 and real-encoder checks remain unavailable; both are verified by CI rather than represented as local passes.

## Milestone 2 implementation check — 2026-11-10

Milestone 2 processing code has been added on `feature/milestone-2-image-engine`; this is an implementation status report, not a production-readiness claim.

| Check | Result | Evidence / limitation |
|---|---|---|
| PHP runtime | Not fully verified | Local PHP is 8.5.0; PHP 8.3 is unavailable in this workspace. |
| PHP syntax | Passed locally and CI | `composer test` loaded the new API and exercised it on PHP 8.5.0; the PHP 8.3 static/unit job passed in [run 42729942236](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42729942236). |
| WPCS | Passed locally after remediation | Before: 215 errors and 34 warnings. After: `composer lint --no-interaction` reports 0 errors and 0 warnings. Scoped, explained WPCS exceptions cover native streaming filesystem calls, hard-link promotion, and `proc_open` argv execution because WordPress filesystem APIs do not provide these bounded/atomic local-file semantics. |
| Standalone tests | Passed locally | `composer test --no-interaction`: 30 assertions passed, including valid PNG CRCs, invalid CRC, truncated/invalid structure, APNG detection, unsupported/empty data, outside-root rejection, no-benefit handling, bounded stdout/stderr capture, argv exit-code capture, and enforced process timeout. The copy adapter test does not claim compression or savings. |
| Composer validation/audit | Passed locally | `composer validate --no-check-publish` is valid; `composer audit --locked` reports no advisories. |
| Real encoder processing | Passed in CI; unavailable locally | `composer test:encoders` stopped at preflight because encoder tools are not installed locally. The Ubuntu/PHP 8.3 job built pinned MozJPEG, installed pngquant and oxipng `9.1.2`, and passed real fixtures in [run 42729942236](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42729942236). |
| WordPress integration | Passed in CI; unavailable locally | PHP 8.3, WordPress test core, and disposable database are unavailable locally. Both disposable WordPress matrix jobs passed in [run 42729942236](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42729942236). |
| Installable ZIP | Passed locally | After remediation, `composer install --no-dev --no-interaction --optimize-autoloader`, `php scripts/package.php`, and `composer test:package` passed; development dependencies were restored afterward. |
| WordPress media safety | Scope review only | No upload/attachment hooks were added. Tests verify separate-output behavior with a copy adapter; no production media was accessed. |

Remaining engineering limitations: IDAT decompression and JPEG entropy stream validation are performed by the actual encoder rather than the PHP structure parser; metadata validation checks detected categories, not byte-for-byte metadata equality. The engine assumes managed root directories are trusted against hostile local users because portable PHP cannot remove every path TOCTOU race. Process timeouts terminate the direct child but cannot portably guarantee termination of descendants. CI validates the Ubuntu runner and configured WordPress matrix; it does not prove encoder availability or behavior on production servers.

Status: **CI verified, with documented limits**. Validation performed locally on PHP 8.5.0 and in GitHub Actions on PHP 8.3. No production site, VPS, or user media was accessed for Milestone 2.

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
