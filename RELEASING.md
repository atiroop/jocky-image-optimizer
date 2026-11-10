# Release management

## Version source and format

The root [`VERSION`](VERSION) file is the single authoritative release version. Use Semantic Versioning:

- Patch: `0.5.1`
- Minor: `0.6.0`
- Major: `1.0.0`
- Prerelease: `0.6.0-beta.1` (then `0.6.0-beta.2`, followed by `0.6.0`)

WordPress reads the literal `Version` plugin-header field and compares it with `version_compare()`. Keep the header, `JIO_VERSION`, README title, and current versioned `CHANGELOG.md` heading synchronized with `VERSION`. Do not change the plugin slug or `jocky-image-optimizer/` directory.

## Prepare a release

1. Create a feature branch from the intended base branch.
2. Update `VERSION`, the plugin header and runtime constant in `jocky-image-optimizer.php`, the README title, and the current release heading and notes in `CHANGELOG.md`.
3. Run `composer install --no-interaction`, `composer lint`, `composer test`, `composer validate --no-check-publish`, and `composer audit --locked`.
4. Build and verify the production ZIP:

   ```sh
   composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
   php scripts/package.php
   composer test:package
   unzip -t "build/jocky-image-optimizer-$(cat VERSION).zip"
   composer install --no-interaction --prefer-dist
   ```

5. Push the feature branch and require every CI job to pass. CI derives its ZIP filename and uploaded artifact name from `VERSION`, installs the exact generated ZIP in disposable WordPress environments, and rejects inconsistent metadata.
6. Review the artifact from the successful workflow. For an actual public release or merge to `main`, obtain the repository owner's authorization separately.

The package helper rejects malformed SemVer and mismatches across the authoritative file, WordPress header, runtime constant, README title, changelog release heading, ZIP metadata, and archive filename. GitHub Actions uses the validated version output to name the artifact. There are no automatic update checks, remote update APIs, or deployment steps in this release process.
