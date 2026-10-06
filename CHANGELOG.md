# Changelog

## Unreleased — consumer runtime integration

- Added focused consumer contract/testing guidance and shipped-factory usage limits.
- Versioned committed event payloads and documented canonical aliases, source connections, failure metadata and optional safe rendering.
- Added explicit first-use/installer and deployment guidance; new acceptance checks remain pending.


All notable changes to `nvl/core` are documented here. Earlier Support history is retained below; Data history is in `data/CHANGELOG.md`.

## [Unreleased]

### Added

- Add instance-owned FIFO fake recording, immutable named call records and framework-independent script/expectation failures under the runtime Support Testing namespace, with the pure PackageException marker.
- Document injection through existing Core extension contracts and direct testing of pure Data/value APIs.

### Changed

- Preserve a host OwnerRegistry binding with a conditional singleton default while retaining existing locale and neutral Tenancy lifetimes.

- Ship an explicit PHPStan consumer extension with five stable identifiers, exact host options, inferred model/capability/table enforcement and catalog-aware result cache invalidation. Runtime discovery remains independent of PHPStan.

- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Add bounded native owner batches and complete object-valued result maps for authorized package readers; these helpers perform no queries or authorization.
- Admit same-storage model lineage through explicit disabled-tenancy resources while retaining exact host registration and storage/adoption guards.
- Prepare lockstep major 5 with required and development NVL peer floors of `^5.0`. This candidate has not been tagged or published.
- Use canonical package config and env inputs with explicit one-major compatibility and collision diagnostics.
- Preserve the host migrator; add exact migration ownership, explicit schema preflight and published-file reconciliation.
- Validate queue metadata before restoring commands, quarantine rejected payloads and expose raw retry.
- Declare owner capabilities through Laravel morph identity and make global Data configuration adoption explicit.
- Review [UPGRADING.md](UPGRADING.md) before adopting the new names and infrastructure boundaries.

## [2.2.1] - 2026-09-26

### Documentation

- Clarify public support, contribution, and private security reporting paths.

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
