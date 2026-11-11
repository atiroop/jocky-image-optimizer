# Validation

## Milestone 6 — frontend delivery (CI verified)

Target release version is `0.6.0-beta.1`. The implementation is on `feature/milestone-6-frontend-delivery` at commit `1672531c028e3fbed4f839273089314280131be2`. GitHub Actions run [42935865495](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42935865495) passed all five jobs: static/unit, real encoder integration, packaged WordPress integration on 6.6.11 and 7.1.4, and beta package artifact publication.

| Local check | Result | Evidence / limitation |
|---|---|---|
| WPCS | Passed | `composer lint`: 0 errors and 0 warnings across 47 PHP files. |
| Standalone tests | Passed | `composer test`: all 36 checks passed, including default-off delivery and version metadata. |
| Composer validate/audit | Passed | `composer validate --no-check-publish` valid; `composer audit --locked` reported no advisories. |
| actionlint / diff | Passed | `actionlint .github/workflows/ci.yml` and `git diff --check` passed. |
| PHP syntax | Passed on PHP 8.5 | All project PHP files passed `php -l`; PHP 8.3 is not installed locally and must be validated by CI. |
| Production ZIP | Passed locally | Built after `composer install --no-dev`; `composer test:package` passed. Filename `jocky-image-optimizer-0.6.0-beta.1.zip`, one top-level plugin directory, runtime autoloader included, dev dependencies excluded. SHA-256 `5BBF84DFF32C3D1E350D61E2D73A36CDD57E974F2A43A47DC3745E1767FE4C89`. |
| WordPress integration | Passed in CI; not run locally | The CI jobs installed and exercised the generated production ZIP against WordPress 6.6.11 and 7.1.4 on PHP 8.3. Local `composer test:integration` could not start because this workspace lacks the WordPress test library and disposable database runtime. |
| Real encoder tests | Passed in CI; not run locally | The isolated Linux encoder job installed trusted MozJPEG, pngquant, oxipng, WebP, and AVIF tools and passed real fixture tests. Local `composer test:encoders` is unavailable because encoder executables including `cjpeg` are not installed. |
| Browser / Elementor runtime | Not run | Manual browser procedure is below. CI does not install Chromium or Elementor; fixture/core API tests are not reported as runtime compatibility tests. |
| Downloaded artifact | Passed | Artifact `jocky-image-optimizer-0.6.0-beta.1`, ID `13818128489`, is available from [run 42935865495](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42935865495/artifacts/13818128489). Downloaded ZIP is 110,792 bytes, SHA-256 `28722693AB4DBF0FC9AE9321CC7EF3BB0AD9F0C2982EA496920A85A50D938D69`; `php scripts/validate-package.php` confirmed the exact expected one-directory production ZIP structure and runtime dependencies. |

The module uses `wp_get_attachment_image` and `wp_content_img_tag`; original markup remains the fallback. The schema migration adds a nullable derivative file identity. Existing Milestone 5 rows are preserved, but rows with no identity are intentionally not served. They need re-optimization before frontend delivery can select them. The exact downloadable package passed both real WordPress matrix jobs and the artifact job.

CI acceptance tests passed for actual packaged ZIP installation/activation, database setup, default-off delivery, real WebP/AVIF generation and selection, preference order, original fallback attributes and responsive candidates, stale derivative fallback, and disabling delivery. Browser-level Chromium execution and actual Elementor plugin execution are not configured; core API integration assertions do not establish browser/Elementor runtime compatibility. No production site, VPS, database, media, cache, or settings were accessed.

### Manual browser procedure (disposable staging only)

1. Install the exact package ZIP on a disposable WordPress site with PHP 8.3 and a database/uploads backup. Keep delivery OFF and confirm rendered attachment images contain the original `<img>` without a JIO `<picture>` wrapper.
2. With local encoders installed, create test JPEG and transparent PNG attachments, queue WebP and AVIF derivatives, and wait for the normal worker to finish. Do not use production media.
3. Add an attachment image through the Media Library, a Gutenberg Image block, and a Classic Editor image. Enable delivery, purge page caches, and inspect each rendered `<picture>` in Chromium DevTools. Confirm source order follows the selected preference, the `<img>` fallback is the original URL, and `sizes`, intrinsic dimensions, alt, loading, and decoding remain present.
4. In the browser console, run `Array.from(document.querySelectorAll('picture')).map((p) => ({ types: Array.from(p.querySelectorAll('source')).map((s) => s.type), fallback: p.querySelector('img')?.getAttribute('src'), current: p.querySelector('img')?.currentSrc, complete: p.querySelector('img')?.complete }))`. Confirm currentSrc is a browser-supported derivative, complete is true, and the fallback remains original. Use the Network panel to verify there are no failed image requests.
5. Test a derivative after removing it only from disposable test media, and confirm output falls back to the original on a fresh uncached request. Turn delivery OFF, purge WP Rocket/Cloudflare/page cache, reload, and confirm original-only markup. This is a manual browser procedure, not a CI result.

The cache integration calls WP Rocket's documented `rocket_clean_domain()` function when available ([WP Rocket API reference](https://docs.wp-rocket.me/article/92-rocketcleandomain)); that API clears the full domain cache. Cloudflare remains a manual purge. No WP Rocket, Cloudflare, browser, or Elementor runtime test has been executed by this workspace.

## Milestone 5 — safety closure (CI verified)

The feature implementation is on `feature/milestone-5-backup-restore`. This closure adds a default-off destructive recovery gate, stale-lease lock coordination, stronger unlink-boundary checks, independent worker tests, and migration retry tests. The latest closure commit `5642b386264b363c72c75f270fd46b7b6ef93063` passed all five jobs in [run 42796876298](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42796876298). No production/VPS access or media modification was performed.

The initial closure run [42796487189](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42796487189) passed static/unit, real encoder, and PHPUnit integration tests but exposed a `wp eval-file` wrapper incompatibility with `strict_types` declarations. Removing those declarations fixed the scripts. Repair run [42796876298](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42796876298) passed all five jobs, including both packaged WP-CLI matrix suites and the beta artifact job.

| Check | Result | Evidence / limitation |
|---|---|---|
| WPCS | Passed locally and CI | `composer lint --no-interaction`: 0 errors and 0 warnings across 43 production files; `static-and-unit` also passed. |
| Standalone tests | Passed locally | `composer test` passed all 30 standalone checks. |
| Composer validation and audit | Passed locally | `composer validate --no-check-publish` valid; `composer audit --locked` found no advisories. |
| PHP syntax | Passed locally and CI | Changed PHP files pass `php -l` on PHP 8.5.0; `static-and-unit` passed syntax checks on PHP 8.3. |
| Workflow YAML/schema | Passed locally | `actionlint .github/workflows/ci.yml` passed; no workflow structure changes were needed. |
| Production ZIP | Passed locally and CI | Built after `composer install --no-dev`; local `composer test:package` passed. The actual run 42785634155 artifact was downloaded and its 100,759-byte inner ZIP passed `php scripts/validate-package.php`. |
| Real encoder integration | Passed in CI | `encoder-integration` installed trusted, pinned encoders and passed real fixtures. Local `composer test:encoders` stopped at the `cjpeg` preflight because encoder binaries are not installed locally. |
| WordPress integration | Passed in CI | Both PHP 8.3 disposable production-ZIP jobs passed on WordPress 6.6.11 and 7.1.4, including failure-injection and admin request tests. This Windows workspace has no local WP-CLI, Docker/Podman, disposable core, or database runtime. |
| Recovery WP-CLI | Passed in CI | Run 42796876298 passed both PHP 8.3 matrix smoke suites: fail-closed cleanup default, two-process single-job claim, lock exclusion, schema repair, and packaged cleanup/source retention. |
| Original/media safety | Passed in CI with documented limits | Boundary regression catches WordPress metadata changes and derivative replacement between preview and unlink; CLI checks preserve source and derivative when another cleanup owns the lock. No production media was accessed. Hostile local-writer final unlink race remains a platform limitation. |
| Artifact | Passed, available, and non-empty | Artifact `jocky-image-optimizer-0.4.0-beta`, ID `13755460104`, 96,617 bytes, is available from [run 42796876298](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42796876298/artifacts/13755460104); digest `sha256:ebd7c6fbc66d2ab337297640f851cb6cbba1a8a515a2693ca6af742e42ef7f43`. The packaged ZIP was built and installed in both disposable WordPress jobs. The prior downloaded artifact and inner ZIP structure remain validated above. |
| Filesystem race boundary | Limitation documented | Uses canonical uploads confinement, symlink rejection, streamed digests, device/inode/size/mtime identity recheck, and shared MySQL advisory locks. PHP cannot eliminate the final unlink TOCTOU window against a hostile local writer; uploads directories must be writable only by trusted site processes. |
| Recovery default gate | Implemented and CI verified | Service returns `cleanup_disabled`; admin, CLI and worker paths check the same gate. Gate requires explicit `JIO_ENABLE_DESTRUCTIVE_RECOVERY=true` and WordPress environment `local` or `development`. Production/staging default remains read-only. |
| New CI recovery/migration checks | Passed | Run 42796876298 passed the synchronized database barrier, lock exclusion, default-off cleanup rejection, and partial-schema retry preserving existing rows. |

Implemented behavior includes schema-v2 derivative registry/audit/batch tables, bounded verification and legacy indexing, read-only previews, history, and WP-CLI recovery commands. Destructive cleanup is disabled by default, including in production and staging, and is available only when explicitly enabled in a local/development environment. The digest manifest is not an independent backup: missing/corrupt originals require UpdraftPlus or another verified external backup. Hashing is limited to 50 MiB per file and 100 MiB per operation; ambiguous or over-limit files fail closed. WordPress core metadata updates, attachment deletion, and external restore do not use the plugin lock; the new default gate avoids exposing cleanup to those unsynchronized races.

### Requirements acceptance matrix

| Requirement | Status | Implemented | Tested | Evidence | Remaining gap |
|---|---|---|---|---|
| 14. Frontend delivery readiness architecture | PASS WITH DOCUMENTED LIMITATION | Documentation only; delivery stays off | Document review | `ARCHITECTURE.md` describes derivative mapping, responsive `srcset`, `<picture>` fallback, Elementor, WP Rocket, and separate Cloudflare/R2 design | No frontend URL or markup integration is activated or tested; intentionally future work |
| 15. Duplicate `WP_DEBUG` warning and bulk scan progress | PASS WITH DOCUMENTED LIMITATION | Progress normalization fixed; warning source not established | Progress regression passed CI; latest disposable WP-CLI smoke captured no duplicate warning | `BulkScanner::state()` supplies fields consumed by `assets/admin.js`. No duplicate define in tracked source or latest CLI output | The originally reported warning remains unreproduced; no source lines supplied and no production config inspection |
| 16. Recovery integrity, safety, DB failures, concurrency, security, WP-CLI | PASS WITH DOCUMENTED LIMITATION | Destructive cleanup defaults off; backend gate plus boundary rechecks; stale recovery uses attachment lock | Run 42796876298 passed independent-process claim, cleanup exclusion, migration and gate smoke on both WP versions | External WordPress deletion/metadata changes and restore do not share the plugin lock; default gate contains cleanup risk. Filesystem TOCTOU after final check remains. |
| 17. CI preservation and required jobs | PASS | Existing five-job matrix retained; workflow definition unchanged | All five jobs passed in run 42796876298 | No CI run reproduces production ownership or hostile local filesystem writers |
| 18. Performance bounds | PASS WITH DOCUMENTED LIMITATION | Bounded hashing/pages/cleanup | Prior CI exercised pagination and cleanup; code review | Streamed 1 MiB blocks; 50 MiB/file and 100 MiB/call; pages and cleanup bounded to ten per worker tick | No production-scale memory/timing benchmark; over-limit files require operator handling |
| 19. Production safety | PASS | No production operations performed | Activity review | No SSH, live files/database, media, server config, or site modifications | Future staging/deployment needs separate authorization |
| 20. Documentation accuracy | PASS WITH DOCUMENTED LIMITATION | Docs updated with actual concurrency boundaries and backup semantics | Diff review | README and ARCHITECTURE explain source preservation, derivative ownership, cleanup, migration, external backup, inactive delivery | Core metadata/deletion/external restore races remain unresolved |
| 21. Automated Git workflow | PASS | Scoped current branch work only | Git review | Authorized changes are limited to `feature/milestone-5-backup-restore`; no merge requested | No merge or deployment was performed |
| 22. Original Milestone 5 definition of done | PASS WITH DOCUMENTED LIMITATION | Safety gate, queue coordination, migration repair and recovery behavior implemented | Run 42796876298 passed the added CLI and existing WordPress matrix suites | External core metadata/deletion and restore races remain outside plugin coordination; cleanup is disabled in production/staging |
| 23. Final report | PASS | Findings and limitations recorded | This audit | Final response reports evidence, SHA and CI URL | Merge remains explicitly unauthorized; merge readiness is reported separately |

### Original handoff acceptance criteria (sections 3–13)

| Section | Acceptance result | Evidence / gap |
|---|---|---|
| 3. Scope and frontend readiness | Partial | Recovery/ownership/history/admin/CLI functionality exists; frontend delivery remains intentionally inactive and its future design is documented. |
| 4. Backup architecture and original integrity manifest | Implemented | Bounded SHA-256 and WordPress references are recorded; no duplicate original backups; external backup restore is required for missing/corrupt bytes. |
| 5. Derivative ownership registry | Implemented | Dedicated v2 tables hold relative paths/digests/encoder/lifecycle; arbitrary filename-based adoption is rejected; legacy import requires current source relation and exact expected derivative path. |
| 6. Restore semantics | Implemented | Original delivery state is verified, not reconstructed; derivative cleanup is separate; missing/corrupt originals are referred to external backup. |
| 7. Per-attachment recovery | Implemented; request regression passed | CI exercised missing/invalid nonce, invalid ID, non-admin, and missing confirmation; each was rejected before filesystem mutation. |
| 8. Safe deletion | Disabled by default; gated implementation | Outside explicit local/development opt-in all deletion APIs return disabled. Gated path rechecks core metadata, attachment, record ownership, active jobs, source/derivative identity and core-managed paths. Boundary regressions passed in run 42796876298. Portable PHP cannot eliminate hostile-writer TOCTOU. |
| 9. Bulk recovery | Preview implemented; deletion disabled by default | Dry-run summary and inventory remain read-only; backend admin, CLI, worker and service paths fail closed unless the local/development gate is explicitly enabled. |
| 10. Optimization history | Implemented; semantic coverage partial | Persisted job/derivative/history state shown; separate derivatives do not necessarily reduce disk usage. History pagination and all lifecycle labels do not have dedicated assertion coverage. |
| 11. Admin UI | Implemented; request regression passed | Escaped/capability/nonce-protected pages and row link exist. The real handler rejection checks passed in both WordPress jobs; no browser responsive UI test. |
| 12. WP-CLI recovery commands | Implemented; CI verified | Packaged smoke covers synthetic verify/preview/cleanup and source retention, disabled-by-default cleanup and bulk-start rejection, independent process claims, lock exclusion and partial schema recovery. No separate `recovery worker` command exists; batch execution is `bulk --action=run`. |
| 13. Queue coordination | Implemented; CI verified | Optimization and cleanup share a connection-scoped per-attachment advisory lock; stale recovery must acquire it before changing ownership. Queue updates remain atomic and token conditional. Two-process WP-CLI claim barrier passed. |

### Restored Section 13 scenario assessment

| Scenario | Status | Evidence and limit |
|---|---|---|
| Active image encoding and derivative promotion vs cleanup | PASS WITH DOCUMENTED LIMITATION | Both plugin paths acquire `AttachmentOperationLock` around processing/removal. A second DB connection holding the same advisory lock blocks acquisition; the integration assertion passed CI. No process-parallel encode/cleanup timing test is present. |
| Queue retries and two workers claiming one job | Implemented; CI verified | Two independent WP-CLI processes wait on a database-backed barrier and contend for one pending synthetic row; run 42796876298 passed one token-owned claim and one empty result. |
| Two cleanup workers targeting the same derivative | Implemented; CI verified | One independent DB connection holds the attachment advisory lock while another cleanup runs; the test returned `operation_locked` and preserved source and derivative. |
| Lease loss/expiry and stale recovery | Implemented; CI verified | A stale job remained running with its token while a separate connection held the attachment lock; it recovered only after release. Existing tests cover token ownership/retry. |
| WordPress attachment metadata update during cleanup | Safety gate plus boundary regression; CI verified | Regression mutates `_wp_attached_file` between preview and unlink; cleanup retains derivative and reports conflict. External core paths still do not acquire the lock. |
| Attachment deletion during cleanup | Contained by default-off gate | Destructive cleanup is disabled outside explicitly enabled local/development environments. A concurrent core delete still cannot be fully serialized; no deletion hook can make core honor the plugin lock. |
| Concurrent restore and cleanup | Contained by default-off gate | The plugin never restores original bytes; external backup tools remain outside its lock. Cleanup is disabled in production/staging. |
| Cleanup failure recovery | PASS WITH DOCUMENTED LIMITATION | Integration tests inject failed registry insert, failed audit insert, failed pre-unlink lifecycle transition, and failed post-unlink status update; all passed CI. Post-unlink failures leave a visible conflict if the follow-up DB update works. |
| Partial schema migration retry | Implemented; CI verified | WP-CLI smoke removes a recovery table, queue index/column, and seed row, retries `dbDelta`, simulates interruption before version persistence, and verifies job and derivative row preservation; passed in run 42796876298. |
| Admin destructive request security | PASS WITH DOCUMENTED LIMITATION | Real WordPress handler tests for missing/invalid nonce, non-admin, invalid attachment ID, and missing confirmation passed before filesystem changes. Default-off gate checks passed in run 42796876298. |
| Packaged WP-CLI recovery | Implemented; CI verified | Synthetic PNG/WebP smoke and default-off rejection, separate process claims, lock exclusion and migration retry passed in both packaged WordPress jobs. WP-CLI cannot establish admin HTTP authorization behavior. |

### Warning investigation and migration/rollback notes

The reported duplicate `WP_DEBUG` warning is not present in checked-out plugin sources, test bootstrap, workflow setup, or captured logs for runs 42776251310, 42780293142, and 42785634155; the latest packaged WP-CLI smoke also captured no duplicate warning. The plugin does not define `WP_DEBUG`. Its root cause remains unconfirmed and is likely outside the plugin, but that attribution is inference only. No production `wp-config.php` or live server was inspected. To resolve it, provide the exact warning and two file/line contexts with secrets redacted.

The schema migration uses additive/idempotent `dbDelta` and advances the schema version only after required tables, columns, indexes, and seed rows are present. The disposable WP-CLI scenario passed in run 42796876298 after testing a missing recovery table, missing job index/column, missing seed slot, stale version state, an interrupted version write, and preservation of existing jobs/derivative rows. Rollback to an older plugin build leaves the new plugin tables and audit data orphaned but does not intentionally delete them; do not downgrade schema options manually. Destructive cleanup, when explicitly enabled for local/development testing, cannot be rolled back from plugin state because derivative bytes are deleted; originals remain. Back up the database and uploads before any staging/production install.

### Risk classification

| Risk | Class | Control / disposition |
|---|---|---|
| Original JPEG/PNG or WordPress thumbnail deleted by recovery | CRITICAL | No known optimizer path overwrites originals; cleanup is backend-disabled by default and rejects any derivative path currently referenced as a core source or thumbnail. Boundary regressions passed in run 42796876298. |
| Unauthorized deletion request | CRITICAL | Capability and nonce checks remain on admin routes; WP-CLI requires explicit command invocation and confirmation. The cleanup gate is enforced in service, handler, CLI and worker layers; gate checks passed in run 42796876298. |
| Cross-worker duplicate job claim / lease ownership transfer during live encoding | HIGH | Queue claims remain a single conditional InnoDB update with token ownership; stale recovery requires the shared attachment lock. Independent-process barrier and stale-lock checks passed in run 42796876298. |
| Derivative cleanup races external metadata changes, attachment deletion or backup restore | HIGH when deletion enabled | These core/external operations cannot be made to honor the plugin lock. Destructive cleanup is unavailable by default and gated to explicit local/development test environments. Do not enable on production/staging. |
| Interrupted schema upgrade leaves incomplete state | HIGH | `dbDelta` re-runs when tables/indexes/seeds are incomplete; version is written only after checks. Recovery/preservation scenario passed in run 42796876298. |
| Unlink TOCTOU after final identity check | MEDIUM | Not eliminable portably in PHP; trusted uploads ownership is required. Default gate prevents production/staging cleanup. |
| Duplicate `WP_DEBUG` diagnostic | LOW | Not reproduced in repository or CI logs; no plugin WP_DEBUG definition found. Source remains unconfirmed and no production config was inspected. |

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
