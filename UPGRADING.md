# Upgrading NVL Core

## Moving to Core 2.0

Install `nvl/core` in place of `nvl/support` and `nvl/data`. The `Nvl\Support` and `Nvl\Data` PHP namespaces and their service providers remain available from Core.

## Historical Support 1.0 guidance

Version 1.0 was a deliberately small foundation. The following steps describe the former split packages; Core's Data and Support namespaces now provide both capabilities.

1. Move paginated DTOs to the `Nvl\Data` namespace provided by `nvl/core`.
2. Register TypeScript sources with Core's Data provider.
3. Keep HTTP response creation in the application exception handler.
4. Use the exception status only as a suggested presentation status.
5. Separate public exception context from internal diagnostics.
6. Remove consumer helpers, models, controllers, routes, and migrations from Support integrations.

## Shared owner identity cutover

Declare canonical morph identities under `nvl-core.owners` and update capability definitions to reference them. Package allowlists, resolvers, handlers, visibility, translation policies, and authorization remain mandatory. Duplicate identical identities are idempotent; conflicting identity declarations or host morph maps fail.

Legacy model inputs remain accepted for one major cycle. Compatibility registration preserves the package's established write-time storage: existing Content, Taxonomy, and Metafields aliases remain mapped, while historical class-backed inputs do not create new aliases automatically. Existing host aliases remain authoritative. Core diagnostics identify deprecated host inputs without changing rows.

A new canonical alias changes Laravel morph writes for its model. Convert verified FQCN-backed rows explicitly before introducing that alias and reconcile all affected package and host relationships. No automatic data conversion or `nvl:owners:upgrade` command is included. Keep existing aliases where possible; never rewrite unrelated values or host-owned morph tables as part of a package conversion. Rebuild configuration caches and restart workers after the coordinated cutover.

## Shared locale catalog cutover

Replace raw package locale reads with `Nvl\Support\Contracts\LocaleCatalog`. Configure `nvl-core.locales.supported`, `default`, and `fallback` or bind the contract in the host. Standalone Core defaults use the distinct valid application locale and fallback; explicit empty fallback lists are preserved. Translatable's configured adapter remains authoritative when installed, and host implementations are preserved.

Move `primitives.locales.supported` to the canonical catalog during this major cycle. Legacy configuration remains accepted only by the standalone default and is reported by `php artisan nvl:doctor`; conflicting catalogs require an explicit host decision. Existing published Translatable lists, defaults, and fallbacks continue to apply. Fresh Translatable defaults inherit Core/application locales instead of adding `en` and `bg`.

Review narrowed resource locales and explicit resource fallbacks against the selected catalog. Exact-only reads and intentional empty translated values remain unchanged. No locale rows are converted automatically. After configuration changes, rebuild configuration caches and restart long-running workers.

## Additive consumer Doctor

Core now registers `nvl:doctor --strict --format=json`. Existing package and workbench Doctor commands remain available. The report is versioned independently through `schema_version`; error checks fail ordinary runs and failed warnings fail strict runs. Package contributors execute services directly rather than parsing command output. No storage conversion is required.

Configure the selected database, queue, cache, and authorization guard backends before running the shared gate. Core validates these names without opening connections and reports deprecated aliases without disclosing configured values.

## Infrastructure option cutover

Move shared connection, queue connection/name, lock store, middleware, and guard defaults to `nvl-core`. Canonical package overrides use `connection`, `tables.*`, `migrations.enabled`, `queue.connection`, `queue.name`, `locks.store`, `routes.*`, and `authorization.guard`. Preserve operation-specific retry/timeout settings, lock durations, and Media's independent operation stores. Package migration opt-in flags are unchanged.

Historical nested storage settings, physical table keys, Templates rendering queue settings, old lock stores, management route settings, and root Auth guard inputs remain supported for one major cycle. Core normalizes explicit host aliases before defaults merge and reports conflicting canonical inputs through Doctor. Canonical settings take precedence, including null values that inherit Core. Apply mappings explicitly, rebuild `config:cache`, and restart workers; executable host PHP configuration is never rewritten automatically.


### Core tenancy contract migration

Replace imports for neutral tenant contracts, identifiers, snapshots, resource definitions, enums, and queue envelopes with `Nvl\Support\Tenancy`. Replace runtime service injection with the matching Core contract, for example `Nvl\Support\Tenancy\Contracts\TenantBoundary`. The enforcing services remain in `Nvl\Tenancy\Services` and implement those contracts. Public neutral classes from the old namespace have deprecated aliases for one major release; new serialized envelopes use the Core namespace. Drain queued jobs and batches before deploying the namespace transition and restart retained workers.

Neutral packages no longer require the tenancy runtime in production. Keep `nvl/tenancy` installed and register its provider whenever tenant enforcement or adopted data is used. Removing or disabling the runtime does not remove adoption markers or turn adopted resources into legacy storage. Resource metadata is registered even without the runtime, so validation and persisted adoption checks remain active. Adoption probes are cached only within the current application scope, keyed by the actual Laravel connection; authorized schema changes must invalidate installation state, and retained workers must restart.

This change does not rewrite tenant-owned rows or installation markers. Existing enabled deployments retain the runtime directory, boundary, queue and adoption behavior.

## Next major: brownfield composition

This release changes package schema identities. Run the upgrade before accepting application writes. The shared interfaces live in `nvl/core` (`Nvl\Support`); no additional package is required.

### Existing package storage

1. Back up the package storage and Laravel migration repository. Pause workers and application writes using those tables.
2. Install the new code with package migration loading disabled. Keep one migration owner: vendor migrations or published copies. Loading both is rejected before DDL.
3. Run `php artisan nvl:doctor --strict --format=json`. Configure canonical package `connection` and `tables.<logical-key>` options, plus Core defaults in `nvl-core.php`. Remove explicit old default table mappings when selecting the new names; deliberately retained old mappings keep those tables in place.
4. Inspect `php artisan nvl:schema:upgrade --package=forms --package=media --claim-legacy --dry-run --format=json`, selecting only packages whose legacy storage you own.
5. Apply the identical selection without `--dry-run`. Re-enable your chosen migration owner, run `php artisan migrate`, rerun Doctor, then resume writes.

`--claim-legacy` is an explicit ownership assertion. The command also requires matching creator history and the released column, primary/unique/index and foreign-key contracts; a familiar table or filename alone is insufficient. Incomplete multi-table installations, duplicate targets and conflicting records fail before any writes. Unrelated host rows, batches, table contents and constraint names are preserved. Schema-qualified rename destinations require an explicit host schema move first. The command does not create importers or adopt host domain data.

Laravel republishes migrations with new timestamps. An unmodified published migration is recognized by its released checksum and maps to the exact canonical vendor identity in the repository. Its sole-owner published path remains usable for migrate/status/rollback through the current package migration implementation. Modified host files retain their own identity and need an explicit host-owned upgrade; NVL never rewrites migration files. Remove a verified duplicate copy or disable vendor loading before using both paths together.

Core preserves the standard Laravel migrator's selected connection, migration paths and console output. A host migrator subclass must extend `Nvl\Support\Schema\PackageMigrator`; custom batch overrides must call `parent::runPending()` before their migration execution. Unsupported subclasses fail with an upgrade instruction instead of silently losing host behavior.

Transactions cover renames and history updates when the database supports transactional DDL. Separate database connections have separate transactions; MySQL/MariaDB DDL cannot provide an all-or-nothing transaction. The dry-run JSON reports those limits. After a failure, inspect completed steps and rerun the command; verified renamed storage is resumable. A second successful run has no steps.

### Configuration and capabilities

All stateful packages accept `connection`, `tables.*`, `migrations.enabled`, `queue.connection`, `queue.name`, `locks.store`, `routes.*` and `authorization.*`. Core provides suite defaults for connection, queues, locks, routes and `authorization.guard`. Resolution is explicit canonical option, explicit legacy option, Core default, then Laravel default. A canonical `null` inherits; empty connection/queue/lock/guard names are invalid. Existing operation-specific lock stores and durations retain their semantics. Media's optional operation ledger uses `connections.owner_slot_operations`; null inherits Media/Core connection.

Old configuration spellings remain input aliases for one major release. Doctor reports explicit deprecated inputs and conflicts; a shipped default is not reported as a host override. Replace those keys before the following major.

Declare owner identities once in `nvl-core.owners` by morph alias. Package capabilities reference aliases and retain their own slots, abilities and definitions. Old class-based declarations remain deprecated inputs for one major. Existing stored morph values are not rewritten; any persisted-alias conversion belongs to an explicit host migration.

Use Core's `LocaleCatalog` for supported/default/fallback locales. Its application adapter reads Laravel locale settings, and a loaded Translatable provider supplies the full catalog. Per-resource locale and fallback overrides remain supported. `primitives.locales` is deprecated for one major.

Tasks' Media/Activity, Comments' Media, Pages' Metafields and Mail Notifications' Settings adapters now activate from loaded providers. Each integration switch accepts null (automatic), false (disabled) or true (required, fail if unavailable). Explicitly require optional packages and register their providers if discovery is disabled. Existing undelivered Task activity stays in its outbox until an Activity publisher is available.

Neutral tenancy contracts and values now live in Core, with safe single-tenant implementations. Old `Nvl\Tenancy` neutral names forward for one major. Ordinary packages no longer require the Tenancy runtime; enabled tenancy or already-adopted storage still requires the real runtime and cannot fall back to unrestricted access. Billing retains its necessary Tenancy dependency.

Public tenant request attributes use the Core `TenantSiteContext` class key. The deprecated Tenancy class key remains readable for one major when the canonical key is absent. Canonical presence wins, including an invalid or null value, so conflicting legacy data cannot bypass validation. Public middleware writes and restores both keys independently.

Use `php artisan nvl:doctor --strict --format=json` as the shared deploy/CI gate. Package commands remain available. Media queue work inherits Core or Laravel's effective connection and queue instead of defaulting to synchronous execution.
