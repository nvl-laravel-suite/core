# NVL Core — API and usage

## Quickstart

```sh
composer require nvl/core:^5.0
php artisan nvl:install core --dry-run
php artisan nvl:install core
```

Core combines Support and Data. Configure supported locales and native owner capabilities without replacing host locale/auth/tenant state.
Review the published common config, select one migration owner, and run schema preflight before existing-table upgrades. The installer does not enable features or run migrations. Follow the detailed installation and capability sections below before invoking a storage/provider operation.

Inject `Nvl\Support\Contracts\LocaleCatalog` in a host service. After supplying the trusted inputs described above, the first public call is:

```php
use Nvl\Support\Contracts\LocaleCatalog;

/** @var LocaleCatalog $capability */
$result = $capability->supported();
```

Use the [event catalog](docs/events.md) and [Testing your app](#testing-your-app) below. The suite [getting-started guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/getting-started.md) provides a complete Comments host fixture; package archives retain their own local references.


[← NVL Laravel Suite](https://github.com/nvl-laravel-suite)

For support, [open an issue](https://github.com/nvl-laravel-suite/core/issues). For vulnerabilities, use
[private reporting](https://github.com/nvl-laravel-suite/core/security/advisories/new). See [Contributing](CONTRIBUTING.md).

See the [installation and publishing guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/installation.md) for Composer setup, configuration, migration ownership, and agent skills.

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/core:^5.0` |
| Package identifier | `nvl/core` |
| PHP namespaces | `Nvl\Support`, `Nvl\Data` |
| Service providers | `Nvl\Support\Providers\SupportServiceProvider`, `Nvl\Data\Providers\DataServiceProvider`, `Nvl\Support\Providers\LocaleServiceProvider` (deferrable) |
| Configuration | Packaged default: `data/config/nvl-data.php`; optional application copy: `config/nvl-data.php` |

## Purpose

`nvl/core` combines the Support and Data foundations in one Composer package. It provides transport-neutral business exceptions, stable response-code contracts, and shared package configuration merging for Laravel 12–13 on PHP 8.4+.

The Support namespace provides transport-neutral contracts and exceptions. The Data namespace provides Spatie Data transforms, pagination, TypeScript source registration, and declaration generation. Core has no internal NVL dependency. See the [Data API and usage](data/README.md) for Data configuration and commands.

## Requirements and installation

```bash
composer require nvl/core:^5.0
```

Laravel auto-discovers the Support, Data, and deferrable Locale providers. The Locale provider registers when `LocaleCatalog` is first resolved. Defaults work without publishing configuration. Publish only what the application needs:

```bash
php artisan vendor:publish --tag=nvl-core-translations
php artisan vendor:publish --tag=nvl-core-config
php artisan vendor:publish --tag=nvl-core-skills
php artisan vendor:publish --tag=nvl-data-skills
php artisan vendor:publish --tag=nvl-data-generated-types-tooling
```

`nvl-core-config` publishes both `config/nvl-core.php` and `config/nvl-data.php`;
`php artisan vendor:publish --tag=nvl-data-config` publishes only the Data file. The generated-types tooling tag copies optional
ESLint and Prettier fragments. The skill tags publish
`.agents/skills/nvl-support` and `.agents/skills/nvl-data`. Laravel Boost can
also discover the bundled skills during `boost:install` or
`boost:update --discover` after adding Core to an existing application.

## Define a stable response code

Response codes are backed enums implementing `ResponseCode`:

```php
use Nvl\Support\Contracts\ResponseCode;

enum AccountResponseCode: string implements ResponseCode
{
    case Locked = 'account.locked';
}
```

The backed value is the stable machine code. Renaming an enum case does not change the public contract; changing its backed value does.

## Throw a transport-neutral failure

```php
use Nvl\Support\Exceptions\BusinessException;

throw new BusinessException(
    message: 'The account is locked.',
    responseCode: AccountResponseCode::Locked,
    suggestedStatus: 423,
    publicContext: ['retryable' => false],
    diagnosticContext: ['rule' => 'failed-attempt-limit'],
);
```

The exception exposes:

- `responseCode()`: the backed machine code or `null`;
- `suggestedStatus()`: presentation guidance from 100 through 599;
- `publicContext()`: data a consumer adapter may serialize;
- `context()`: internal diagnostic data for logging and reporting;
- the standard previous-exception chain.

`BusinessException` does not render HTTP, JSON, CLI, or queue responses. The consuming application maps it to the appropriate transport and may choose a different presentation status.

## Context safety

Only deliberately safe scalar or structured values belong in `publicContext`. Do not include:

- credentials, tokens, or secrets;
- stack traces or exception objects;
- SQL or database connection information;
- storage paths;
- unredacted personal data;
- arbitrary model serialization.

Diagnostic context is never automatically safe to expose. Applications must keep it on trusted reporting and logging paths.

## Failure behavior

Constructing an exception with a suggested status outside 100–599 throws `SupportException`. A missing response code is valid for failures that have no stable public machine contract. The original exception can be retained through `previous`.

`Nvl\Support\Exceptions\SupportException` and the `TenantNotFound` failure documented by `Nvl\Support\Tenancy\Contracts\TenantDirectory::find()` are supported consumer failure types. Catch `Nvl\Support\Tenancy\Exceptions\TenantNotFound` for an unknown tenant; its implementation parent `TenancyException` and internal response-code enum are not separate consumer contracts.

## Consumer catalog discovery

Call `Nvl\Support\Consumer\ConsumerApiCatalog::installed()` explicitly to inspect supported symbols, model-handle declarations and default table ownership from installed package catalogs. Discovery reads Composer installation metadata and validated JSON without booting Laravel, providers, database connections or sibling packages. Providers do not activate discovery automatically. Every installed NVL code library must ship a matching catalog; missing or invalid catalogs fail with regeneration or upgrade guidance. Protocol-1 maps use JSON objects, including `{}` when empty, and member/permission lists use JSON arrays, including `[]` when empty.

Each `psr4` prefix accepts one relative source directory or an ordered nonempty list of directories. A symbol file must match its longest declared prefix and its exact source root. Duplicate roots or selected symbol files across those roots are rejected.

## Non-goals

- HTTP exception rendering
- validation response construction
- models, persistence, and migrations
- consumer-specific error catalogs

Pagination belongs to the `Nvl\Data` namespace in Core. Domain response codes belong to the package or application that owns the behavior.

## Verification

The Support tests cover standalone boundaries and discovery, backed response-code enforcement, status boundaries, public and diagnostic context separation, serialization safety, exception chaining, skill publication, and architecture constraints. Release checks run Pest, Pint, PHPStan at maximum strictness, Composer validation, dependency analysis, and distribution validation.

See [Data documentation](data/README.md), [upgrading](UPGRADING.md), [security](SECURITY.md), [contributing](CONTRIBUTING.md), and [changelog](CHANGELOG.md).

## Opt-in PHPStan consumer boundary

Install PHPStan and Larastan as host development tools, then include Core's shipped extension explicitly:

```bash
composer require --dev phpstan/phpstan:^2.2.5 larastan/larastan:^3.10
```

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/nvl/core/support/consumer-audit.neon
parameters:
    paths:
        - app
        - Modules
        - database/migrations
        - tests
    nvlConsumer:
        testPaths:
            - tests
        tableNames:
            tenant_comments: nvl/comments
        exceptions: []
```

List only directories that exist in your host. Run `vendor/bin/phpstan analyse`; these rules report errors at every analysis level. The five identifiers are `nvl.consumer.internalApi`, `nvl.consumer.packageQuery`, `nvl.consumer.packageWrite`, `nvl.consumer.capabilityRelation`, and `nvl.consumer.ownedTable`. Normal PHPStan identifier ignores and generated baselines work, but prefer a reviewed, narrow exception for intentional adapters. PHPStan 2.2.5 with Larastan 3.10.0 is the verified development profile; release compatibility matrices remain separate gates.

The policy reads installed JSON catalogs and Composer filesystem ownership. A forged `Nvl\\` namespace grants nothing. Catalog bytes, versions/references, ordered source roots and normalized host options participate in PHPStan's normal result cache; missing or invalid catalogs fail closed. Runtime package discovery neither registers nor loads the rule classes and needs no PHPStan installation.

Explicit `testPaths` permit package model query/write setup, including factory persistence, only under canonical listed directories. Internal types, package capability relations and raw owned-table access remain prohibited. `tableNames` adds exact physical names mapped to an installed package; default ownership remains enforced even if a Suite module is disabled. Both literal reads and writes, including migration `Schema` calls, are errors. This replaces the workbench's older advisory raw-read classifications.

Model handles allow only catalogued identity methods and safe in-memory fields, including the same fields through array access and literal collection projections. Refresh/loading, persistence, package relation traversal, collection/paginator serialization and `json_encode` of known models/items are rejected. Inherited Data `from`/`collect` cannot turn known models/items into a projection; array/value DTO construction and DTO serialization remain supported. Host-only queries, public Filterable/Translatable scopes and documented taxonomy predicates remain supported. Static literal host `$taxonomies` declarations supply dynamic relation names without instantiating the host model.

For a reviewed C1 Comments SQL adapter, bind your implementation of `CommentBatchQueryScope` through the host container:

```php
$this->app->bind(\Nvl\Comments\Contracts\CommentBatchQueryScope::class, \App\Policies\VisibleCommentPolicy::class);
```

Implement both SQL scoping methods and `authorizeOwners` according to the host authorization policy. Only the supplied `Builder<Comment>` read predicate needs the exact exception below; implementing the interface alone grants no query access:

```neon
parameters:
    nvlConsumer:
        exceptions:
            -
                file: app/Policies/VisibleCommentPolicy.php
                identifier: nvl.consumer.packageQuery
                symbol: 'Nvl\Comments\Models\Comment::where'
                reason: 'Reviewed C1 SQL visibility predicate on the package-supplied builder.'
```

The file must exist; paths and symbols cannot contain patterns or traversal. Match the exact identifier, canonical symbol spelling and one file. Writes, query-builder escapes, other members and other files remain errors. Source metadata validates ordinary members and query/write kinds. Synthesized taxonomy capability members cannot all be proven when options are read: an exact exception only suppresses a matching diagnostic derived from the actual host declaration; a typo grants nothing.

Static analysis does not prove authorization or cover dynamic SQL, unknown `mixed` receiver provenance, dynamic method/field names, or taxonomy names computed at runtime. Keep package Doctor, schema preflight, authorization and runtime adoption checks. The workbench `nvl:suite:consumer-audit` now covers runtime/adoption wiring only and reports `static_checked: false`; it does not replace this extension.

## Supported PHP usage

The source `@api` declarations identify supported workflows, extension contracts, and value types. Public members marked `@internal` and untagged implementation types remain package-owned. Concrete Actions retain their existing constructors, qualifiers, and `execute()` signatures.

A package model returned or accepted by a public workflow is an identity/result handle. Use its declared type and `getKey()`, `getKeyName()`, `getMorphClass()`, `getRouteKey()`, `getRouteKeyName()`, `is()`, `isNot()`, and `relationLoaded()`. Read only explicitly declared in-memory `@nvl-consumer-read` fields; ordinary model PHPDocs and fillable attributes do not grant consumer reads. Obtain display projections through public reads. Persistence, additional model queries, relation access/loading, and generic model serialization are outside this contract. Host-model queries remain available, while traversal or aggregates of package capability relations require the package public reader or authorized adapter.

## Testing your app

Inject the existing `Nvl\Support\Contracts\LocaleCatalog` or neutral `Nvl\Support\Tenancy\Contracts` interfaces into host services. Locale defaults are conditional singletons; `TenantContext` is scoped and the other disabled tenant adapters retain their conditional transient defaults. Register substitutes before resolving a host service; replacing a binding later affects newly constructed services. The public `OwnerRegistry` default also preserves a host instance or factory while retaining its singleton lifetime. Doctor extensions implement and tag `Nvl\Support\Doctor\DoctorContributor`; Core does not introduce a second diagnostics interface or fake facade.

```php
use Nvl\Support\Contracts\LocaleCatalog;

final readonly class ArticleLocale
{
    public function __construct(private LocaleCatalog $locales) {}

    public function contentLocale(string $requested): string
    {
        return $this->locales->assertSupported($requested);
    }
}

$catalog = Mockery::mock(LocaleCatalog::class);
$catalog->shouldReceive('assertSupported')->once()->with('bg')->andReturn('bg');
$this->app->instance(LocaleCatalog::class, $catalog);

expect($this->app->make(ArticleLocale::class)->contentLocale('bg'))->toBe('bg');
```

Pure owner identity, configuration and Data value APIs remain directly constructible. Core owns no persistent domain model and supplies no model factory. Construct DTOs in memory; use the owning leaf's schema and fixtures when testing its real persistence. Keep Laravel HTTP, storage, event and queue guards around the host invocation and measure effects after application/fixture setup. Interface substitution does not establish package authorization or lifecycle correctness. See [the opt-in PHPStan configuration](#opt-in-phpstan-consumer-boundary); `vendor/nvl/core/support/consumer-audit.neon` is explicitly included by the host.

`Nvl\Support\Testing\FakeCalls` supports runtime leaf fakes with instance-owned scripts and `list<FakeCall>` records. Each fake declares its exact native method allowlist, installs through its supplied container, and requires a response for every call. `willReturn($method, $value)` and `willThrow($method, $exception)` append FIFO entries for that method; a Closure value is inert, a null script is valid for void, and an exception is rethrown unchanged. Calls are recorded before success, scripted failure, type failure or exhaustion. Records retain named arguments and object handles without serialization; immutable records do not deep-freeze the objects they reference.

`calls($method)` returns matching attempts in invocation order; `calls()` returns all attempts. `assertCalled($method, $predicate, $times)` requires an exact matching count, including zero. The predicate receives the immutable `Nvl\Support\Testing\FakeCall` with public `method` and `arguments`. Negative counts and unsupported names fail with `FakeExpectationFailed`; an exhausted script raises `UnscriptedFakeCall`. Both are ordinary RuntimeExceptions implementing the marker-only `Nvl\Support\Contracts\PackageException`, with no testing-framework or HTTP-rendering dependency. No scripts or call history are stored globally. See the [Payments fake](https://github.com/nvl-laravel-suite/payments#testing-your-app) and [Billing fakes](https://github.com/nvl-laravel-suite/billing#testing-your-app) for concrete consumers.

## Shared owner identity

Publish Core defaults with `php artisan vendor:publish --tag=nvl-core-config`, then declare model classes in `config/nvl-core.php`:

```php
'owners' => [Article::class],
```

Declare owner classes in `nvl-core.owners`, for example `'owners' => [Article::class]`, and reference the same model class from each package capability. Laravel's `getMorphClass()` is the stored owner identity: it returns the host-authored morph alias or the FQCN when no map exists. Core declarations and package allowlists do not add or enforce a host morph map and do not grant authorization.

Legacy alias references remain read compatibility during major 5 and are removed in major 6. A legacy configured alias must agree with the model's current `getMorphClass()`; mismatches are diagnostics and require a host decision. Doctor can inspect declared package owner columns for stored-versus-current identities without rewriting them. If the host introduces or changes its morph map, review and convert only the affected stored columns and reconcile host relationships before cutover. No automatic owner-data conversion or `nvl:owners:upgrade` is provided. Rebuild configuration caches and restart workers after the coordinated change.

`Nvl\Support\OwnerRegistry` declares capabilities and resolves classes and their native identities. The deprecated `register($alias, $modelClass)` interface remains for major 5, reports incompatible legacy declarations and never rewrites the host morph map. Package-owned Page and TemplateVersion aliases are separately collision-checked. Keep per-package allowlists, resolvers, scopes and mutation abilities.

## Bounded owner batches

`Nvl\Support\Owners\OwnerBatch::fromModels($owners)` accepts a list of at most
100 persisted Eloquent models. It captures each native morph type and Laravel-cast
scalar key, deduplicates exact pairs in first-request order, and rejects conflicting
concrete classes, connections or raw attributes. Attribute column order is ignored;
attribute values are compared strictly. No queries run in Core's batch helpers.

`identities()` returns immutable snapshots; `owners()` retains live model references.
A package reader must reload and admit the captured identities through its registered
capabilities, host scopes and tenant boundary before reading package storage. A batch
alone grants no authorization and does not verify persistence or tenant membership.

`OwnerResultMap` requires one object value per requested identity and rejects missing
or foreign results. Its JSON type/key levels remain objects, including numeric keys
and an empty `{}` result. `order()` carries the original identity order separately.
These helpers require the prepared Core major 5 artifact.

## Shared content locale catalog

Depend on `Nvl\Support\Contracts\LocaleCatalog` for supported locales, the content default, configured fallbacks, normalization, and deterministic resolution chains. Core provides it without Translatable or Primitives. Its default catalog uses the distinct valid `app.locale` and `app.fallback_locale` values. Configure a standalone content catalog in `nvl-core.php` when content differs from application language defaults:

```php
'locales' => [
    'supported' => ['fr', 'fr-CA', 'en'],
    'default' => 'fr',
    'fallback' => ['en'],
],
```

Null values inherit application defaults; an explicit empty fallback list stays empty. A host can bind its own `LocaleCatalog`. Translatable supplies a validated adapter when installed, with explicit `translatable` catalog values authoritative and null values inheriting Core defaults. Host implementations remain selected across provider order changes.

Use constructor injection for runtime consumers. `Nvl\Support\Facades\Locales` exposes the same contract for declarative model definitions and static validation rules. Shared locale-code normalization preserves canonical regional/script casing such as `zh-Hant-TW`. A chain orders the requested locale, supported parents, explicit resource fallbacks, catalog fallbacks, and the content default without duplicate values.

`nvl-primitives.locales` is deprecated for one major cycle. Its explicit legacy catalog is translated only by the standalone Core default when no canonical catalog is selected. `app(Nvl\Support\Locales\LocaleCatalogDiagnostics::class)->inspect()` and `nvl:doctor` report deprecations and catalog conflicts without changing locale rows.

## Consumer diagnostics

Core provides `php artisan nvl:doctor --strict --format=json` for applications that install individual NVL packages. Loaded package providers contribute their own read-only checks; the command requires neither the suite metapackage nor the workbench. JSON uses `schema_version: 1` and includes package, check key, severity, result (`pass` or `fail`), and an actionable message. Errors fail the command; warnings also fail with `--strict`. Informational optional capabilities do not fail the gate. Invalid formats and contributor exceptions return a nonzero status.

Core also validates effective database, queue, lock-store, and authorization guard names against configured backends, even when no domain package is loaded. Its informational checks expose only those selected names and the queue name. Deprecated configuration inputs produce warnings without including their values.

A host extension implements `Nvl\Support\Doctor\DoctorContributor` and tags its binding with that interface. Contributions are discovered at execution time and sorted deterministically. Existing package Doctor commands remain available; `nvl:suite:doctor` additionally checks workbench module and production requirements.

## Shared infrastructure defaults

Configure common infrastructure in `nvl-core.php` and override only the capabilities that need separate infrastructure:

```php
'connection' => null,
'queue' => ['connection' => 'redis', 'name' => 'nvl-work'],
'locks' => ['store' => 'redis'],
'routes' => ['middleware' => ['api', 'auth']],
'authorization' => ['guard' => 'admin'],
```

`Nvl\Support\Config\PackageOptions` resolves a canonical package option, an explicit compatibility input, Core, then Laravel's effective configured default. Null inherits; an empty string fails validation. A canonical host setting wins over a deprecated alias even when it is null. Queue names inherit the selected queue connection's configured queue. Middleware lists replace whole lists, including intentional empty lists where the capability permits them; an explicitly selected guard applies to bare `auth` middleware and preserves existing `auth:guard` declarations. Authorization abilities and callbacks remain capability-owned.

Package schema options use `connection`, `tables.<logical_key>`, and `migrations.enabled`. Migration opt-in defaults stay package-specific. Lock durations and queue job retry settings also stay operation-specific. Media can choose independent `locks.mutation.store`, `locks.deduplication.store`, and `locks.multipart.store` overrides before its shared `locks.store` fallback.

Compatibility aliases are normalized from the host overlay before package defaults merge. `PackageOptions::deprecations()` reports each original key once with its replacement, effective canonical value before inheritance, and conflict flag. Reports live in serializable configuration and survive config caching. Rebuild configuration caches and restart workers after changing infrastructure.


### Neutral tenant boundaries

Core owns `Nvl\Support\Tenancy` contracts, immutable identifiers and snapshots, queue envelopes, resource definitions, and the resource metadata registry. Packages consume `TenantBoundary`, `TenantContext`, `TenantRunner`, `TenantQueueContext`, `TenantInstallationState`, and `TenantOwnershipConfiguration` contracts from that namespace. Registering a neutral package registers Core; it does not select the enforcing Tenancy provider.

Without `nvl/tenancy`, the disabled boundary preserves validated legacy queries and identity keys and supplies no ownership attributes. Tenant context is disabled, and requiring a tenant or privileged platform execution fails. The actual resource connection is checked for persisted adoption before access. Setting `nvl-tenancy.enabled=true` without the enforcing provider fails, and adopted storage cannot be reopened through disabled defaults. Queue admission runs before native command deserialization and rejects captured tenant work or adopted storage without the runtime.

An explicit resource key admits its registered model lineage on the same canonical table and connection, including Taxonomy's Tag/Category subclasses. Host-owner lookup still requires the exact registered model. Altered query sources, connections, unions and adopted storage fail in both cases.

`nvl/tenancy` is suggested by neutral packages and required for their tenancy test profiles. Select its provider through Laravel discovery or register it explicitly to activate the runtime implementations. Merely having its classes on disk does not register adoption adapters. Billing continues to require the runtime.

## Canonical configuration and deployment preflight

All current package config files and roots use `nvl-<package>`. Old generic roots and unprefixed package environment names are foreign by default. Existing NVL hosts may select `nvl-core.compatibility.legacy_config` package IDs and `legacy_env` while moving to canonical inputs; both default off. Canonical presence wins, including false/null/empty values. Keep logical `PackageStorage` IDs and tenant resource keys unchanged. See [the config/env rename inventory and cutover](UPGRADING.md#major-5-canonical-configuration-and-environment).

Package HTTP routes, view and translation namespaces, middleware, rate limiters, permissions, publication tags, Blade directives, and container aliases use owned NVL names. HTTP APIs default to `/nvl/api/v1/<package>`; Media assets use `/nvl/media`, and SEO files use `/nvl/seo`. Existing host registrations keep their names and paths, including collisions with canonical NVL names, and Doctor reports the conflict. No package resources join the host's generic `config` publication tag.

For major 5 migration only, select logical package IDs in `nvl-core.compatibility.global_aliases` for free legacy aliases and in `nvl-core.compatibility.legacy_routes` for legacy default URLs. Both default to `[]`, preserve occupied host names and paths, and emit strict Doctor warnings. Compatibility routes retain the same controllers, middleware, authorization, and signature checks. Retain legacy private asset URLs until issued signed links expire, then remove the selection, rebuild configuration and route caches, and restart workers. All legacy aliases and routes are removed in major 6. The complete owned-name inventory is `support/resources/global-names.json`.

Run `nvl:schema:preflight` with the same selected paths and repository connection before native migrations. The automatic `MigrationStarted` guard checks an exact owned file before its own operation; it does not promise that an entire batch is checked before earlier DDL. Core preserves native and custom migrator objects. Follow [the owned-storage runbook](UPGRADING.md#existing-package-storage) for exact published declarations and manual vendor/published reconciliation; NVL never edits migration files.

## Testing your app

Inject the supported contract rather than constructing its concrete Action or querying package tables. Replace `Nvl\Support\Contracts\LocaleCatalog` in Laravel's native container for a host-workflow test:

```php
use Nvl\Support\Contracts\LocaleCatalog;

$double = Mockery::mock(LocaleCatalog::class);
$this->app->instance(LocaleCatalog::class, $double);
// Configure the exact supported arguments and documented return value for your host case.
```

The package's conditional native binding preserves host substitutions. Production uses the real contract; test doubles do not prove its storage/authorization behavior.

This package has no persistent fixture model in the supported factory inventory. Test value objects and contract inputs directly; do not invent a package model factory.

Use Laravel `Event::fake()`, `Queue::fake()`, `Mail::fake()` or `Storage::fake()` only for the effects the host test intends to isolate. Use real commits/listeners for timing proof. Add the optional Core consumer boundary rules to host PHPStan:

```neon
includes:
    - vendor/nvl/core/support/consumer-audit.neon
parameters:
    nvlConsumer:
        testPaths: [tests]
        tableNames: []
        exceptions: []
```

Rules read installed public metadata without suite boot. They flag internal symbols, package model queries/writes, capability relations and owned tables; they cannot prove dynamic code or runtime authorization. Exact exceptions require `file`, `identifier`, `symbol`, and a documented `reason`. New C3/C4/E tests, archives and guide execution remain pending until the integration phase records results.

## Error codes and events

All recognized package failures implement `Nvl\Support\Contracts\PackageException`; only `RespondableException` opts into safe response metadata. Keep native PHP programmer errors and Laravel/SDK exceptions distinct. The optional `PackageExceptionRenderer` is registered by the host in `withExceptions`; it leaves unrelated, marker-only and non-JSON handling to the host. Its JSON envelope is `{message:string, code:string, context:object}`. Request locale is host-owned; diagnostics/previous exceptions are not public copy. Event schemas and source connections are documented in [events](docs/events.md).

The table lists enum discriminators, including any successful codes retained for compatibility. A code is not itself an HTTP status; the throwing exception's `suggestedStatus()` is authoritative, especially legacy/custom constructors. Empty context renders as `{}`; only documented JSON-safe context is presented.

| Code | Suggested status | Public context | Translation key |
| --- | --- | --- | --- |
| `operation_failed` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-core::responsecode.operation_failed` |
| `binding_required` | 500 | {} | `nvl-core::responsecode.binding_required` |
| `event_commit_unavailable` | 500 | {} | `nvl-core::responsecode.event_commit_unavailable` |
| `operation_failed` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-core::responsecode.operation_failed` |
| `tenant_context_missing` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-core::responsecode.tenant_context_missing` |
| `tenant_not_found` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-core::responsecode.tenant_not_found` |
| `tenant_inactive` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-core::responsecode.tenant_inactive` |
| `tenant_boundary_violation` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-core::responsecode.tenant_boundary_violation` |
| `tenant_configuration_invalid` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-core::responsecode.tenant_configuration_invalid` |
| `tenant_schema_not_ready` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-core::responsecode.tenant_schema_not_ready` |

### Operational logging

`nvl-core.logging` defaults to channel `nvl`, normal verbosity and a CSV quiet override. Configure package `channel`/`verbosity` overrides under `packages`; verbosity is `quiet`, `normal` or `verbose`. Warnings/errors survive every setting. The absent `nvl` channel becomes a stack of the host default; a configured host channel wins. Do not configure a self-referential stack. Doctor diagnoses missing/cyclic channels without logging to them. Stable `nvl.<package>.<operation>.<result>` keys carry bounded diagnostics, never retained tenant/job context. CSV logs one failed-row warning summary per chunk; row details require verbose mode and contain no raw row values.


## License

Released under the [MIT License](LICENSE).
