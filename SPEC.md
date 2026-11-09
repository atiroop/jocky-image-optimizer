# Product Specification

## Objective

Provide a self-hosted WordPress image optimization plugin that processes media locally, preserves WordPress attachment behavior and originals, and reports real processing outcomes. Avoid paid optimization APIs.

## Requirements

- Optimize JPEG and PNG locally using available encoders; support lossy/lossless modes where the chosen encoder permits.
- Generate WebP and AVIF derivatives when available.
- Preserve originals, attachment IDs, attachment metadata, URLs, thumbnail sizes, and functional `srcset` behavior.
- Support automatic upload processing, resumable bulk work, bounded concurrency (default one worker), retry limits, and WP-CLI.
- Provide settings, dashboard statistics, job failures, and accurate runtime diagnostics.
- Fail safely: no destructive replacement without validated output and recovery; validate file type and path; escape output; check capabilities and nonces.
- Do not install server packages automatically or process images during ordinary frontend requests.
- Defer Cloudflare R2 until a dedicated milestone; leave third-party R2 plugin state untouched.

## Milestone 1 scope

Establish plugin metadata/bootstrap, Composer PSR-4 loading, activation/deactivation, minimal Dashboard and Settings pages, sanitized settings persistence, runtime capability diagnostics, basic automated checks, CI, docs, and installable ZIP packaging. No media processing or fabricated statistics.

## Explicitly deferred

Encoder adapters and image processing, WebP/AVIF delivery, upload hooks, queues and bulk actions, originals backup/restoration, storage adapters, R2 integration, and production hardening beyond the foundation requirements.

## Compatibility targets

WordPress 6.6+, PHP 8.3+, standard Media Library attachments, generated thumbnail sizes, Elementor, and WP Rocket. These compatibility targets need integration testing before release. Linux PHP-FPM is the deployment target; Windows is the development environment.
