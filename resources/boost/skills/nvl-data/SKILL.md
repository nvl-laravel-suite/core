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
