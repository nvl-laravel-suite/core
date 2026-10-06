# NVL Data — API and usage

[← NVL Core](../README.md)

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/core:^2.0` |
| Package identifier | `nvl/core` |
| PHP namespace | `Nvl\Data` |
| Service provider | `Nvl\Data\Providers\DataServiceProvider` |
| Configuration | Packaged default: `data/config/nvl-data.php`; optional application copy: `config/nvl-data.php` |

## Purpose

`nvl/core` provides the package family's sole DTO and PHP-to-TypeScript boundary for Laravel 13 on PHP 8.4+. It standardizes Spatie Data persistence transforms, pagination, deterministic source registration, declaration generation, integrity manifests, stale checks, and protected artifact delivery.

It has no internal NVL dependency. It does not own Eloquent models, database transactions, domain validation policy, content localization, or frontend build tooling.

NVL generation uses isolated TypeScript configuration. Global Spatie transformer configuration remains host-owned unless `nvl-data.typescript.adopt_global_config` is explicitly `true`; its default is `false`. The compatibility `configure_transformer` switch alone does not activate global adoption. Doctor reports the active adoption.

## Requirements and installation

```bash
composer require nvl/core:^2.0
```

Laravel auto-discovers `DataServiceProvider`. Optional publish tags are:

```bash
php artisan vendor:publish --tag=nvl-data-config
php artisan vendor:publish --tag=nvl-data-skills
php artisan vendor:publish --tag=nvl-data-generated-types-tooling
```

The existing `php artisan vendor:publish --tag=nvl-data-config` command remains
a compatible alias. The configuration
also participates in Laravel's conventional `config` publish group, so
`php artisan vendor:publish --tag=config` includes `nvl-data.php`.

Configuration uses `config/nvl-data.php` and cannot collide with Spatie Laravel Data's `data.php`.

## DTO and persistence transforms

Use Spatie `Data` for public, command, action, event, and HTTP boundaries. Add `DataTransform` when a DTO maps to persistence:

```php
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class UpdateArticlePayload extends Data
{
    use DataTransform;

    public function __construct(
        public string|Optional $title,
        public string|Optional|null $summary,
    ) {}
}
```

- `toArray()` returns the mapped public shape.
- `toModel()` recursively normalizes keys, backed enums, dates, nested DTOs, and collections.
- `toModelFiltered()` omits `Optional` and `null` values for create/default semantics.
- `toModelPatch()` omits only `Optional` and preserves explicit `null` clears.

`Optional` means omitted. `null` means explicitly empty. Do not collapse those states in mutation DTOs.

Keep display and mutation DTOs separate. Infrastructure objects that gain no validation, transport, or TypeScript value do not need to extend Data.

## Pagination

`PaginatedCollection::fromPaginator()` converts a length-aware paginator and item Data class into:

```json
{
  "items": [],
  "meta": {
    "currentPage": 1,
    "lastPage": 1,
    "perPage": 20,
    "total": 0
  }
}
```

This is the stable package-family pagination envelope. Pagination belongs to Core's Data namespace, not its Support namespace.

## Configure TypeScript sources

```php
'typescript' => [
    'configure_transformer' => true,
    'allowed_roots' => [
        base_path(),
    ],
    'source_paths' => [
        app_path('Data'),
        base_path('src/Contracts'),
    ],
    'output_directory' => resource_path('js/types'),
    'output_file' => 'generated.d.ts',
    'manifest_file' => 'generated.manifest.json',
    'enum_union_types' => true,
    'writer' => 'split',
    'split_directory' => 'generated',
    'scope_mappings' => [
        'Modules\Auth\Invitations' => 'users',
        'Modules\Auth\Permissions' => 'users',
        'Modules\Auth\Roles' => 'users',
        'Modules\Auth\Users' => 'users',
    ],
    'model_type' => 'any',
    'readonly_properties' => false,
    'type_replacements' => [
        App\Data\DateTimeValue::class => 'string',
        Symfony\Component\HttpFoundation\File\UploadedFile::class => 'File',
    ],
    'memory_limit' => '1G',
    'max_source_files' => 50_000,
    'max_generated_files' => 2_000,
    'max_generated_bytes' => 100 * 1024 * 1024,
    'max_manifest_bytes' => 5 * 1024 * 1024,
],
```

Every source and output must resolve inside an allowed root. Invalid roots, traversal, missing source directories, and symlink escape fail with diagnostics. Publication rejects symlinked paths beneath the output directory, including declarations and both manifests, before replacing any artifacts. Use regular files and directories for generated output.

The default split writer groups `Modules\{Module}\*` and `Nvl\{Package}\*` symbols into stable scope files under `generated/`, then writes `output_file` as the compatibility entrypoint. Use longest-prefix `scope_mappings` for application-specific grouping such as a shared `users` scope. Set `writer=global` only when a consumer explicitly requires one declaration file.

The transformer extracts all eligible Spatie Data classes and backed enums, preserves `Optional`, `DataCollectionOf`, native/Carbon date, Eloquent-model, and mutable-property semantics, and omits `#[Hidden]` contracts. Explicit `#[TypeScript(name: ..., location: ...)]` overrides are applied consistently to classes, Data objects, and enums and reflected exactly in manifest symbols and duplicate-symbol checks. Generation raises memory to the configured floor without lowering a larger or unlimited process limit.

`type_replacements` maps PHP class/interface names to TypeScript expressions.
NVL Data also imports legacy host maps from
`typescript-transformer.default_type_replacements` (including the historical
misspelled `default_type_relacements`) and
`typescript-transformer.type_replacements`; the NVL map has final precedence.
Every map is validated and remains configuration-cache safe.

NVL providers register their own source directory:

```php
use Nvl\Data\Services\TypeScriptSourceRegistry;

app(TypeScriptSourceRegistry::class)->register(
    path: base_path('src/Data'),
    package: 'application/contracts',
    priority: 50,
);
```

Provider keys identify ownership. Duplicate directories owned by conflicting providers fail. `descriptors()` returns the deterministic priority, package, and path explanation used by diagnostics and manifests.

Set `configure_transformer=false` only if the application binds its own Spatie `TypeScriptTransformerConfig`. The source registry and artifact services remain available.

## Generate and verify

```bash
php artisan nvl:data:types:generate
php artisan nvl:data:types:manifest
php artisan nvl:data:types:check
php artisan nvl:data:types:generate --fail-on-warning
php artisan nvl:data:types:check --fail-on-warning
```

`--fail-on-warning` is accepted by `nvl/laravel-suite` 1.0.2 and later. Suite
1.0.1 already rejects transformer warnings but does not expose the flag, so
shared release scripts targeting 1.0.1 must omit it. The option description in
Artisan help records this minimum version. Warning-free generation and checks
remain the safe package default; the flag makes that CI requirement explicit.

Generation sorts providers, paths, symbols, and artifacts. Public symbols use `Nvl.<Package>.*` unless an explicit TypeScript attribute overrides the public name or location. The versioned manifest records source ownership, exact generated source symbols, package and transformer versions, generation time, artifact paths, sizes, and SHA-256 checksums. Its `revision` covers all metadata, while `hash` identifies the declaration artifact set.

`types:check` generates into an isolated temporary directory and fails when committed declarations are stale. Transformer warnings, including unresolved references, fail both generation and freshness checks so a warning cannot publish or validate an incomplete contract. CI must also compile the combined declarations with `tsc --noEmit`.

Split declarations and their integrity metadata are generator-owned. Do not
run ESLint or Prettier over them: formatting output changes their verified
hashes, and the compatibility entrypoint intentionally uses TypeScript
triple-slash path references. Keep exact integrity enforcement in
`nvl:data:types:check` and use these default-path exclusions:

```js
// eslint.config.js
import nvlGeneratedTypes from './nvl-data.eslint.config.js';

export default [
    ...nvlGeneratedTypes,
    // application configuration
];
```

```text
# .prettierignore
resources/js/types/generated.d.ts
resources/js/types/generated/**
resources/js/types/generated.manifest.json
```

The `nvl-data-generated-types-tooling` publish tag provides the ESLint flat
config as `nvl-data.eslint.config.js` and the same Prettier lines in
`.nvl-data.prettierignore` for copying into the host ignore file. Adjust all
three paths together when `output_directory`, `output_file`,
`split_directory`, or `manifest_file` differs from the defaults.

## Generated-type HTTP routes

Routes are disabled by default and never generate during a request:

```php
'routes' => [
    'enabled' => false,
    'prefix' => 'api/v1/nvl/types',
    'middleware' => ['web', 'auth', 'throttle:60,1'],
    'cache_control' => 'private, no-store',
    'headers_prefix' => 'NVL',
    'archive_enabled' => true,
    'archive_name' => 'generated-types',
    'archive_max_bytes' => 25 * 1024 * 1024,
    'archive_max_files' => 1_000,
],
```

When enabled, the API serves only manifest-listed declaration artifacts:

- manifest/catalog metadata;
- the declaration entrypoint;
- named supplemental declaration scopes;
- a bounded streamed archive when archive support is available.

Paths are normalized and checked against traversal and symlink escape. Responses support ETags and conditional requests: the catalog ETag uses the full manifest revision, while declaration and archive ETags use their content hash. Keep private/no-store caching when authorization is present. Generated declarations may reveal internal contracts, so do not enable public delivery by accident.

To mirror an existing application endpoint contract, configure its route `prefix`, `output_file`, `archive_name`, and `headers_prefix`, then apply the application's required route middleware.

## Failure and deployment behavior

The provider is safe during package discovery, config caching, route caching, and console boot. It does not require a database. Generation belongs in build or deployment, never request handling.

`nvl:data:types:generate` serializes concurrent builds, transforms into an isolated staging directory, and publishes generated files plus their integrity manifest behind a short reader/writer lock. Failed publication restores the prior artifact set. HTTP requests read the persisted manifest rather than rescanning PHP source trees. A request that overlaps the short publication boundary returns retryable `503` with `Retry-After` instead of waiting behind filesystem I/O.

A failed transform does not write a successful manifest. Stale, unlisted, checksum-invalid, oversized, or symlink-escaped artifacts are not served. Temporary generation, archive, and check directories are removed after use.

## Extension points

- `TypeScriptSourceRegistry` for package and application sources
- validated PHP-to-TypeScript replacement maps through NVL or legacy host configuration
- Spatie transformers and collectors through the host configuration
- configured artifact roots, file names, route middleware, and archive bounds

Source collisions must be resolved at registration; output collisions must be resolved before release.

## Verification

Package tests cover transforms, pagination, Laravel 13 provider boot, config caching, multiple providers, invalid paths, symlinks, deterministic output, stale checks, manifest integrity, archive bounds, ETags, and route protection. Combined CI generation compiles all installed package declarations.

See [UPGRADING.md](UPGRADING.md), [SECURITY.md](SECURITY.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE).
