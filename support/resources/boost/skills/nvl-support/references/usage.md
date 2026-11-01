# Core consumer recipes

Core publishes the `Nvl\Support` namespace from `nvl/core`. It has no Eloquent domain models or database migrations. It boots safe defaults without Auth, Ownership, Data/TypeScript tooling or an HTTP layer. See [installation](installation.md) for real resource tags and [API](api.md) for full signatures.

## Select the capability

| Need | Public surface | Host decision |
| --- | --- | --- |
| Declare polymorphic capabilities | `Nvl\Support\OwnerRegistry` | Actual model classes and persisted Laravel morph identity |
| Resolve current principal facts | `Nvl\Support\Auth\CanonicalActorResolver` | Authenticate the principal and apply operation policy separately |
| Share content locale policy | `Nvl\Support\Contracts\LocaleCatalog` | Supported/default/fallback locales |
| Read infrastructure defaults | `Nvl\Support\Config\PackageStorage`, `PackageOptions` | Database, queue, lock and logging configuration |
| Publish declared lean configuration | `Nvl\Support\Installation\ConfigPublisher` | Selected installed packages; overwrite only when explicitly intended |
| Inspect required host adapters | `Nvl\Support\Bindings\RequiredBindings` | Bind actual policy/provider implementations before using enabled features |
| Present safe domain failures | `Nvl\Support\Exceptions\BusinessException`, package exception contracts | HTTP/CLI response formatting and status handling |
| Deliver immutable facts after commit | `Nvl\Support\Events\DomainEventDispatcher` | Actual writer connection, event data and listener behavior |
| Declare ownership capability | `Nvl\Support\Ownership\Contracts\Ownable`, `Traits\HasOwnership`, resource registry and boundary/writer/context ports | Exact schema and optional enforcing runtime |
| Capture admitted worker context | `OwnershipQueuedJob`, `CapturesOwnership`, `OwnershipJobEnvelope` in the Support Ownership namespace | Capture during production; native Ownership must re-admit it in the worker |

## Identity registration is not ownership

Set actual host classes in `config/nvl-core.php` before providers boot:

```php
use App\Models\Article;
use App\Models\Company;

return [
    'owners' => [
        'articles' => Article::class,
        'companies' => Company::class,
    ],
];
```

Alternatively call `OwnerRegistry::register($alias, $modelClass)` from the host provider before the resource registry freezes. Use `model($reference)` to resolve a declared capability and `aliasFor($model)` for the actual Laravel morph identity. The registry does not invent a morph map or membership grant. Configure package model replacements through each package's documented key before boot; do not replace a frozen root or rewrite stored aliases casually.

Core's `CanonicalActorResolver::resolve(Authenticatable $actor)` reloads Eloquent actors from canonical writer storage, retaining host scopes and rejecting changed keys/storage or unavailable rows. Non-Eloquent providers retain their own identity/freshness contract. A returned actor is still subject to host policy. `Nvl\Support\Enums\PublicActor::Anonymous` and `SystemActor::Background` distinguish anonymous and background work; neither is a grant and null is not privileged execution.

`SystemActor` implements Laravel `Authorizable` so compatible global Gate hooks can receive explicit background work. Its `can` method evaluates the same explicit enum through the Gate, preserving global hooks and undefined-ability denial. It is not `Authenticatable` and cannot establish a user login. Host hooks for background operations must accept the actual actor contract instead of requiring a concrete application User.

## Locale and shared configuration

Configure `nvl-core.locales.supported`, `default` and `fallback` or bind `LocaleCatalog` before package defaults register. These are content locale rules, not UI/session middleware. The catalog normalizes and validates identifiers and builds a deterministic fallback chain:

```php
use Nvl\Support\Contracts\LocaleCatalog;

$catalog = app(LocaleCatalog::class);
$locale = $catalog->assertSupported('en');
$chain = $catalog->chain($locale);
```

Use the separate `nvl/translatable` content-locale scope for temporary localized operations. Restore ambient state in reused workers; do not retain an admitted owner or actor in a singleton.

`PackageStorage::connection($package)` and `resolveTable($package, $identity)` use the selected package namespace and Core defaults where supported. `PackageOptions` resolves operation-specific queue/lock/guard values. Canonical explicit false/null/empty values retain their documented meaning; no retired config alias is a fallback. Use `nvl-core.connection`, `queue`, `locks`, `authorization`, `logging` and the specific package's options deliberately.

## Publish configuration without an installer framework

Standard Laravel publish tags remain the normal installation route. A host that needs a selected-package configuration workflow can inject `ConfigPublisher`:

```php
use Nvl\Support\Installation\ConfigPublisher;

$plan = app(ConfigPublisher::class)->publish(['nvl/core'], dryRun: true);
$report = app(ConfigPublisher::class)->publish(['nvl/core']);
```

It uses metadata contributed by already loaded providers, preserves existing files unless force is explicitly selected and reports required bindings. It does not discover/enable other packages, register policies, migrate data or adopt ownership. `RequiredBindings::inspect()` reports missing configured capabilities; an actual operation still enforces its own requirements. Core does not supply a generic install or doctor command.

## Safe errors and after-commit facts

Use the owning package's typed failures. `BusinessException` accepts a safe message, stable response code, suggested status, safe public context and internal diagnostics; consult its exact constructor in [API](api.md). Marker-only failures do not opt into transport serialization. Keep SQL, stack traces, credentials, disk paths and diagnostic previous exceptions out of responses. The consumer chooses HTTP/CLI formatting.

An application event may implement the narrow Core `DomainEvent` contract:

```php
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Events\DomainEventDispatcher;

final readonly class RecordChanged implements DomainEvent
{
    public function __construct(public string $recordId) {}

    public function schemaVersion(): int
    {
        return 1;
    }
}

$connection = $record->getConnection();
$connection->transaction(function () use ($record, $connection): void {
    $record->saveOrFail();
    app(DomainEventDispatcher::class)->dispatch(
        new RecordChanged((string) $record->getKey()),
        $connection,
    );
});
```

Here `$record` is an already authorized canonical host model whose input was validated by its owning service. Dispatch uses its actual source connection. An enclosing transaction delays delivery to the outer commit, and rollback discards delivery. This callback boundary is not a crash-durable outbox; use the owning domain's durable delivery/recovery capability when required. Events should contain immutable scalar facts rather than live models or current request authority.

## Optional Ownership and verification

Core's unowned ports support only canonical storage that has not been protected/adopted. Registering an alias alone does not enable isolation. Once storage declares or retains durable ownership, missing `nvl/ownership` must deny protected work. Use native Ownership admission, schema review and adoption for enforcing owner boundaries; do not fake readiness or substitute permissive no-ops.

Test your policy adapter, model identity registration, explicit locale selection, null/omission behavior and outer commit/rollback on the actual connection. A consumer should boot without optional packages and reject explicitly requested unavailable capabilities. Use ordinary Laravel discovery/config-cache checks and the owning package's focused tests; Core adds no HTTP routes or schema/DTO-generation tooling.
