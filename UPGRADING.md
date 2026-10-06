# Upgrading NVL Core

## Major 5: batched owner input

Package many-owner readers consume the new `Nvl\Support\Owners` helpers. Supply a
finite list of at most 100 persisted models and use each reader's authorization
contract. Core captures native morph/key identity but performs no database reads or
authorization. Reader admission must use the immutable captured identities, even
if the caller later mutates the retained model references. Results carry object
maps and a separate identity order; callers must not infer list order from JSON
property enumeration.

Core's disabled-tenancy boundary now admits registered model subclasses through
an explicit resource key when they retain its canonical table and connection.
This keeps Taxonomy's registered Tag/Category models usable without the optional
Tenancy provider. Exact host-owner registration, altered SQL-source rejection and
adopted-storage refusal still apply; no data migration or tenancy activation is
required.

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

Declare owner classes in `nvl-core.owners`, for example `'owners' => [Article::class]`, and reference the same model class from each package capability. Laravel's `getMorphClass()` is the stored owner identity: it returns the host-authored morph alias or the FQCN when no map exists. Core declarations and package allowlists do not add or enforce a host morph map and do not grant authorization.

Legacy alias references remain read compatibility during major 5 and are removed in major 6. A legacy configured alias must agree with the model's current `getMorphClass()`; mismatches are diagnostics and require a host decision. Doctor can inspect declared package owner columns for stored-versus-current identities without rewriting them. If the host introduces or changes its morph map, review and convert only the affected stored columns and reconcile host relationships before cutover. No automatic owner-data conversion or `nvl:owners:upgrade` is provided. Rebuild configuration caches and restart workers after the coordinated change.

## Shared locale catalog cutover

Replace raw package locale reads with `Nvl\Support\Contracts\LocaleCatalog`. Configure `nvl-core.locales.supported`, `default`, and `fallback` or bind the contract in the host. Standalone Core defaults use the distinct valid application locale and fallback; explicit empty fallback lists are preserved. Translatable's configured adapter remains authoritative when installed, and host implementations are preserved.

Move `nvl-primitives.locales.supported` to the canonical catalog during this major cycle. Legacy configuration remains accepted only by the standalone default and is reported by `php artisan nvl:doctor`; conflicting catalogs require an explicit host decision. Existing published Translatable lists, defaults, and fallbacks continue to apply. Fresh Translatable defaults inherit Core/application locales instead of adding `en` and `bg`.

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
2. Install the new code with package migration loading disabled. Select exactly one migration owner: vendor or published files.
3. Declare each claimed published file by exact absolute path in `nvl-core.migrations.published`, with `package` (logical slug), `migration` (canonical manifest name), and optional `legacy` (exact recorded filename without `.php`). Retimestamped historical records require that explicit `legacy` mapping. A matching basename, directory, checksum alone, or modified host file is never automatically claimed.
4. Review `php artisan nvl:schema:upgrade --package=settings --claim-legacy --migration-owner=vendor --dry-run --format=json`. The plan lists exact claimed files, ownership actions, table renames and history changes. Existing shape, keys and creator records must establish ownership; unrelated host data remains untouched.
5. For **vendor ownership**, manually archive verified published copies outside every loaded executable migration path and update their declarations to the archived exact paths. The command refuses execution while a declared copy remains in a loaded path. Generate a fresh dry run, then apply it. History aligns to the canonical vendor filenames while retaining batches.
6. For **published ownership**, replace each claimed executable file with current package migration code first and declare its actual filename, keeping vendor `migrations.enabled=false`. Review with `--migration-owner=published`. History aligns to the actual published native filenames while retaining batches. Renaming old files alone is insufficient: their old `down()` implementations can target legacy tables.
7. Apply the reviewed selection without `--dry-run`. Re-enable only your selected owner. Run `nvl:schema:preflight` with the same intended paths and repository connection, stop on failure, then run native Laravel migration/status/rollback commands. Rerun Doctor before resuming writes.

`nvl:schema:upgrade` never moves, rewrites, deletes or implicitly claims host migration files. Modified or unclaimed code requires host reconciliation before NVL can verify it. Duplicate declarations, history conflicts, incomplete installations and conflicting targets fail before changes. Schema-qualified rename destinations require an explicit host schema move first. The command does not import domain data.

Core preserves the host migrator object, including subclasses, selected connection, paths and output. It no longer installs `PackageMigrator` or changes native filename identity. Existing wrapper-based published installations must reconcile both code and history through the selected ownership procedure above before relying on native pending/status/rollback.

**The automatic whole-batch guarantee is lost.** Laravel's batch event does not carry its selected file list, and process-wide included files can contaminate later host-only commands. Core therefore guards exact owned files at `MigrationStarted` and supplies the explicit `nvl:schema:preflight` deployment gate. Plain `migrate --force` can apply earlier migrations before a later owned migration fails. Pretend and custom migration execution require the explicit preflight when the deployment needs a whole selected set checked in advance.

For example, run `php artisan nvl:schema:preflight --path=database/migrations --format=json` before `php artisan migrate --path=database/migrations --force`. Use the same `--database`, multiple `--path` values and `--realpath` choices on both commands. Omitting paths inspects native registered vendor and host migration paths; `--package` restricts that selection to logical NVL package IDs. A custom migrator that changes its file set must pass that actual set to the explicit gate. Preflight performs no migration DDL and does not claim to load schema dumps or prepare Laravel's migration repository.

### Configuration and capabilities

All stateful packages accept `connection`, `tables.*`, `migrations.enabled`, `queue.connection`, `queue.name`, `locks.store`, `routes.*` and `authorization.*`. Core provides suite defaults for connection, queues, locks, routes and `authorization.guard`. Resolution is explicit canonical option, explicit legacy option, Core default, then Laravel default. A canonical `null` inherits; empty connection/queue/lock/guard names are invalid. Existing operation-specific lock stores and durations retain their semantics. Media's optional operation ledger uses `connections.owner_slot_operations`; null inherits Media/Core connection.

Old configuration spellings remain input aliases for one major release. Doctor reports explicit deprecated inputs and conflicts; a shipped default is not reported as a host override. Replace those keys before the following major.

Declare owner classes in `nvl-core.owners`, for example `'owners' => [Article::class]`, and reference the same model class from each package capability. Laravel's `getMorphClass()` is the stored owner identity: it returns the host-authored morph alias or the FQCN when no map exists. Core declarations and package allowlists do not add or enforce a host morph map and do not grant authorization.

Legacy alias references remain read compatibility during major 5 and are removed in major 6. A legacy configured alias must agree with the model's current `getMorphClass()`; mismatches are diagnostics and require a host decision. Doctor can inspect declared package owner columns for stored-versus-current identities without rewriting them. If the host introduces or changes its morph map, review and convert only the affected stored columns and reconcile host relationships before cutover. No automatic owner-data conversion or `nvl:owners:upgrade` is provided. Rebuild configuration caches and restart workers after the coordinated change.

Use Core's `LocaleCatalog` for supported/default/fallback locales. Its application adapter reads Laravel locale settings, and a loaded Translatable provider supplies the full catalog. Per-resource locale and fallback overrides remain supported. `nvl-primitives.locales` is deprecated for one major.

Tasks' Media/Activity, Comments' Media, Pages' Metafields and Mail Notifications' Settings adapters now activate from loaded providers. Each integration switch accepts null (automatic), false (disabled) or true (required, fail if unavailable). Explicitly require optional packages and register their providers if discovery is disabled. Existing undelivered Task activity stays in its outbox until an Activity publisher is available.

Neutral tenancy contracts and values now live in Core, with safe single-tenant implementations. Old `Nvl\Tenancy` neutral names forward for one major. Ordinary packages no longer require the Tenancy runtime; enabled tenancy or already-adopted storage still requires the real runtime and cannot fall back to unrestricted access. Billing retains its necessary Tenancy dependency.

Public tenant request attributes use the Core `TenantSiteContext` class key. The deprecated Tenancy class key remains readable for one major when the canonical key is absent. Canonical presence wins, including an invalid or null value, so conflicting legacy data cannot bypass validation. Public middleware writes and restores both keys independently.

Use `php artisan nvl:doctor --strict --format=json` as the shared deploy/CI gate. Package commands remain available. Media queue work inherits Core or Laravel's effective connection and queue instead of defaulting to synchronous execution.

## Major 5: canonical configuration and environment

Packages that ship configuration publish canonical `config/nvl-<package>.php` files under `nvl-<package>` roots. Core ships both `nvl-core.php` and `nvl-data.php`; CSV and Filterable configure behavior through typed APIs and have no package config file. The provider-free `nvl/laravel-suite` metapackage has no configuration of its own. Logical package IDs and tenant resource IDs retain their existing spellings; `PackageStorage::table('media', 'assets')` still uses the logical package ID. Do not rename Laravel's inherited `DB_*`, `QUEUE_CONNECTION`, cache, mail or filesystem inputs.

| Former NVL config root | Canonical root |
| --- | --- |
| `activity` | `nvl-activity` |
| `billing` | `nvl-billing` |
| `comments` | `nvl-comments` |
| `content` | `nvl-content` |
| `forms` | `nvl-forms` |
| `mail-notifications` | `nvl-mail-notifications` |
| `media` | `nvl-media` |
| `metafields` | `nvl-metafields` |
| `pages` | `nvl-pages` |
| `payments` | `nvl-payments` |
| `primitives` | `nvl-primitives` |
| `seo` | `nvl-seo` |
| `settings` | `nvl-settings` |
| `tasks` | `nvl-tasks` |
| `taxonomy` | `nvl-taxonomy` |
| `templates` | `nvl-templates` |
| `tenancy` | `nvl-tenancy` |
| `translatable` | `nvl-translatable` |
| `translations` | `nvl-translations` |

`nvl-auth`, `nvl-core`, `nvl-data` and `nvl-suite` were already canonical. The complete 120 package-owned environment renames are in [the versioned global names inventory](support/resources/global-names.json): each `env` entry maps its canonical name to its former name. For example, use `NVL_MEDIA_QUEUE`, `NVL_MEDIA_QUEUE_CONNECTION`, `NVL_MAIL_NOTIFICATIONS_ENABLED` and `NVL_TENANCY_ENABLED`.

Compatibility is off by default. For an existing NVL installation only, explicitly select old config inputs in `nvl-core.compatibility.legacy_config`, for example `['media', 'settings']`, and enable `nvl-core.compatibility.legacy_env` (or `NVL_CORE_LEGACY_ENV=true`) while moving old environment variables. Fresh installations leave both off. The old generic config roots are read only when selected and are never populated or written back. Empty or unrelated foreign roots remain untouched. Recognizable old NVL options with no canonical root appear in Doctor so an upgrade does not silently use defaults.

Canonical values win by presence, including `false`, `null`, `''` and empty arrays. Within a package's infrastructure resolver, a preserved canonical null can intentionally inherit Core/Laravel defaults, while invalid empty connection/queue/lock/guard names still fail validation. Canonical environment variables win whenever set, including false or empty values. Legacy fallbacks run only during config evaluation; runtime code reads configuration. Replace aliases before major 6, rebuild configuration caches and restart long-running workers.

Global aliases and legacy URLs use separate selected package lists: `nvl-core.compatibility.global_aliases` and `legacy_routes`. Both default to `[]`; collisions preserve host registrations and are reported without values. Permission bridges require an explicit mapping and do not automatically accept old generic grants. Review the package-specific URL and signed-link cutover before rebuilding route caches.


## Queue envelope and retained handler cutover

### Compose retained queue handlers explicitly

Enabled Tenancy preserves an existing host `CallQueuedHandler` binding. Adapt it
to Core's `TenantQueueHandler` contract: `validate()` must admit captured metadata
and the inert command/model graph without restoring user objects. Both `call()`
and `failed()` must revalidate before command restoration. Extending the supplied
`TenantCallQueuedHandler` and preserving all three methods supplies this adapter.
Core invokes `validate()` at `JobProcessing`, before native execution or terminal
failure handling. Carried-envelope mismatches, wrong-owner model identifiers and
other admission failures enter raw quarantine, so native retry cannot restore
the rejected command. Admitted commands that fail in `handle()` keep native
failure handling. Ordinary host payloads without NVL metadata retain their handling.
Tenancy Doctor requires these integrations when the runtime is enabled or a
declared resource has adopted storage; disabled legacy storage passes with
retained host handlers and batch repositories.

### Retry quarantined NVL jobs through raw transport

Rejected NVL envelopes appear in the host's native failed-job store with
`NVL queue envelope rejected:` in their boundary exception. Laravel's native
`queue:retry` restores the command before it requeues it. Installed Laravel 13
dispatches `JobRetryRequested` before that restoration, so Core rejects identified
quarantine records at this event for native ID, `all`, queue and range selections.
These records must use the raw retry command. Ordinary failed
host jobs retain native retry behavior.

Configure persistent native failed-job storage for this inspection and retry
path. If that storage is disabled or unavailable, the rejected job is still
deleted to prevent native failure callbacks, and a storage error is raised;
there is no durable quarantine record for that attempt.

After repairing the runtime or envelope boundary, use
`php artisan nvl:queue:retry <failed-id...>` for these records. It requeues the
original raw body and lets the worker validate it again; it preserves captured
`retryUntil` and payload attempts. The native failed-job ID is forgotten only
after the transport confirms the push. An expired deadline still expires.
Sync queues and transports with command-dependent options, including native
SQS `getQueueableOptions()`, require an explicit raw retry integration. Custom
retry commands must apply Core's `TenantQueueQuarantine::beforeNativeRetry()`
raw-record preflight before any restoration. Commands omitting Laravel's event
require that explicit integration. Recheck event ordering when upgrading Laravel.
