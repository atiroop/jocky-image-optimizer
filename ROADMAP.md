# Roadmap

1. **Milestone 1 — Plugin Foundation (implemented):** bootstrap, settings, diagnostics, unit checks, docs, and ZIP packaging.
2. **Milestone 1.5 — WordPress Integration Validation (implemented):** real-core integration suite and PHP 8.3 CI; see `VALIDATION.md` for the recorded passing run.
3. **Milestone 2 — Image Processing Engine (implemented and CI verified):** explicit local JPEG/PNG optimization API, CRC-checked PNG parsing, encoder adapters, bounded validation, and safe separate-output handling.
4. **Milestone 3 — Next-generation Formats (implemented and CI verified):** explicit still WebP/AVIF conversion APIs, independently decoded real-encoder tests, and measured fixed-fixture results. WordPress delivery integration remains deferred; see `VALIDATION.md` for the passing workflow and limits.
5. **Milestone 4 — Automatic and Bulk Optimization (implemented; CI verified):** post-upload enqueue hook, bounded paginated scan, persistent queue, CLI worker, retries, pause/resume, cancellation, and derivative records. See `VALIDATION.md` for the passing PHP 8.3 WordPress and encoder workflow.
6. **Milestone 5 — Derivative recovery and lifecycle (implementation complete; merge acceptance blocked):** digest-backed derivative ownership, bounded source/derivative verification, explicit per-attachment cleanup, persistent bulk cleanup batches, audit/history, legacy registry import, and WP-CLI controls. Final remediation passed all five jobs in [run 42785634155](https://github.com/atiroop/jocky-image-optimizer/actions/runs/42785634155). Merge acceptance remains blocked: duplicate claims across DB connections, process-parallel cleanup, and partial-table migration retry lack valid tests; WordPress metadata updates, attachment deletion, and external restore are not coordinated by the plugin advisory lock. Originals are retained and external backups remain necessary for original-byte recovery. See `VALIDATION.md` for the status matrix and evidence.
7. **Milestone 6 — Cloudflare R2:** optional adapter after studying existing offload workflows and compatibility.
8. **Milestone 7 — Production Hardening:** security review, load and compatibility testing, operational docs, and release packaging.

Do not claim parity with ShortPixel: encoders and settings produce different quality and file-size results.
