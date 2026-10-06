---
name: nvl-support
description: Implement, integrate, test, or review the Support namespace shipped by nvl/core in Laravel 13. Use for shared owner identity, locale catalogs, infrastructure option inheritance, consumer diagnostics, transport-neutral business exceptions, stable response codes, safe error context, or package-family foundation boundaries.
---

# NVL Support

Keep Support capability-neutral, transport-neutral, and independent of other NVL packages.

## Model failures

- Implement `ResponseCode` with stable machine-readable backed values.
- Pass suggested presentation status to `BusinessException` as adapter guidance.
- Throw `BusinessException` when callers need a safe code, message, and public context.
- Preserve the previous exception for internal diagnostics.
- Keep internal diagnostic context separate from serialized public context.
- Treat suggested HTTP status as presentation guidance; controllers or exception handlers own the response.

## Protect the boundary

- Do not add DTOs, pagination, Eloquent models, controllers, routes, migrations, or domain helpers.
- Do not add dependencies on other NVL packages.
- Do not serialize stack traces, SQL, storage paths, tokens, or arbitrary exception context.

## Host injection and fake recording

- Inject existing LocaleCatalog and neutral Tenancy contracts into host orchestration; tag host DoctorContributor implementations for diagnostics. Pure owner identity/configuration APIs stay direct classes. Core owns no domain model factory or fake facade.
- Preserve host defaults: LocaleCatalog is a conditional singleton, TenantContext a conditional scoped service and neutral disabled adapters conditional transients. OwnerRegistry also retains its singleton lifetime with a conditional default. Late replacement affects freshly resolved host services.
- Use runtime Testing\FakeCalls for a fake's exact native method allowlist. Scripts are per-instance, per-method FIFO values or exceptions; Closure values remain inert. Script null for native void. Record attempts before returning, throwing, type failure and exhaustion.
- Predicates for assertCalled receive immutable Testing\FakeCall objects with method and named arguments. Count exact matches, including zero; negative counts and unsupported names raise FakeExpectationFailed, exhausted scripts UnscriptedFakeCall. Both are RuntimeExceptions implementing the pure Contracts\PackageException marker, with no HTTP behavior or testing-framework imports.
- Install leaf fakes in the explicit container before resolving host services. Two installations own separate history/scripts. Keep real authorization, persistence and provider lifecycle coverage; prepare fixtures before effect guards. Do not serialize stored model handles to compare records.

## Verify

Test code and status validation, exception chaining, serialization safety, enum completeness, standalone installation, and architecture constraints.

## Configurable-tenancy release discipline

- Preserve disabled compatibility and package independence; tenant support never creates an undeclared Auth or Suite dependency.
- Explicit package resource keys admit the registered model lineage only on its canonical table and connection. Exact host-model registration remains required; altered SQL sources, unions and persisted adoption still fail without the runtime.
- Use registered package-owned resources, adoption adapters, Actions, and lifecycle APIs. Never add a generic tenant delete-all path or raw cross-package cleanup.
- Treat mapping/configuration hashes, interruption checkpoints, conservation evidence, worker context, tenant-leading queries, and standalone consumption as release contracts.

## Shared owner identity

- Declare owner class lists in `nvl-core.owners`; use Laravel `getMorphClass()` for stored identity. Unmapped models retain their FQCN, and host-authored aliases stay authoritative.
- Keep identity distinct from package authorization, allowlists, resolvers, handlers, scopes, and mutation abilities.
- Core declarations and capability registration never install or enforce host morph maps. Package-owned Page/TemplateVersion mappings are separately collision-checked.
- Report incompatible legacy alias references and stored identity drift through Doctor. Legacy aliases last major 5 only; reviewed host morph-map changes need explicit data reconciliation, never an automatic conversion.
- `Owners\OwnerBatch::fromModels()` bounds input to 100 persisted models, captures native morph/key identities and deduplicates exact pairs. It performs no queries or authorization. Models remain live references; admit the immutable identities through package capabilities, host scopes and tenant boundaries before package SQL.
- `Owners\OwnerResultMap` requires a DTO object for every requested identity and rejects foreign results. Its JSON morph/key maps remain objects, including numeric keys and empty maps; retain `order()` separately.

## Shared diagnostics

- Run `php artisan nvl:doctor --strict --format=json` as the consumer gate over loaded package providers.
- The versioned report uses package/check/severity/result/message fields; errors and strict warnings fail, while optional absent capabilities remain informational unless explicitly enabled.
- Implement and tag `Nvl\Support\Doctor\DoctorContributor` for host-owned read-only checks. Keep package Doctor services authoritative and existing package commands available.

## Shared infrastructure options

- Resolve infrastructure with `Nvl\Support\Config\PackageOptions` and storage with `PackageStorage`. Use canonical package keys `connection`, `tables.*`, `migrations.enabled`, `queue.connection`, `queue.name`, `locks.store`, `routes.*`, and `authorization.guard`.
- Resolve explicit canonical package values before compatibility aliases, Core, and Laravel's effective configuration. A canonical host null inherits Core and still takes precedence over an old alias; empty strings are invalid.
- Normalize only explicit host aliases before merging shipped defaults. Keep lists atomic and deprecation diagnostics serializable for config caching. Report aliases once through Doctor; do not emit warnings per request or rewrite executable host configuration.
- Preserve package migration opt-in defaults, operation-specific lock lifetimes, and job retry settings. Media's mutation, deduplication, and multipart stores may override the shared store independently.
- Apply explicit guards to bare `auth` middleware while retaining existing `auth:guard` selections. Infrastructure inheritance never supplies authorization abilities, callbacks, or capability allowlists.

### Schema ownership and upgrades

Run `nvl:schema:preflight` before the native migration command with the same selected `--path`, `--realpath` and `--database`. It checks the selected pending NVL set without DDL. The automatic `Illuminate\Database\Events\MigrationStarted` listener checks only the exact owned file before its own up/down operation; it cannot promise whole-batch-before-DDL protection. Earlier migrations in plain `migrate --force` may already have executed before a later file is rejected. Pretend and a custom migrator selecting different files require explicit preflight of that actual set.

Preserve the original Laravel or custom migrator object, paths, connection and output; no `PackageMigrator` replacement or subclass requirement is installed. Claim published files only through exact `nvl-core.migrations.published` declarations, canonical identities and released checksums, with an explicit `legacy` history mapping for retimestamped records. `nvl:schema:upgrade --package=media --claim-legacy --migration-owner=vendor --dry-run --format=json` validates the complete owned storage/history plan. Archive verified copies outside loaded paths manually for vendor ownership, or install current package migration code manually and disable vendor loading before `--migration-owner=published`. Never rename old files and leave their obsolete down() code executable. The command rewrites verified history while retaining batches, and never mutates files or host morph values. Native pending/status/rollback uses actual filenames. DDL transaction guarantees depend on the driver and connection.

Public tenant request compatibility goes through `TenantSiteAttributes`; canonical attribute presence wins and both middleware keys restore independently.

### Quarantined native queue retries

Native Laravel 13 `queue:retry` dispatches `Illuminate\Queue\Events\JobRetryRequested` before command restoration. Core checks the event's raw failed-job record and rejects quarantined work at that boundary. Retry captured NVL payloads through `nvl:queue:retry <id...>` after repairing the boundary. Custom retry implementations must provide the same pre-restoration guard, using `TenantQueueQuarantine::beforeNativeRetry()` with the raw event. Preserve original raw bodies, captured attempts/deadlines and failed IDs until transport acceptance.

## Canonical configuration ownership

- Read/write `nvl-core` configuration and publish only canonical `nvl-<package>-<resource>` tags. Keep logical package/tenant resource identifiers unchanged.
- Generic config roots and unprefixed package environment names are foreign by default. For an upgrading NVL host only, select `nvl-core.compatibility.legacy_config` package IDs and `legacy_env` explicitly; both default off. Canonical presence wins, including false/null/empty values. Legacy inputs are read without writing back and are removed in major 6.
- Use canonical `NVL_<PACKAGE>_*` variables only in config evaluation, then rebuild configuration caches and restart workers after cutover. Shared Laravel environment variables retain their names. Consult Core's versioned `support/resources/global-names.json` for all renames.
- Old global aliases and legacy route families require separate explicit `global_aliases`/`legacy_routes` package selections. Preserve collisions and use Doctor diagnostics; never grant generic permissions automatically or claim signed-link compatibility without the same authorization/signature checks.

## Static consumer checks

Use host development PHPStan/Larastan and explicitly include `vendor/nvl/core/support/consumer-audit.neon`. Set host analysis paths separately from `nvlConsumer.testPaths`; only the latter grants model setup queries/writes. Internal APIs, owned tables and host capability relation traversal remain errors in tests. Default and configured physical tables are protected regardless of Suite module switches. Use `nvlConsumer.tableNames` for exact overrides.

Use public DTO projections and declared identity/safe model fields. Do not serialize known package models/items through model, collection, paginator, JSON or inherited Data conversions. Host Filterable/Translatable scopes remain supported. Package path ownership comes from installed catalogs, never the namespace spelling; runtime discovery does not load the rules.

For a reviewed Comments `CommentBatchQueryScope` implementation, bind the host implementation and allow only its supplied builder's exact `Nvl\Comments\Models\Comment::where` predicate with one existing file, `nvl.consumer.packageQuery`, exact symbol and nonempty reason in `nvlConsumer.exceptions`. No interface-wide exemption; writes, escapes and other members/files remain prohibited. Use the Core README example. Normal PHPStan baselines/identifier ignores work and require review. These checks do not prove authorization, dynamic SQL or unknown mixed provenance. Keep runtime Doctor/adoption checks; the Suite consumer command no longer scans source.


## Consumer runtime and testing contracts

Start with the package README Quickstart and Testing your app sections. Use `nvl:install <package>` for loaded-package common config publication; it does not enable features, run schema or refresh caches. Preserve native host owner keys/morph maps and selected auth/tenancy defaults. Read full runtime defaults and publish advanced config only deliberately.

Inject the supported focused interfaces and preserve host bindings. Returned model handles do not permit package-table queries/writes outside documented capability/extension seams. Host tests may substitute contracts in Laravel's container, use shipped model factories (ordinary make may persist parents; withoutParents()->make is detached), and use Laravel effect fakes deliberately. Only Media/Stripe have dedicated provider/library fakes; do not invent a universal package fake. Settings InteractsWithSettings is definition-only. Host PHPStan may include vendor/nvl/core/support/consumer-audit.neon; no unpublished workbench command is a consumer requirement.

Read docs/events.md and the package README error table. Domain events use schemaVersion=1, model-free facts and actual source-connection commit callbacks; only six declared old Event suffix aliases remain for major 5. Migrate exact listeners/fakes and suffix wildcards, drain old queued payloads, rebuild event cache and restart workers. Delivery is not a durable outbox. The Core exception renderer is opt-in, JSON-only for respondable failures, with exactly message/code/context and host-selected locale. Do not expose diagnostics or reinterpret missing bindings as authorization denial.

Core package logging uses nvl/normal with CSV quiet by default, stable message keys and bounded context; incidents survive quiet. Do not mutate global logger context or log raw row/provider/content/credential payloads. Run only authorized project checks and report new acceptance as pending until actual output exists.
