# Roadmap

1. **Milestone 1 — Plugin Foundation (implemented):** bootstrap, settings, diagnostics, unit checks, docs, and ZIP packaging.
2. **Milestone 1.5 — WordPress Integration Validation (implemented):** real-core integration suite and PHP 8.3 CI; see `VALIDATION.md` for the recorded passing run.
3. **Milestone 2 — Image Processing Engine (QA in progress):** explicit local JPEG/PNG optimization API, CRC-checked PNG parsing, encoder adapters, bounded validation, and safe separate-output handling. WPCS and standalone checks pass locally; real encoders and WordPress integration require the new GitHub workflow to run successfully before this milestone is considered validated.
4. **Milestone 3 — Next-generation Formats:** WebP/AVIF derivatives and safe WordPress delivery integration.
5. **Milestone 4 — Automatic and Bulk Optimization:** post-upload pipeline, bounded queue, CLI worker, retries, pause/resume, and recovery.
6. **Milestone 5 — Backup and Restoration:** managed originals, restoration workflow, and interrupted-operation recovery.
7. **Milestone 6 — Cloudflare R2:** optional adapter after studying existing offload workflows and compatibility.
8. **Milestone 7 — Production Hardening:** security review, load and compatibility testing, operational docs, and release packaging.

Do not claim parity with ShortPixel: encoders and settings produce different quality and file-size results.
