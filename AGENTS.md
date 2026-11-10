# Instructions for future Codex sessions

- Work only in this repository and implement only the milestone requested by the user.
- Inspect Git status and existing files before editing; preserve existing work.
- The repository owner explicitly authorizes Codex to stage task-related changes, run quality checks, commit, push to the current task's remote feature branch, monitor GitHub Actions, and repair confirmed CI failures within the assigned task scope. Do not ask for per-commit or per-push confirmation.
- Keep image processing local. Do not invent processing results or report simulated capability.
- Never execute commands built from upload data; avoid shell execution for encoders unless a later reviewed adapter uses safe argument handling.
- Preserve originals and WordPress attachment metadata. Validate output before any replacement, use atomic operations, and keep failure recovery.
- Gate admin actions with capabilities and nonces; sanitize inputs and escape outputs.
- Do not add automatic system package installation, visitor-driven expensive work, or automatic cron configuration.
- Leave Cloudflare R2 and third-party R2 settings untouched until the dedicated milestone.
- Run documented checks and state clearly what could not be verified. Do not claim WordPress compatibility without integration testing.
- Keep `composer audit --locked` clean; update vulnerable dev dependencies rather than ignoring advisories.
- Integration tests must install and exercise the generated production ZIP in a disposable WordPress core/database. Do not substitute WordPress API stubs for these tests.
- CI runs on PHP 8.3 and currently covers WordPress 6.6.11 and 7.1.4. Do not report CI results until the GitHub workflow has actually run.
- Run `composer install --no-dev` before generating the installable ZIP, then restore dev dependencies if further checks are needed.
- Keep docs and CHANGELOG current when behavior changes.

## Git Commit, Push, and CI Workflow

For each development task:

1. Inspect Git status and the current branch before editing. Preserve unrelated local modifications.
2. Create or reuse the appropriate feature branch. Never work on `main` for feature changes.
3. Implement only the requested task and run its documented local tests, linting, and validation. Fix failures rather than bypassing or disabling tests.
4. Stage only files created or modified for the assigned task. Review the staged diff for secrets, credentials, unrelated changes, and production safety.
5. Create a descriptive commit after required local checks pass.
6. Push the commit to `origin/<feature-branch>` without force-pushing.
7. Monitor the GitHub Actions workflow for the pushed commit. Do not report CI success until GitHub reports a successful run.
8. For confirmed CI failures, diagnose the failure, fix it without weakening tests or safety requirements, run available checks, commit the correction, and push again. Limit automated CI repair to three cycles; if CI still fails, stop and report the blocker.
9. Report the final branch, commit SHA, CI run URL and result, and any checks or dependencies that could not be verified.

This standing authorization applies only to this repository and the assigned task. It does not authorize merging or production deployment.

Mandatory restrictions:

- Never force-push, delete remote branches automatically, or rewrite published Git history.
- Never commit secrets or credentials.
- Never push directly to `main` or merge a pull request without explicit approval.
- Never deploy to production.
- Never discard unrelated local modifications.
- Never create commits outside this assigned repository.
- Preserve all existing security, image-processing, and testing requirements in this file.
