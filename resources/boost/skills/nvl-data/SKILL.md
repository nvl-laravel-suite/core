---
name: nvl-data
description: Implement, integrate, test, or review the Data namespace shipped by nvl/core on PHP 8.3–8.5 and Laravel 13. Use for Spatie Data DTOs, persistence transforms, paginated contracts, deterministic PHP-to-TypeScript generation, source registration, artifact manifests, stale checks, or protected generated-type delivery.
---

# NVL Data

Use this package as the package family's only DTO and PHP-to-TypeScript boundary. Do not move infrastructure-only objects into DTOs when transformation, validation, or transport typing adds no value.

## Define DTOs

- Extend Spatie `Data` and use the `DataTransform` trait where persistence mapping is required.
- Represent pagination with `PaginatedCollection` and `PaginationMeta`.
- Distinguish omitted `Optional` values from explicit `null`.
- Extract all eligible Data classes and backed enums while keeping native/Carbon date, collection, nested DTO, and mutation semantics stable across PHP and TypeScript.
- Publish generated symbols under `Nvl.<Package>.*`.

## Register type sources

- Register package or application sources through `TypeScriptSourceRegistry`.
- Use stable provider keys and priorities; duplicate keys or symbols must fail clearly.
- Keep roots inside configured project boundaries and reject traversal or symlink escape.
- Use regular files and directories beneath the generated output root. Publication preflights declaration and manifest paths and rejects child symlinks before writing artifacts.
- Never add application-specific source paths to package configuration.

## Generate artifacts

- Run `nvl:data:types:generate --fail-on-warning` to create declarations and
  the manifest on suite 1.0.2+; omit the flag on 1.0.1.
- Run `nvl:data:types:check --fail-on-warning` in 1.0.2+ CI to detect stale
  output while making the warning-free requirement explicit.
- Run `nvl:data:types:manifest --write` when only the manifest must be refreshed.
- Treat explicit `#[TypeScript(name: ..., location: ...)]` values as the public symbol contract and fail duplicate public symbols.
- Use the manifest `revision` for catalog synchronization and its artifact `hash` for declaration/archive synchronization.
- Keep HTTP artifact routes disabled by default. Enabled routes serve manifest-listed files only and never generate in a request.
- Configure exceptional PHP references through validated `type_replacements`;
  transformer warnings fail generation and freshness checks.
- Exclude the configured entrypoint, split declaration directory, and integrity
  manifest from ESLint and Prettier. Verify their exact generator-owned content
  with `nvl:data:types:check`; publish `nvl-data-generated-types-tooling` for
  default-path fragments.
- TypeScript Transformer 3.3 removed
  `Spatie\TypeScriptTransformer\Attributes\RecordTypeScriptType`; use
  `LiteralTypeScriptType('Record<string, unknown>')` for dynamic records.

## Testing host applications

- Keep pure public DTO transforms and pagination/value types directly constructible; do not add a fake facade, Data engine interface or Eloquent factory for symmetry.
- Construct declared result DTOs in memory when substituting the owning package's workflow interface in a container-resolved host service. Keep persistence, authorization and real generation tests distinct from host orchestration tests.
- Core's runtime Support Testing\FakeCalls recorder supports leaf fakes with instance-owned FIFO scripts and immutable FakeCall records; assertion predicates receive the record, and Closure result values are inert. Install substitutes before resolving a host service and prepare fixtures before SQL/storage/network guards.
- Explicitly include vendor/nvl/core/support/consumer-audit.neon in host development PHPStan; DTO transforms do not permit generic package model serialization or persistence.

## Verify

Test deterministic ordering, duplicate sources, invalid roots, symlinks, manifests, checksums, ETags, archive limits, combined package generation, DTO transforms, and `tsc --noEmit`.

## Configurable-tenancy release discipline

- Preserve disabled compatibility and package independence; tenant support never creates an undeclared Auth or Suite dependency.
- Use registered package-owned resources, adoption adapters, Actions, and lifecycle APIs. Never add a generic tenant delete-all path or raw cross-package cleanup.
- Treat mapping/configuration hashes, interruption checkpoints, conservation evidence, worker context, tenant-leading queries, and standalone consumption as release contracts.

## Canonical configuration ownership

- Read/write `nvl-data` configuration and publish only canonical `nvl-<package>-<resource>` tags. Keep logical package/tenant resource identifiers unchanged.
- Generic config roots and unprefixed package environment names are foreign by default. For an upgrading NVL host only, select `nvl-core.compatibility.legacy_config` package IDs and `legacy_env` explicitly; both default off. Canonical presence wins, including false/null/empty values. Legacy inputs are read without writing back and are removed in major 6.
- Use canonical `NVL_<PACKAGE>_*` variables only in config evaluation, then rebuild configuration caches and restart workers after cutover. Shared Laravel environment variables retain their names. Consult Core's versioned `support/resources/global-names.json` for all renames.
- Old global aliases and legacy route families require separate explicit `global_aliases`/`legacy_routes` package selections. Preserve collisions and use Doctor diagnostics; never grant generic permissions automatically or claim signed-link compatibility without the same authorization/signature checks.


## Consumer runtime and testing contracts

Start with the package README Quickstart and Testing your app sections. Use `nvl:install <package>` for loaded-package common config publication; it does not enable features, run schema or refresh caches. Preserve native host owner keys/morph maps and selected auth/tenancy defaults. Read full runtime defaults and publish advanced config only deliberately.

Inject the supported focused interfaces and preserve host bindings. Returned model handles do not permit package-table queries/writes outside documented capability/extension seams. Host tests may substitute contracts in Laravel's container, use shipped model factories (ordinary make may persist parents; withoutParents()->make is detached), and use Laravel effect fakes deliberately. Only Media/Stripe have dedicated provider/library fakes; do not invent a universal package fake. Settings InteractsWithSettings is definition-only. Host PHPStan may include vendor/nvl/core/support/consumer-audit.neon; no unpublished workbench command is a consumer requirement.

Read docs/events.md and the package README error table. Domain events use schemaVersion=1, model-free facts and actual source-connection commit callbacks; only six declared old Event suffix aliases remain for major 5. Migrate exact listeners/fakes and suffix wildcards, drain old queued payloads, rebuild event cache and restart workers. Delivery is not a durable outbox. The Core exception renderer is opt-in, JSON-only for respondable failures, with exactly message/code/context and host-selected locale. Do not expose diagnostics or reinterpret missing bindings as authorization denial.

Core package logging uses nvl/normal with CSV quiet by default, stable message keys and bounded context; incidents survive quiet. Do not mutate global logger context or log raw row/provider/content/credential payloads. Run only authorized project checks and report new acceptance as pending until actual output exists.
