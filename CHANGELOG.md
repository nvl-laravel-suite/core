# Changelog

All notable changes to `nvl/core` are documented here. Earlier Support history is retained below; Data history is in `data/CHANGELOG.md`.

## [Unreleased]

## [2.2.0] - 2026-09-25

### Changed

- Combine Support and Data in the independently published `nvl/core` package while retaining both PHP namespaces and service providers.
- Expose both Support and Data skills at the package root for Laravel Boost discovery while retaining their individual publish tags.

## [2.0.1] - 2026-09-22

### Changed

- Released unchanged under the suite's shared version.

## [2.0.0] - 2026-08-29

### Changed

- Kept `BusinessException` and `ResponseCode` as the package's model-free Suite
  2.0 consumer boundary.

## [1.0.7] - 2026-08-22

### Changed

- Aligned the documented runtime requirement with the PHP 8.4+ package
  baseline.

## [1.0.5] - 2026-08-12

### Changed

- Corrected the historical v1.0.0 release date and classified its already
  shipped support contracts under that stable release.

## [1.0.0] - 2026-08-08

- Added transport-neutral `BusinessException` and `ResponseCode`.
- Added stable machine codes, suggested presentation statuses, safe public context, internal diagnostics, and exception chaining.
- Removed DTO, TypeScript registry, pagination, domain, route, model, and persistence responsibilities.
- Enforced backed response-code implementations directly through the `ResponseCode` contract.
- Strengthened standalone boundaries, response-code validation coverage, publication checks, and architecture verification.

<!-- tenancy-program-p2 -->
Configurable-tenancy implementation and adoption documentation are present. The final consolidated verification matrix is pending; do not treat this package as release-ready until that gate passes.
