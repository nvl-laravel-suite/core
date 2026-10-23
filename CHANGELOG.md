# Changelog


All notable changes to `nvl/core` are documented here. Earlier Support history is retained below; Data history is in `data/CHANGELOG.md`.

## [Unreleased]

## [6.1.0] - 2026-10-23

<!-- NVL GENERATED CHANGES START -->
### Changed

- expose safe host APIs and harden package boundaries
<!-- NVL GENERATED CHANGES END -->

## [6.0.1] - 2026-10-21

### Changed

- Share configured-model registration and queue-envelope capture across domain packages. Isolate retained legacy model stamping and immutable-field checks in the ownership compatibility layer while preserving existing 6.x contracts.

## [6.0.0] - 2026-10-20

### Release fixes

- Apply the existing collision boundary to validated model-replacement morph registration and inventory Ownership publication tags.
- Exercise major 6 package archives in the offline consumer-boundary installation proof.

### Changed

- Add neutral ownership contracts, configurable validated model subclasses, canonical morph identity, queue admission before restoration, and durable guards that deny access when the enforcing runtime is disabled or removed.
- Align required/development NVL peers on `^6.0`, optional peer conflicts on major 6, and `dev-main` on `6.x-dev`. Review [UPGRADING.md](UPGRADING.md) before rollout.

## [5.0.1] - 2026-10-08

### Changed

- Correct published-family verification and adoption guidance for local Dagger CI; runtime contracts are unchanged.


## [5.0.0] - 2026-10-08

### Added

- Add instance-owned FIFO fake recording, immutable named call records and framework-independent script/expectation failures under the runtime Support Testing namespace, with the pure PackageException marker.
- Document injection through existing Core extension contracts and direct testing of pure Data/value APIs.

### Changed

- Preserve a host OwnerRegistry binding with a conditional singleton default while retaining existing locale and neutral Tenancy lifetimes.

- Ship an explicit PHPStan consumer extension with five stable identifiers, exact host options, inferred model/capability/table enforcement and catalog-aware result cache invalidation. Runtime discovery remains independent of PHPStan.

- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Add bounded native owner batches and complete object-valued result maps for authorized package readers; these helpers perform no queries or authorization.
- Admit same-storage model lineage through explicit disabled-tenancy resources while retaining exact host registration and storage/adoption guards.
- Adopt lockstep major 5 with required and development NVL peer floors of `^5.0`.
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
