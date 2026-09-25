# Changelog

This records changes to the Data component of `nvl/core`, including its history as the separate `nvl/data` package.

## [Unreleased]

- Publish the Data namespace through `nvl/core` while retaining its existing PHP API.

## [2.0.1] - 2026-09-22

### Fixed

- Reject symlinked declaration directories, declaration files, and manifests
  before generated TypeScript publication writes or replaces any artifacts.

## [2.0.0] - 2026-08-29

### Changed

- Kept DTO transforms and generated TypeScript services as the package's
  model-free Suite 2.0 consumer boundary and verified them in the sealed
  upgrade rehearsal.

## [1.0.7] - 2026-08-22

### Changed

- Aligned the documented runtime requirement with the PHP 8.4+ package
  baseline.

## [1.0.5] - 2026-08-12

### Changed

- Released unchanged under the suite's shared version.

## [1.0.2] - 2026-08-12

- Added the versioned `--fail-on-warning` generation/check CLI contract while
  retaining warning-free output as the default.
- Added publishable ESLint flat-config and Prettier-ignore fragments for every
  default split declaration and integrity-manifest path.
- Added the canonical `data-config` tag while retaining `nvl-data-config` and
  Laravel's shared `config` group as compatible aliases.
## [1.0.1] - 2026-08-09

- Added validated PHP-to-TypeScript replacement maps with legacy host-map
  compatibility and explicit configuration precedence.
- Made transformer warnings fail generation and freshness checks before
  incomplete declarations can be published or accepted.
- Added staged, serialized TypeScript generation with rollback-safe publication.
- Added split namespace declaration output, configurable scope mappings, and application-compatible Data extraction.
- Bound HTTP delivery to a persisted integrity manifest with checksum, file-count, size, symlink, and archive limits.
- Added recursive Optional filtering, nesting limits, and normalized-key collision detection for model transforms.
- Added exact TypeScript name/location discovery, duplicate public-symbol checks, and manifest-wide revisions.
- Made request-time publication locks fail fast with retryable responses and bounded manifest writes.
- Rejected empty delivery middleware and generated-artifact path collisions before publication.
- Normalized ZIP entry timestamps so content-addressed archives remain byte-stable.
- Added `nvl-data.php` to Laravel's conventional `config` publish group.
- Removed the obsolete `RecordTypeScriptType` compatibility processor.

## [1.0.0] - 2026-08-08

- Added the package-family DTO and persistence-transform boundary.
- Added typed pagination DTOs and deterministic source registration.
- Added generated TypeScript manifests, stale checks, integrity checks, and protected artifact delivery.
- Standardized generated declarations under `Nvl.*`.
