# Instructions for future Codex sessions

- Work only in this repository and implement only the milestone requested by the user.
- Inspect Git status and existing files before editing; preserve existing work.
- Never deploy, push, force-push, or commit without explicit instruction.
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
