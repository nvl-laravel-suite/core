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

## Verify

Test code and status validation, exception chaining, serialization safety, enum completeness, standalone installation, and architecture constraints.

## Configurable-tenancy release discipline

- Preserve disabled compatibility and package independence; tenant support never creates an undeclared Auth or Suite dependency.
- Use registered package-owned resources, adoption adapters, Actions, and lifecycle APIs. Never add a generic tenant delete-all path or raw cross-package cleanup.
- Treat mapping/configuration hashes, interruption checkpoints, conservation evidence, worker context, tenant-leading queries, and standalone consumption as release contracts.

## Shared owner identity

- Declare canonical aliases in `nvl-core.owners` and resolve identity through `Nvl\Support\OwnerRegistry`. Identical registrations are idempotent; conflicting aliases, duplicate canonical model aliases, and incompatible host morph maps fail before use.
- Keep identity distinct from package authorization, allowlists, resolvers, handlers, scopes, and mutation abilities.
- Accept deprecated host class inputs for one major cycle, report them through Doctor, and preserve the package's established morph-write behavior.
- Do not automatically create aliases for historical FQCN-backed inputs or globally enforce morph maps on unrelated host models. Introducing a canonical alias requires an explicit, reviewed stored-morph conversion and host relationship reconciliation.

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

Core's guarded migrator validates the entire pending batch before DDL. Canonical `tables.*` names use the full package prefix; resolve connections through PackageStorage, including Media's separate owner-slot ledger. `nvl:schema:upgrade` requires explicit packages and `--claim-legacy`, validates released relational keys and creator history, offers `--dry-run --format=json`, and preserves unrelated history/batches. Verified published copies map to exact canonical identities and current package migration code. Duplicate vendor/published owners are rejected. Modified host copies remain host-owned. Transactions are driver dependent and per connection; no automatic stored morph rewrite or importer is provided.

Preserve the standard migrator state when installing the preflight. Host subclasses must extend `PackageMigrator` and retain `parent::runPending()` in custom execution; never replace an unknown host migrator silently. Public tenant request compatibility goes through `TenantSiteAttributes`; canonical attribute presence wins and both middleware keys restore independently.
