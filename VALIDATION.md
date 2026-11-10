# Validation

## Milestone 5 — final remediation (validation pending; merge blocked)

The feature implementation is on `feature/milestone-5-backup-restore`. The restored handoff specifies coordination with active encoding/promotion, queue retries, WordPress metadata updates/deletion, and overlapping recovery operations. No production/VPS access or media modification was performed.

The feature previously passed all five jobs in [run 42781239061](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42781239061) at `2f3f8c3631cbc03ad4c0c23045a494012a1b75cc`. Current changes add recovery failure handling, independent-connection integration checks, request-handler security coverage, and destructive packaged WP-CLI smoke. Those new tests are not considered passed until a new GitHub Actions run succeeds.

| Check | Result | Evidence / limitation |
|---|---|---|
| WPCS | Passed locally | Final `composer lint --no-interaction`: 0 errors and 0 warnings across the 43 production files. |
| Standalone tests | Passed locally | `composer test` passed all 30 standalone checks. |
| Composer validation and audit | Passed locally | `composer validate --no-check-publish` valid; `composer audit --locked` found no advisories. |
| PHP syntax | Partial locally | Changed PHP files pass `php -l` on PHP 8.5.0. PHP 8.3 is not installed locally; full CI syntax validation is pending. |
| Workflow YAML/schema | Passed locally | `actionlint .github/workflows/ci.yml` passed; no workflow structure changes were needed. |
| Production ZIP | Passed locally | Followed `composer install --no-dev`, built `build/jocky-image-optimizer-0.4.0-beta.zip`, and `composer test:package` confirmed its structure and production dependencies; development dependencies were restored afterward. |
| Real encoder integration | Passed in CI | `encoder-integration` installed trusted, pinned encoders and passed real fixtures. Local `composer test:encoders` stopped at the `cjpeg` preflight because encoder binaries are not installed locally. |
| WordPress integration | Passed in CI | Both PHP 8.3 disposable production-ZIP integration jobs passed on WordPress 6.6.11 and 7.1.4, including audit regressions. This Windows workspace has no local WP-CLI, Docker/Podman, disposable core, or database runtime. |
| Recovery WP-CLI | New coverage pending CI | The updated checksum-verified WP-CLI 2.12.0 smoke uses a synthetic attachment and cwebp derivative to exercise verify, preview, cleanup rejection without `--confirm`, invalid attachment rejection, confirmed cleanup, original preservation, repeated cleanup, and WP_DEBUG warning capture. No new CI run has executed this script yet. |
| Original/media safety | Existing CI pass; new checks pending | Previous integration coverage checked source bytes, metadata, thumbnails, corruption/missing files, and queue conflicts. New tests inject DB failures before and after unlink and exercise admin rejection paths; awaiting CI. No production media was accessed. |
| Artifact | Passed and downloaded | Artifact `jocky-image-optimizer-0.4.0-beta` (93,997 bytes outer artifact, ID `13745544255`) was downloaded. [Download artifact](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42780293142/artifacts/13745544255). Its inner plugin ZIP is 100,633 bytes, passes `php scripts/validate-package.php`, and has SHA-256 `30417c8bb53ecdb7cd7c300c35776b30fa52cfe50c55e2cf45d82f4d0cd4f6b6`. |
| Filesystem race boundary | Limitation documented | Uses canonical uploads confinement, symlink rejection, streamed digests, device/inode/size/mtime identity recheck, and shared MySQL advisory locks. PHP cannot eliminate the final unlink TOCTOU window against a hostile local writer; uploads directories must be writable only by trusted site processes. |

Implemented behavior includes schema-v2 derivative registry/audit/batch tables, bounded verification and legacy indexing, per-attachment cleanup preview/actions, paginated bulk candidate preview and persistent cleanup worker controls, history, and WP-CLI recovery commands. The digest manifest is not an independent backup: missing/corrupt originals require UpdraftPlus or another verified external backup. Hashing is limited to 50 MiB per file and 100 MiB per operation; ambiguous or over-limit files fail closed. MySQL advisory locks coordinate plugin optimization/promotion and plugin recovery but do not lock WordPress core metadata updates, attachment deletion, or an external backup restore; these remain release-blocking concurrency gaps unless accepted with supporting safeguards.

### Requirements acceptance matrix

| Requirement | Status | Implemented | Tested | Evidence | Remaining gap |
|---|---|---|---|---|
| 14. Frontend delivery readiness architecture | PASS WITH DOCUMENTED LIMITATION | Documentation only; delivery stays off | Document review | `ARCHITECTURE.md` describes derivative mapping, responsive `srcset`, `<picture>` fallback, Elementor, WP Rocket, and separate Cloudflare/R2 design | No frontend URL or markup integration is activated or tested; intentionally future work |
| 15. Duplicate `WP_DEBUG` warning and bulk scan progress | PASS WITH DOCUMENTED LIMITATION | Progress normalization fixed; warning source not established | Progress regression previously passed CI; current disposable CLI warning capture awaits CI | `BulkScanner::state()` supplies fields consumed by `assets/admin.js`. No duplicate define in tracked source or existing captured logs | Warning not reproduced; no sanitized original warning/file-line evidence and no production config inspection |
| 16. Recovery integrity, safety, DB failures, concurrency, security, WP-CLI | BLOCKED | Core paths exist; focused failure/security/CLI tests added | Previous suite passed; new tests await CI | `PluginIntegrationTest.php`, `RecoveryService.php`, `RecoveryCommand.php`, and `bin/test-wp-cli.sh` | Partial-table migration retry still lacks a valid isolated test; no parallel two-cleanup race or mid-operation attachment deletion test. WordPress metadata updates, deletion, and external restore do not use plugin locks |
| 17. CI preservation and required jobs | BLOCKED pending run | Existing five-job matrix preserved; added tests extend it | Previous run 42781239061 passed all five jobs; current changes have not run in CI | PHP 8.3, WordPress matrix, encoders, and package artifact remain configured | Do not treat previous run as evidence for current changes |
| 18. Performance bounds | PASS WITH DOCUMENTED LIMITATION | Bounded hashing/pages/cleanup | Prior CI exercised pagination and cleanup; code review | Streamed 1 MiB blocks; 50 MiB/file and 100 MiB/call; pages and cleanup bounded to ten per worker tick | No production-scale memory/timing benchmark; over-limit files require operator handling |
| 19. Production safety | PASS | No production operations performed | Activity review | No SSH, live files/database, media, server config, or site modifications | Future staging/deployment needs separate authorization |
| 20. Documentation accuracy | PASS WITH DOCUMENTED LIMITATION | Docs updated with actual concurrency boundaries and backup semantics | Diff review | README and ARCHITECTURE explain source preservation, derivative ownership, cleanup, migration, external backup, inactive delivery | Core metadata/deletion/external restore races remain unresolved |
| 21. Automated Git workflow | PASS | Scoped current branch work only | Git review | Authorized changes are limited to `feature/milestone-5-backup-restore`; no merge requested | Push and current CI still pending |
| 22. Original Milestone 5 definition of done | BLOCKED | Partial | Acceptance tables below | Section 13 is fully assessed and limited | Current CI, destructive CLI/admin tests, and external core-operation concurrency risks prevent acceptance |
| 23. Final report | PASS | Findings and limitations recorded | This audit | Final response reports evidence, SHA and CI URL | Merge remains explicitly unauthorized and unready |

### Original handoff acceptance criteria (sections 3–13)

| Section | Acceptance result | Evidence / gap |
|---|---|---|
| 3. Scope and frontend readiness | Partial | Recovery/ownership/history/admin/CLI functionality exists; frontend delivery remains intentionally inactive and its future design is documented. |
| 4. Backup architecture and original integrity manifest | Implemented | Bounded SHA-256 and WordPress references are recorded; no duplicate original backups; external backup restore is required for missing/corrupt bytes. |
| 5. Derivative ownership registry | Implemented | Dedicated v2 tables hold relative paths/digests/encoder/lifecycle; arbitrary filename-based adoption is rejected; legacy import requires current source relation and exact expected derivative path. |
| 6. Restore semantics | Implemented | Original delivery state is verified, not reconstructed; derivative cleanup is separate; missing/corrupt originals are referred to external backup. |
| 7. Per-attachment recovery | Implemented; request regression added | Capability rejection and service cleanup regressions previously passed CI. New missing/invalid nonce, invalid ID, non-admin, and missing-confirmation handler checks are pending CI. |
| 8. Safe deletion | Implemented with documented residual risk | Confined exact derivative path, symlink rejection, SHA-256/identity recheck, lifecycle transition and idempotent status. New DB failure tests check pre-unlink blocking and post-unlink conflict reporting; pending CI. Portable PHP unlink TOCTOU against hostile local writers cannot be fully eliminated. |
| 9. Bulk recovery | Implemented | Dry-run summary, explicit start confirmation, persistent bounded cursor, pause/resume/cancel/retry, per-attachment locking and audit. CI integration coverage exercises bounded cleanup. |
| 10. Optimization history | Implemented; semantic coverage partial | Persisted job/derivative/history state shown; separate derivatives do not necessarily reduce disk usage. History pagination and all lifecycle labels do not have dedicated assertion coverage. |
| 11. Admin UI | Implemented; request regression added | Escaped/capability/nonce-protected pages and row link exist. New actual handler rejection checks for bad/missing nonce, ID, confirmation, and non-admin await CI; no browser responsive UI test. |
| 12. WP-CLI recovery commands | Implemented; destructive smoke added | Updated smoke creates a synthetic media attachment and derivative, checks verify/preview, confirmation and invalid-ID rejection, then confirmed and repeated cleanup plus source retention. Pending CI. No separate `recovery worker` command exists; batch execution is `bulk --action=run`. |
| 13. Queue coordination | Partial; merge blocked | Optimization and cleanup use the same connection-scoped per-attachment MySQL advisory lock; DB queue claim/retry transitions are token-conditional. New independent-connection claim and lock exclusion checks await CI. Existing stale queue recovery/token ownership covers expiry of queue leases, not advisory-lock time expiry. |

### Restored Section 13 scenario assessment

| Scenario | Status | Evidence and limit |
|---|---|---|
| Active image encoding and derivative promotion vs cleanup | PASS WITH DOCUMENTED LIMITATION | Both plugin paths acquire `AttachmentOperationLock` around processing/removal. A second DB connection holding the same advisory lock blocks acquisition; the new integration assertion awaits CI. No process-parallel encode/cleanup timing test is present. |
| Queue retries and two workers claiming one job | PASS WITH DOCUMENTED LIMITATION | Queue transitions use one conditional update and worker tokens; existing tests cover retry/stale recovery and wrong-token rejection. New independent-connection claim test awaits CI; it checks sequential claims across distinct connections, not simultaneous processes. |
| Two cleanup workers targeting the same derivative | BLOCKED | Per-attachment lock should serialize plugin cleanup, but a true process-parallel cleanup test is absent. |
| Lease loss/expiry and stale recovery | PASS WITH DOCUMENTED LIMITATION | Existing integration tests force a queue lease stale, recover/retry it, and check token ownership. Advisory locks are connection-scoped locks, not expiring leases. Mid-encoder lease loss is not directly injected. |
| WordPress attachment metadata update during cleanup | BLOCKED | Cleanup verifies core metadata under the plugin lock, but WordPress core metadata update paths do not acquire that plugin lock. A hostile/local race cannot be excluded. |
| Attachment deletion during cleanup | BLOCKED | Missing source before cleanup is tested and fails closed. Deletion after preview/while cleanup runs is not deterministically tested; WordPress deletion does not acquire the plugin lock. |
| Concurrent restore and cleanup | BLOCKED | The plugin does not restore original bytes; UpdraftPlus/external restore operations are outside its lock. Concurrent external restore is unsupported. |
| Cleanup failure recovery | PASS WITH DOCUMENTED LIMITATION | New deterministic integration tests inject failed registry insert, failed audit insert, failed pre-unlink lifecycle transition, and failed post-unlink status update; pending CI. Post-unlink failures leave a visible conflict if the follow-up DB update works. |
| Partial schema migration retry | BLOCKED | Existing older-version idempotent migration test verifies existing queue and legacy derivative preservation. A separate-prefix partial-table test was attempted but did not recreate the table in the disposable integration setup; it was removed rather than counted as passing. |
| Admin destructive request security | PASS WITH DOCUMENTED LIMITATION | New real WordPress handler test checks missing/invalid nonce, non-admin, invalid attachment ID, and missing confirmation before filesystem changes; pending CI. Repeated request is covered at service level only. |
| Packaged WP-CLI recovery | PASS WITH DOCUMENTED LIMITATION | Updated smoke uses synthetic PNG/WebP files and checks verify/preview, no-confirm and malformed-ID rejection, confirmed/repeated cleanup, and source retention; pending CI. WP-CLI cannot establish admin HTTP authorization behavior. |

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
