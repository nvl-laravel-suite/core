# Changelog


All notable changes to `nvl/core` are documented here. Earlier Support history is retained below; history of the former `nvl/data` package is kept only in the source repository and is not distributed with this package.

## [Unreleased]

## [7.0.7] - 2026-11-12

### Fixed

- Record the unscoped Templates schema and recognize the original published 7.0.2 and 7.0.3 document migrations.

<!-- NVL GENERATED CHANGES START -->
### Changed

- recover and isolate Templates ownership migrations
<!-- NVL GENERATED CHANGES END -->

## [7.0.6] - 2026-11-11

### Fixed

- Construct each owned canonical model once per resource registry instead of on every unclaimed-model admission. Unowned queries, saves, refreshes, translation admission and mail notifiable checks no longer rebuild the complete owned inventory per call. Preserve base-class and shared-storage refusal; canonical tables and connections are still read on every admission, so no storage fact is cached.

### Changed

- Probe the ownership protocol catalog once per connection within a request or job instead of before every query and write on a package model. With no enforcing runtime enabled, each read ran two statements and each write up to four; later operations in the same request now run alone. The result lives in a scoped service, a reconnected connection is probed again, and refusals are never remembered, so storage adopted by another process is refused from the next request or job rather than the next query. Queue publication and job admission still probe on every call.

### Fixed

- Keep Eloquent's scope-free builder writes inside the ownership boundary. Builder-level `forceDelete()`, `updateOrInsert()` and `updateFrom()` run on the base query without global scopes, so on an ownership-declaring model they could delete or update another owner's rows, or run with no admitted owner at all. Such models now receive a builder that applies the ownership boundary to these writes, with caller predicates grouped first; a model that would receive the plain framework builder is refused. Custom Eloquent builders of ownership-declaring models must use the new `ScopesOwnedBuilderWrites` trait to receive the same guarantee. A builder whose caller explicitly removed the ownership scope, and `truncate()`, remain native.
- Allow native `queue:retry` for ordinary failed work that carries only an inert disabled ownership envelope while no enforcing runtime is enabled (Core-only hosts, or `nvl/ownership` installed with `nvl-ownership.enabled` false). Such work was refused and pointed at `nvl:queue:retry`, a command those hosts do not have, so it could never be retried. Quarantined records, retired or malformed metadata and owner, central or unowned runtime captures are still refused before command restoration, as is every captured record while the runtime is enabled.
- Supply `nvl:queue:retry` from Core. `OwnershipQueueQuarantine` records rejected work on Core-only workers, but the only command able to requeue those records lived in `nvl/ownership`, so a Core-only host could never retry them. The command moved to `Nvl\Support\Console\Commands\QueueQuarantineRetryCommand`, is registered by `SupportServiceProvider` in console, and the native `queue:retry` refusal no longer points at another package.
- Report required host bindings in `nvl:doctor` again. `RequiredBindingsDoctor` lost its registration with the logging check, so an enabled Billing or Payments capability still bound to its deny placeholder was never reported; it is an error again, and dormant or on-use requirements remain informational.
- Report the package logging policy in `nvl:doctor` again. The `logging.policy` check lost its registration when the Doctor became an opt-in provider, so invalid `nvl-core.logging` channels, verbosity and stack cycles were never reported.
- Resolve declared owners once per unchanged input in `OwnerRegistry`. `model()`, `aliasFor()`, `all()` and `errors()` constructed every declared owner model and sorted the result on each call, several times per owned admission. The result is recomputed whenever a declaration or replacement, the raw `nvl-core.owners` value, Laravel's morph map or its morph-map requirement differs.
- Find persisted ownership markers through PostgreSQL's effective `search_path`. With a path such as `"$user", public` whose first schema does not exist, the disabled-runtime probe looked for the marker table only in that schema, found none and treated prepared or adopted storage as unadopted.
- Restore queued pivots that use `HasOwnership` through Laravel's own restoration query. A pivot serialized by its composite identity (`foreign:1:related:2`, and the morph type for a morph pivot) was looked up by primary key and never found.
- Reject a trailing line break in `LocaleCode::isValid()`, and point the deprecated `nvl-primitives.locales` Doctor warning at `nvl-core.locales.supported`, the key Core's catalog reads.
- Stop revalidating the frozen ownership resource graph on every admission, and check a new registration for cycles only through that resource instead of from every registered resource. Refusals are unchanged.
- Find persisted ownership markers in the database a MySQL or MariaDB connection is actually attached to. The disabled-runtime probe looked in the database named by the connection's configuration, so a connection attached to a different source reported no markers and unowned access was admitted.

<!-- NVL GENERATED CHANGES START -->
### Changed

- construct owned canonical models once for unclaimed admission
- scope builder writes to the owner and probe storage once per request
<!-- NVL GENERATED CHANGES END -->

## [7.0.5] - 2026-11-10

### Fixed

- Match persisted ownership markers once per actual source during batched resource or table admission, avoiding repeated inventory scans while preserving malformed-marker refusal, resource-or-table matching, fresh write-source facts and physical partition checks.

<!-- NVL GENERATED CHANGES START -->
### Changed

- scan marker inventories once per source
<!-- NVL GENERATED CHANGES END -->

## [7.0.4] - 2026-11-10

### Fixed

- Inspect each actual connection once per captured disabled admission while preserving resource-or-table marker refusal and native partition checks. Refresh all facts for each later or nested admission; retain write-source reads and refuse malformed marker identities consistently.

<!-- NVL GENERATED CHANGES START -->
### Changed

- batch fresh disabled publication storage checks
<!-- NVL GENERATED CHANGES END -->

## [7.0.3] - 2026-11-09

### Fixed

- Preserve native integer deletion results for Laravel `Pivot` and `MorphPivot` models, including cancelled deletion, through the existing nullable-boolean Ownership persistence port.
- Remove the duplicate storage probe during unowned model creation while retaining canonical query validation and fresh adoption checks for each operation.

<!-- NVL GENERATED CHANGES START -->
### Changed

- preserve native pivot deletion and unowned guards
<!-- NVL GENERATED CHANGES END -->

## [7.0.2] - 2026-11-05

### Fixed

- Require patched HTTP Foundation, HTTP Kernel and Routing versions and raise the Filesystem development-tool minimum to exclude the Symfony security advisories disclosed on November 4, 2026.

<!-- NVL GENERATED CHANGES START -->
### Changed

- require patched Symfony security floors
<!-- NVL GENERATED CHANGES END -->

## [7.0.1] - 2026-11-04

<!-- NVL GENERATED CHANGES START -->
### Changed

- diagnose retained template owner identities
<!-- NVL GENERATED CHANGES END -->

## [7.0.0] - 2026-11-03

- Adopt the reviewed NVL 7 family for PHP 8.4+ and Laravel 13, with compatible required and optional NVL peers. Review the package upgrade guide before deployment.

### Changed

- Align NVL peers and consumer contracts on the coordinated 7.x line. Review [UPGRADING.md](UPGRADING.md) for adoption and application-owned integration requirements.
- Require Laravel 13.30 and patched dependency minimums so lowest-version runtime resolutions exclude known security advisories.
- Keep explicit background actors compatible with Laravel `Authorizable` Gate hooks without creating an authenticated identity or granting permissions; correct installer and canonical event-listener guidance.

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
