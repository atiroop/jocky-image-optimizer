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
- Keep docs and CHANGELOG current when behavior changes.
