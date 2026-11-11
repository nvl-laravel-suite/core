# Public API: nvl/core

Generated from the package public contract. Use this reference to confirm fully qualified names, parameter order, defaults, native array shapes and return types. Read [usage](usage.md) for policy and operation semantics; declarations alone never grant authority.

Search for the relevant fully qualified service or contract and read only its matching symbol sections; this is a lookup catalog, not an instruction to load every declaration. `resolved` annotations expand imported aliases and domain type shapes. Framework inheritance and host-authored extensions remain outside this package contract.

## Symbols

- `Nvl\Support\Auth\CanonicalActorResolver`
- `Nvl\Support\Bindings\RequiredBindingDefinition`
- `Nvl\Support\Bindings\RequiredBindingStatus`
- `Nvl\Support\Bindings\RequiredBindings`
- `Nvl\Support\Config\PackageOptions`
- `Nvl\Support\Contracts\DomainEvent`
- `Nvl\Support\Contracts\LocaleCatalog`
- `Nvl\Support\Contracts\PackageException`
- `Nvl\Support\Contracts\RespondableException`
- `Nvl\Support\Contracts\ResponseCode`
- `Nvl\Support\Doctor\DoctorCheck`
- `Nvl\Support\Doctor\DoctorContributor`
- `Nvl\Support\Doctor\RequiredBindingsDoctor`
- `Nvl\Support\Enums\CoreResponseCode`
- `Nvl\Support\Enums\PublicActor`
- `Nvl\Support\Enums\SystemActor`
- `Nvl\Support\Events\ConnectionCommitCallbacks`
- `Nvl\Support\Events\DomainEventDispatcher`
- `Nvl\Support\Exceptions\BindingRequiredException`
- `Nvl\Support\Exceptions\BusinessException`
- `Nvl\Support\Exceptions\EventCommitRegistrationException`
- `Nvl\Support\Exceptions\ExceptionResponse`
- `Nvl\Support\Exceptions\SupportException`
- `Nvl\Support\Facades\Locales`
- `Nvl\Support\Facades\PackageLog`
- `Nvl\Support\Installation\ConfigPublisher`
- `Nvl\Support\Installation\InstallReport`
- `Nvl\Support\Installation\InstallationContributor`
- `Nvl\Support\Installation\InstallationRegistry`
- `Nvl\Support\Installation\PackageInstallation`
- `Nvl\Support\Locales\LocaleCatalogDiagnostics`
- `Nvl\Support\Logging\PackageLogger`
- `Nvl\Support\Models\ConfiguredModelResolver`
- `Nvl\Support\OwnerRegistry`
- `Nvl\Support\Owners\OwnerBatch`
- `Nvl\Support\Owners\OwnerIdentity`
- `Nvl\Support\Owners\OwnerResultMap`
- `Nvl\Support\Ownership\Contracts\Ownable`
- `Nvl\Support\Ownership\Contracts\OwnershipBoundary`
- `Nvl\Support\Ownership\Contracts\OwnershipContext`
- `Nvl\Support\Ownership\Contracts\OwnershipContextParticipant`
- `Nvl\Support\Ownership\Contracts\OwnershipQueueContext`
- `Nvl\Support\Ownership\Contracts\OwnershipQueueHandler`
- `Nvl\Support\Ownership\Contracts\OwnershipQueuedJob`
- `Nvl\Support\Ownership\Contracts\OwnershipSystemRunner`
- `Nvl\Support\Ownership\Enums\OwnershipContextMode`
- `Nvl\Support\Ownership\Enums\OwnershipResponseCode`
- `Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid`
- `Nvl\Support\Ownership\Exceptions\OwnershipException`
- `Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady`
- `Nvl\Support\Ownership\Exceptions\OwnershipViolation`
- `Nvl\Support\Ownership\Services\OwnerSiteContext`
- `Nvl\Support\Ownership\Services\OwnershipContextParticipants`
- `Nvl\Support\Ownership\Services\OwnershipResourceRegistry`
- `Nvl\Support\Ownership\Traits\CapturesOwnership`
- `Nvl\Support\Ownership\Traits\HasOwnership`
- `Nvl\Support\Ownership\Traits\ScopesOwnedBuilderWrites`
- `Nvl\Support\Ownership\ValueObjects\OwnerReference`
- `Nvl\Support\Ownership\ValueObjects\OwnershipDefinition`
- `Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope`
- `Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition`
- `Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot`
- `Nvl\Support\Ownership\ValueObjects\VerifiedOwnerSiteContext`
- `Nvl\Support\Testing\FakeCall`
- `Nvl\Support\Testing\FakeCalls`
- `Nvl\Support\Testing\FakeExpectationFailed`
- `Nvl\Support\Testing\UnscriptedFakeCall`
- `Nvl\Support\Traits\InteractsWithPackageFailure`

## Nvl\Support\Auth\CanonicalActorResolver

```text
final readonly class Nvl\Support\Auth\CanonicalActorResolver
public function resolve(Illuminate\Contracts\Auth\Authenticatable $actor, bool $lock=false): Illuminate\Contracts\Auth\Authenticatable
```

## Nvl\Support\Bindings\RequiredBindingDefinition

```text
final readonly class Nvl\Support\Bindings\RequiredBindingDefinition
public function __construct(string $package, string $contract, string $placeholder, string $capability, ?string $enabledWhen, string $documentation): mixed
@param string $contract
@param string $placeholder
public readonly string $capability
public readonly string $contract
@param string $contract
public readonly string $documentation
public readonly ?string $enabledWhen
public readonly string $package
public readonly string $placeholder
@param string $placeholder
```

## Nvl\Support\Bindings\RequiredBindingStatus

```text
final readonly class Nvl\Support\Bindings\RequiredBindingStatus
public function __construct(string $package, string $contract, string $capability, string $status, string $message, string $documentation): mixed
@param string $status
public readonly string $capability
public readonly string $contract
public readonly string $documentation
public readonly string $message
public readonly string $package
public readonly string $status
@param string $status
```

## Nvl\Support\Bindings\RequiredBindings

```text
final class Nvl\Support\Bindings\RequiredBindings
public function __construct(Illuminate\Container\Container $container, Illuminate\Contracts\Config\Repository $config): mixed
public function all(?string $package=null): array
@return list<RequiredBindingDefinition>
resolved @return list<Nvl\Support\Bindings\RequiredBindingDefinition>
public function inspect(): array
@return list<RequiredBindingStatus>
resolved @return list<Nvl\Support\Bindings\RequiredBindingStatus>
public function register(Nvl\Support\Bindings\RequiredBindingDefinition $definition): void
```

## Nvl\Support\Config\PackageOptions

```text
final class Nvl\Support\Config\PackageOptions
public static function authGuard(string $package): string
public static function connection(string $package): string
public static function lockStore(string $package, ?string $operation=null): string
public static function migrationsEnabled(string $package, bool $default=true): bool
public static function queueConnection(string $package): string
public static function queueName(string $package): string
public static function routeMiddleware(string $package, ?string $group=null, array $default=[]): array
@param list<string> $default
@return list<string>
```

## Nvl\Support\Contracts\DomainEvent

```text
interface Nvl\Support\Contracts\DomainEvent
public abstract function schemaVersion(): int
```

## Nvl\Support\Contracts\LocaleCatalog

```text
interface Nvl\Support\Contracts\LocaleCatalog
public abstract function assertSupported(string $locale): string
public abstract function chain(string $requestedLocale, array $additionalFallbacks=[]): array
@param list<mixed> $additionalFallbacks
@return list<string>
public abstract function default(): string
public abstract function fallbacks(): array
@return list<string>
public abstract function normalize(string $locale): string
public abstract function supported(): array
@return list<string>
public abstract function supports(string $locale): bool
```

## Nvl\Support\Contracts\PackageException

```text
interface Nvl\Support\Contracts\PackageException extends Throwable
```

## Nvl\Support\Contracts\RespondableException

```text
interface Nvl\Support\Contracts\RespondableException extends Nvl\Support\Contracts\PackageException
public abstract function package(): string
public abstract function publicContext(): array
@return array<string, mixed>
public abstract function responseCode(): ?string
public abstract function responseHeaders(): array
@return array<string, string>
public abstract function suggestedStatus(): int
public abstract function translationKey(): ?string
public abstract function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Support\Contracts\ResponseCode

```text
interface Nvl\Support\Contracts\ResponseCode extends BackedEnum
```

## Nvl\Support\Doctor\DoctorCheck

```text
final readonly class Nvl\Support\Doctor\DoctorCheck
public function __construct(string $key, string $severity, bool $passed, string $message): mixed
public function fails(bool $strict): bool
public function toArray(string $package): array
@return array{package: string, key: string, severity: string, result: string, message: string}
public readonly string $key
public readonly string $message
public readonly bool $passed
public readonly string $severity
```

## Nvl\Support\Doctor\DoctorContributor

```text
interface Nvl\Support\Doctor\DoctorContributor
public abstract function inspect(): iterable
@return iterable<DoctorCheck>
resolved @return iterable<Nvl\Support\Doctor\DoctorCheck>
public abstract function package(): string
```

## Nvl\Support\Doctor\RequiredBindingsDoctor

```text
final readonly class Nvl\Support\Doctor\RequiredBindingsDoctor implements Nvl\Support\Doctor\DoctorContributor
public function __construct(Nvl\Support\Bindings\RequiredBindings $bindings): mixed
public function inspect(): iterable
@return iterable<DoctorCheck>
resolved @return iterable<Nvl\Support\Doctor\DoctorCheck>
public function package(): string
```

## Nvl\Support\Enums\CoreResponseCode

```text
enum Nvl\Support\Enums\CoreResponseCode implements BackedEnum, Nvl\Support\Contracts\ResponseCode, UnitEnum: string
public const Nvl\Support\Enums\CoreResponseCode BindingRequired=Nvl\Support\Enums\CoreResponseCode::BindingRequired="binding_required"
public const Nvl\Support\Enums\CoreResponseCode EventCommitUnavailable=Nvl\Support\Enums\CoreResponseCode::EventCommitUnavailable="event_commit_unavailable"
public const Nvl\Support\Enums\CoreResponseCode OperationFailed=Nvl\Support\Enums\CoreResponseCode::OperationFailed="operation_failed"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Support\Enums\PublicActor

```text
enum Nvl\Support\Enums\PublicActor implements UnitEnum
public const Nvl\Support\Enums\PublicActor Anonymous=Nvl\Support\Enums\PublicActor::Anonymous
public readonly string $name
public static function cases(): array
```

## Nvl\Support\Enums\SystemActor

```text
enum Nvl\Support\Enums\SystemActor implements Illuminate\Contracts\Auth\Access\Authorizable, UnitEnum
public const Nvl\Support\Enums\SystemActor Background=Nvl\Support\Enums\SystemActor::Background
public function can(mixed $abilities, mixed $arguments=[]): bool
@param (\UnitEnum | iterable<array-key, (\UnitEnum | string)> | string) $abilities
resolved @param (UnitEnum | iterable<array-key, (UnitEnum | string)> | string) $abilities
public readonly string $name
public static function cases(): array
```

## Nvl\Support\Events\ConnectionCommitCallbacks

```text
final readonly class Nvl\Support\Events\ConnectionCommitCallbacks
public function __construct(Closure $transactions): mixed
@param Closure(): DatabaseTransactionsManager $transactions
resolved @param Closure(): Illuminate\Database\DatabaseTransactionsManager $transactions
public function afterCommit(Illuminate\Database\Connection $connection, Closure $callback): void
@param Closure(): void $callback
@throws EventCommitRegistrationException
resolved @throws Nvl\Support\Exceptions\EventCommitRegistrationException
```

## Nvl\Support\Events\DomainEventDispatcher

```text
final readonly class Nvl\Support\Events\DomainEventDispatcher
public function __construct(Closure $events, Nvl\Support\Events\ConnectionCommitCallbacks $commits): mixed
@param Closure(): Dispatcher $events
resolved @param Closure(): Illuminate\Contracts\Events\Dispatcher $events
public function dispatch(Nvl\Support\Contracts\DomainEvent $event, Illuminate\Database\Connection $connection): void
```

## Nvl\Support\Exceptions\BindingRequiredException

```text
final class Nvl\Support\Exceptions\BindingRequiredException extends Nvl\Support\Exceptions\BusinessException
public function __construct(string $message="", ?Nvl\Support\Contracts\ResponseCode $responseCode=null, int $suggestedStatus=422, array $publicContext=[], array $diagnosticContext=[], ?Throwable $previous=null): mixed
@param string $message
@param (ResponseCode | null) $responseCode
resolved @param (Nvl\Support\Contracts\ResponseCode | null) $responseCode
@param int $suggestedStatus
@param array<string, mixed> $publicContext
@param array<string, mixed> $diagnosticContext
@param (Throwable | null) $previous
@throws SupportException
resolved @throws Nvl\Support\Exceptions\SupportException
public function context(): array
@return array<string, mixed>
public static function for(string $package, string $contract, string $capability): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
@return (string | null)
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
@return int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Support\Exceptions\BusinessException

```text
class Nvl\Support\Exceptions\BusinessException extends Nvl\Support\Exceptions\SupportException implements Nvl\Support\Contracts\RespondableException
public function __construct(string $message="", ?Nvl\Support\Contracts\ResponseCode $responseCode=null, int $suggestedStatus=422, array $publicContext=[], array $diagnosticContext=[], ?Throwable $previous=null): mixed
@param string $message
@param (ResponseCode | null) $responseCode
resolved @param (Nvl\Support\Contracts\ResponseCode | null) $responseCode
@param int $suggestedStatus
@param array<string, mixed> $publicContext
@param array<string, mixed> $diagnosticContext
@param (Throwable | null) $previous
@throws SupportException
resolved @throws Nvl\Support\Exceptions\SupportException
public function context(): array
@return array<string, mixed>
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
@return (string | null)
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
@return int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Support\Exceptions\EventCommitRegistrationException

```text
final class Nvl\Support\Exceptions\EventCommitRegistrationException extends Nvl\Support\Exceptions\BusinessException
public function __construct(): mixed
public function context(): array
@return array<string, mixed>
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
@return int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Support\Exceptions\ExceptionResponse

```text
final readonly class Nvl\Support\Exceptions\ExceptionResponse
public function __construct(string $package, Nvl\Support\Contracts\ResponseCode $code, int $status=422, array $publicContext=[], array $translationParameters=[], array $headers=[]): mixed
@param array<string, mixed> $publicContext
@param array<string, (scalar | null)> $translationParameters
@param array<string, string> $headers
public readonly Nvl\Support\Contracts\ResponseCode $code
public readonly array $headers
@param array<string, string> $headers
public readonly string $package
public readonly array $publicContext
@param array<string, mixed> $publicContext
public readonly int $status
public readonly array $translationParameters
@param array<string, (scalar | null)> $translationParameters
```

## Nvl\Support\Exceptions\SupportException

```text
class Nvl\Support\Exceptions\SupportException extends Exception implements Nvl\Support\Contracts\PackageException
```

## Nvl\Support\Facades\Locales

```text
final class Nvl\Support\Facades\Locales extends Illuminate\Support\Facades\Facade
@method static string assertSupported(string $locale)
@method static list<string> chain(string $requestedLocale, list<mixed> $additionalFallbacks = [])
@method static string default()
@method static list<string> fallbacks()
@method static string normalize(string $locale)
@method static list<string> supported()
@method static bool supports(string $locale)
```

## Nvl\Support\Facades\PackageLog

```text
final class Nvl\Support\Facades\PackageLog extends Illuminate\Support\Facades\Facade
@method static void log(string $package, string $level, string $key, array<string, mixed> $context = [], string $verbosity = 'normal')
```

## Nvl\Support\Installation\ConfigPublisher

```text
final readonly class Nvl\Support\Installation\ConfigPublisher
public function __construct(Illuminate\Contracts\Foundation\Application $application, Nvl\Support\Installation\InstallationRegistry $registry, Nvl\Support\Bindings\RequiredBindings $requiredBindings): mixed
public function publish(array $packages, bool $dryRun=false, bool $force=false): Nvl\Support\Installation\InstallReport
@param list<string> $packages
```

## Nvl\Support\Installation\InstallReport

```text
final readonly class Nvl\Support\Installation\InstallReport
public function __construct(array $packages, array $configuration, array $bindings, bool $missingEnabledBindings): mixed
@param list<string> $packages
@param list<array{path: string, result: ('published' | 'preserved' | 'would_publish' | 'would_replace'), backup: ?string}> $configuration
@param list<RequiredBindingStatus> $bindings
resolved @param list<Nvl\Support\Bindings\RequiredBindingStatus> $bindings
public function toArray(): array
@return array{schema_version: int, packages: list<string>, configuration: list<array{path: string, result: string, backup: ?string}>, bindings: list<array{package: string, contract: string, capability: string, status: string, message: string, documentation: string}>, healthy: bool}
public readonly array $bindings
@param list<RequiredBindingStatus> $bindings
resolved @param list<Nvl\Support\Bindings\RequiredBindingStatus> $bindings
public readonly array $configuration
@param list<array{path: string, result: ('published' | 'preserved' | 'would_publish' | 'would_replace'), backup: ?string}> $configuration
public readonly bool $missingEnabledBindings
public readonly array $packages
@param list<string> $packages
```

## Nvl\Support\Installation\InstallationContributor

```text
interface Nvl\Support\Installation\InstallationContributor
public abstract function installation(): Nvl\Support\Installation\PackageInstallation
```

## Nvl\Support\Installation\InstallationRegistry

```text
final readonly class Nvl\Support\Installation\InstallationRegistry
public function __construct(Illuminate\Contracts\Container\Container $container): mixed
public function all(): array
@return list<PackageInstallation>
resolved @return list<Nvl\Support\Installation\PackageInstallation>
public function select(array $packages): array
@param list<string> $packages
@return list<PackageInstallation>
resolved @return list<Nvl\Support\Installation\PackageInstallation>
```

## Nvl\Support\Installation\PackageInstallation

```text
final readonly class Nvl\Support\Installation\PackageInstallation implements Nvl\Support\Installation\InstallationContributor
public function __construct(string $package, array $configuration): mixed
@param array<array-key, mixed> $configuration
public function installation(): self
public static function register(Illuminate\Contracts\Container\Container $container, string $package, array $configuration): void
@param array<array-key, mixed> $configuration
public readonly array $configuration
@var array<string, array{source: string, target: string, tag: string}>
public readonly string $package
```

## Nvl\Support\Locales\LocaleCatalogDiagnostics

```text
final readonly class Nvl\Support\Locales\LocaleCatalogDiagnostics
public function __construct(Illuminate\Contracts\Config\Repository $config, Nvl\Support\Contracts\LocaleCatalog $catalog): mixed
public function inspect(): array
@return array{errors: list<string>, warnings: list<string>}
```

## Nvl\Support\Logging\PackageLogger

```text
final readonly class Nvl\Support\Logging\PackageLogger
public function __construct(Illuminate\Contracts\Config\Repository $config, Illuminate\Log\LogManager|Closure $logs): mixed
@param (LogManager | Closure(): LogManager) $logs
resolved @param (Illuminate\Log\LogManager | Closure(): Illuminate\Log\LogManager) $logs
public function diagnostics(): array
@return list<string>
public function log(string $package, string $level, string $key, array $context=[], string $verbosity="normal"): void
@param array<array-key, mixed> $context
```

## Nvl\Support\Models\ConfiguredModelResolver

```text
final readonly class Nvl\Support\Models\ConfiguredModelResolver
public function resolve(string $configured, string $base): string
@template TModel of Model
resolved @template TModel of Illuminate\Database\Eloquent\Model
@param string $configured
@param class-string<TModel> $base
@return class-string<TModel>
template Nvl\Support\Models\ConfiguredModelResolver::TModel of Illuminate\Database\Eloquent\Model
```

## Nvl\Support\OwnerRegistry

```text
final class Nvl\Support\OwnerRegistry
public function __construct(Illuminate\Contracts\Config\Repository $configuration): mixed
public function aliasFor(Illuminate\Database\Eloquent\Model $model): string
public function all(): array
@return array<string, class-string<Model>>
resolved @return array<string, class-string<Illuminate\Database\Eloquent\Model>>
public function errors(): array
@return array<string, string>
public function model(string $reference): string
@return class-string<Model>
resolved @return class-string<Illuminate\Database\Eloquent\Model>
public function register(string $alias, string $model): void
@param class-string<Model> $model
resolved @param class-string<Illuminate\Database\Eloquent\Model> $model
public function registerReplacement(string $alias, string $base, string $configured): void
@param class-string<Model> $base
resolved @param class-string<Illuminate\Database\Eloquent\Model> $base
```

## Nvl\Support\Owners\OwnerBatch

```text
final readonly class Nvl\Support\Owners\OwnerBatch
public const int MAXIMUM_OWNERS=100
public static function fromModels(array $owners): self
@param array<array-key, mixed> $owners
@throws InvalidArgumentException
public function identities(): array
@return list<OwnerIdentity>
resolved @return list<Nvl\Support\Owners\OwnerIdentity>
public function isEmpty(): bool
public function owners(): array
@return list<Model>
resolved @return list<Illuminate\Database\Eloquent\Model>
```

## Nvl\Support\Owners\OwnerIdentity

```text
final readonly class Nvl\Support\Owners\OwnerIdentity
public static function fromModel(Illuminate\Database\Eloquent\Model $owner): self
@throws InvalidArgumentException
public readonly ?string $connection
public readonly string $id
public readonly string $model
@param class-string<Model> $model
resolved @param class-string<Illuminate\Database\Eloquent\Model> $model
public readonly string $type
```

## Nvl\Support\Owners\OwnerResultMap

```text
final readonly class Nvl\Support\Owners\OwnerResultMap implements JsonSerializable
@template T of object
public function __construct(Nvl\Support\Owners\OwnerBatch $batch, array $values): mixed
@param array<string, array<array-key, T>> $values
@throws InvalidArgumentException
template Nvl\Support\Owners\OwnerResultMap::T of object
public function get(string $type, string $id): object
@return T
@throws OutOfBoundsException
template Nvl\Support\Owners\OwnerResultMap::T of object
public function jsonSerialize(): stdClass
public function order(): array
@return list<array{type: string, id: string}>
```

## Nvl\Support\Ownership\Contracts\Ownable

```text
interface Nvl\Support\Ownership\Contracts\Ownable
public abstract static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
```

## Nvl\Support\Ownership\Contracts\OwnershipBoundary

```text
interface Nvl\Support\Ownership\Contracts\OwnershipBoundary
public abstract function assertRecord(Illuminate\Database\Eloquent\Model $record, string $resource): void
public abstract function attributes(string $resource): array
@return array<string, (int | string | null)>
public abstract function key(string $resource, string $identity): string
public abstract function query(Illuminate\Database\Eloquent\Builder $query, string $resource): Illuminate\Database\Eloquent\Builder
@template TModel of Model
resolved @template TModel of Illuminate\Database\Eloquent\Model
@param Builder<TModel> $query
resolved @param Illuminate\Database\Eloquent\Builder<TModel> $query
@return Builder<TModel>
resolved @return Illuminate\Database\Eloquent\Builder<TModel>
template Nvl\Support\Ownership\Contracts\OwnershipBoundary::TModel of Illuminate\Database\Eloquent\Model
```

## Nvl\Support\Ownership\Contracts\OwnershipContext

```text
interface Nvl\Support\Ownership\Contracts\OwnershipContext
public abstract function requireOwner(): Nvl\Support\Ownership\ValueObjects\OwnerReference
public abstract function snapshot(): Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot
```

## Nvl\Support\Ownership\Contracts\OwnershipContextParticipant

```text
interface Nvl\Support\Ownership\Contracts\OwnershipContextParticipant
public abstract function enter(Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot $next): Closure
@return Closure(): void
```

## Nvl\Support\Ownership\Contracts\OwnershipQueueContext

```text
interface Nvl\Support\Ownership\Contracts\OwnershipQueueContext
public abstract function captureBatch(Illuminate\Bus\PendingBatch $batch): Illuminate\Bus\PendingBatch
public abstract function captureUnowned(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public abstract function run(Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope $envelope, Closure $operation): mixed
@template T
@param Closure(): T $operation
@return T
template Nvl\Support\Ownership\Contracts\OwnershipQueueContext::T
```

## Nvl\Support\Ownership\Contracts\OwnershipQueueHandler

```text
interface Nvl\Support\Ownership\Contracts\OwnershipQueueHandler
public abstract function call(Illuminate\Contracts\Queue\Job $job, array $data): void
@param array<string, mixed> $data
public abstract function failed(array $data, mixed $e, string $uuid, ?Illuminate\Contracts\Queue\Job $job=null): void
@param array<string, mixed> $data
public abstract function validate(array $data): void
@param array<string, mixed> $data
```

## Nvl\Support\Ownership\Contracts\OwnershipQueuedJob

```text
interface Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public abstract function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
```

## Nvl\Support\Ownership\Contracts\OwnershipSystemRunner

```text
interface Nvl\Support\Ownership\Contracts\OwnershipSystemRunner
public abstract function runSystem(Nvl\Support\Ownership\ValueObjects\OwnerReference $owner, string $operation, Closure $callback): mixed
@template T
@param Closure(): T $callback
@return T
template Nvl\Support\Ownership\Contracts\OwnershipSystemRunner::T
```

## Nvl\Support\Ownership\Enums\OwnershipContextMode

```text
enum Nvl\Support\Ownership\Enums\OwnershipContextMode implements BackedEnum, UnitEnum: string
public const Nvl\Support\Ownership\Enums\OwnershipContextMode Central=Nvl\Support\Ownership\Enums\OwnershipContextMode::Central="central"
public const Nvl\Support\Ownership\Enums\OwnershipContextMode Disabled=Nvl\Support\Ownership\Enums\OwnershipContextMode::Disabled="disabled"
public const Nvl\Support\Ownership\Enums\OwnershipContextMode Owner=Nvl\Support\Ownership\Enums\OwnershipContextMode::Owner="owner"
public const Nvl\Support\Ownership\Enums\OwnershipContextMode Unresolved=Nvl\Support\Ownership\Enums\OwnershipContextMode::Unresolved="unresolved"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Support\Ownership\Enums\OwnershipResponseCode

```text
enum Nvl\Support\Ownership\Enums\OwnershipResponseCode implements BackedEnum, Nvl\Support\Contracts\ResponseCode, UnitEnum: string
public const Nvl\Support\Ownership\Enums\OwnershipResponseCode BoundaryViolation=Nvl\Support\Ownership\Enums\OwnershipResponseCode::BoundaryViolation="ownership_boundary_violation"
public const Nvl\Support\Ownership\Enums\OwnershipResponseCode ConfigurationInvalid=Nvl\Support\Ownership\Enums\OwnershipResponseCode::ConfigurationInvalid="ownership_configuration_invalid"
public const Nvl\Support\Ownership\Enums\OwnershipResponseCode SchemaNotReady=Nvl\Support\Ownership\Enums\OwnershipResponseCode::SchemaNotReady="ownership_schema_not_ready"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid

```text
final class Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid extends Nvl\Support\Ownership\Exceptions\OwnershipException
public function __construct(string $message="Ownership configuration is invalid."): mixed
public function context(): array
@return array<string, mixed>
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
@return (string | null)
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
@return int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Support\Ownership\Exceptions\OwnershipException

```text
class Nvl\Support\Ownership\Exceptions\OwnershipException extends Nvl\Support\Exceptions\BusinessException
public function __construct(string $message="", ?Nvl\Support\Contracts\ResponseCode $responseCode=null, int $suggestedStatus=422, array $publicContext=[], array $diagnosticContext=[], ?Throwable $previous=null): mixed
@param string $message
@param (ResponseCode | null) $responseCode
resolved @param (Nvl\Support\Contracts\ResponseCode | null) $responseCode
@param int $suggestedStatus
@param array<string, mixed> $publicContext
@param array<string, mixed> $diagnosticContext
@param (Throwable | null) $previous
@throws SupportException
resolved @throws Nvl\Support\Exceptions\SupportException
public function context(): array
@return array<string, mixed>
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
@return (string | null)
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
@return int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady

```text
final class Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady extends Nvl\Support\Ownership\Exceptions\OwnershipException
public function __construct(string $message="The ownership installation is not ready."): mixed
public function context(): array
@return array<string, mixed>
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
@return (string | null)
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
@return int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Support\Ownership\Exceptions\OwnershipViolation

```text
final class Nvl\Support\Ownership\Exceptions\OwnershipViolation extends Nvl\Support\Ownership\Exceptions\OwnershipException
public function __construct(string $message="The resource is outside the admitted ownership context.", ?Throwable $previous=null): mixed
public function context(): array
@return array<string, mixed>
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
@return (string | null)
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
@return int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Support\Ownership\Services\OwnerSiteContext

```text
final class Nvl\Support\Ownership\Services\OwnerSiteContext
public static function current(): ?Nvl\Support\Ownership\ValueObjects\VerifiedOwnerSiteContext
public static function require(): Nvl\Support\Ownership\ValueObjects\VerifiedOwnerSiteContext
```

## Nvl\Support\Ownership\Services\OwnershipContextParticipants

```text
final class Nvl\Support\Ownership\Services\OwnershipContextParticipants
public function register(string $participant): void
@param class-string<OwnershipContextParticipant> $participant
resolved @param class-string<Nvl\Support\Ownership\Contracts\OwnershipContextParticipant> $participant
```

## Nvl\Support\Ownership\Services\OwnershipResourceRegistry

```text
final class Nvl\Support\Ownership\Services\OwnershipResourceRegistry
public function all(): array
@return array<string, OwnershipResourceDefinition>
resolved @return array<string, Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition>
public function forModel(Illuminate\Database\Eloquent\Model $model): Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition
public function freeze(): void
public function get(string $key): Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition
public function register(Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition $resource): void
```

## Nvl\Support\Ownership\Traits\CapturesOwnership

```text
trait Nvl\Support\Ownership\Traits\CapturesOwnership
public final function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public final function ownershipLockKey(string $identity): string
public final function uniqueId(): string
```

## Nvl\Support\Ownership\Traits\HasOwnership

```text
trait Nvl\Support\Ownership\Traits\HasOwnership
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Support\Ownership\Traits\HasOwnership>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
```

## Nvl\Support\Ownership\Traits\ScopesOwnedBuilderWrites

```text
trait Nvl\Support\Ownership\Traits\ScopesOwnedBuilderWrites
public function forceDelete(): mixed
@return mixed
public function updateFrom(array $values): int
@param array<string, mixed> $values
public function updateOrInsert(array $attributes, array|callable $values=[]): bool
@param array<string, mixed> $attributes
@param (array<string, mixed> | callable) $values
```

## Nvl\Support\Ownership\ValueObjects\OwnerReference

```text
final readonly class Nvl\Support\Ownership\ValueObjects\OwnerReference
public function __construct(string $type, string $storage, string $keyType, string $key, int $version=1): mixed
public static function fromArray(array $payload): self
@param array<array-key, mixed> $payload
public static function fromJson(string $payload): self
public function scopeKey(): string
public function toArray(): array
@return array{version: int, type: string, storage: string, keyType: string, key: string}
public readonly string $key
public readonly string $keyType
public readonly string $storage
public readonly string $type
public readonly int $version
```

## Nvl\Support\Ownership\ValueObjects\OwnershipDefinition

```text
final readonly class Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public static function column(string $owner, string $foreignKey): self
public static function inherited(string $relation): self
public static function pivot(string $owner, string $table, string $resourceKey, string $ownerKey): self
public static function unscoped(): self
public readonly ?string $foreignKey
public readonly ?string $owner
@param (class-string<Model> | null) $owner
resolved @param (class-string<Illuminate\Database\Eloquent\Model> | null) $owner
public readonly ?string $ownerKey
public readonly ?string $relation
public readonly ?string $resourceKey
public readonly string $strategy
public readonly ?string $table
```

## Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope

```text
final readonly class Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public static function capture(Nvl\Support\Ownership\Contracts\OwnershipContext $context): self
public function context(): Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot
public static function fromArray(array $data): self
@param array<array-key, mixed> $data
public static function fromSnapshot(Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot $snapshot): self
public function key(string $identity): string
public function toArray(): array
@return Wire
resolved @return array{version: (1 | 2), mode: string, owner: (array{version: int, type: string, storage: string, keyType: string, key: string} | null), principal: (array{kind: ('actor' | 'system'), type: string, id: string} | null), operation: (string | null), generations: array<string, string>}
public readonly array $data
@param Wire $data
resolved @param array{version: (1 | 2), mode: string, owner: (array{version: int, type: string, storage: string, keyType: string, key: string} | null), principal: (array{kind: ('actor' | 'system'), type: string, id: string} | null), operation: (string | null), generations: array<string, string>} $data
```

## Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition

```text
final readonly class Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition
public function __construct(string $key, string $family, string $model, Nvl\Support\Ownership\ValueObjects\OwnershipDefinition $ownership, ?string $parentResource=null, bool $allowsCentral=false, bool $requiresPartition=true, array $parentResources=[], array $immutableFields=[]): mixed
@param class-string<Model> $model
resolved @param class-string<Illuminate\Database\Eloquent\Model> $model
@param array<array-key, string> $parentResources
@param list<string> $immutableFields
public function parents(): array
@return list<string>
public readonly bool $allowsCentral
public readonly string $family
public readonly array $immutableFields
@param list<string> $immutableFields
public readonly string $key
public readonly string $model
@param class-string<Model> $model
resolved @param class-string<Illuminate\Database\Eloquent\Model> $model
public readonly Nvl\Support\Ownership\ValueObjects\OwnershipDefinition $ownership
public readonly ?string $parentResource
public readonly array $parentResources
@var list<string>
public readonly bool $requiresPartition
```

## Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot

```text
final readonly class Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot
public function __construct(Nvl\Support\Ownership\Enums\OwnershipContextMode $mode, ?Nvl\Support\Ownership\ValueObjects\OwnerReference $owner=null, ?array $principal=null, ?string $operation=null, array $generations=[], ?Nvl\Support\Ownership\ValueObjects\VerifiedOwnerSiteContext $publicSite=null): mixed
@param (array{kind: ('actor' | 'system' | 'public'), type: string, id: string} | null) $principal
@param array<string, string> $generations
public readonly array $generations
@param array<string, string> $generations
public readonly Nvl\Support\Ownership\Enums\OwnershipContextMode $mode
public readonly ?string $operation
public readonly ?Nvl\Support\Ownership\ValueObjects\OwnerReference $owner
public readonly ?array $principal
@param (array{kind: ('actor' | 'system' | 'public'), type: string, id: string} | null) $principal
public readonly ?Nvl\Support\Ownership\ValueObjects\VerifiedOwnerSiteContext $publicSite
```

## Nvl\Support\Ownership\ValueObjects\VerifiedOwnerSiteContext

```text
final readonly class Nvl\Support\Ownership\ValueObjects\VerifiedOwnerSiteContext
public function __construct(Nvl\Support\Ownership\ValueObjects\OwnerReference $owner, string $site, string $canonicalOrigin, array $resources, array $writableResources=[]): mixed
@param array<array-key, mixed> $resources
@param array<array-key, mixed> $writableResources
public readonly string $canonicalOrigin
public readonly Nvl\Support\Ownership\ValueObjects\OwnerReference $owner
public readonly array $resources
@var list<string>
public readonly string $site
public readonly array $writableResources
@var list<string>
```

## Nvl\Support\Testing\FakeCall

```text
final readonly class Nvl\Support\Testing\FakeCall
public function __construct(string $method, array $arguments): mixed
@param array<string, mixed> $arguments
public readonly array $arguments
@param array<string, mixed> $arguments
public readonly string $method
```

## Nvl\Support\Testing\FakeCalls

```text
trait Nvl\Support\Testing\FakeCalls
public function assertCalled(string $method, ?Closure $predicate=null, int $times=1): void
@param (Closure(FakeCall): bool | null) $predicate
resolved @param (Closure(Nvl\Support\Testing\FakeCall): bool | null) $predicate
public function calls(?string $method=null): array
@return list<FakeCall>
resolved @return list<Nvl\Support\Testing\FakeCall>
public function willReturn(string $method, mixed $value): static
public function willThrow(string $method, Throwable $exception): static
```

## Nvl\Support\Testing\FakeExpectationFailed

```text
final class Nvl\Support\Testing\FakeExpectationFailed extends RuntimeException implements Nvl\Support\Contracts\PackageException
```

## Nvl\Support\Testing\UnscriptedFakeCall

```text
final class Nvl\Support\Testing\UnscriptedFakeCall extends RuntimeException implements Nvl\Support\Contracts\PackageException
```

## Nvl\Support\Traits\InteractsWithPackageFailure

```text
trait Nvl\Support\Traits\InteractsWithPackageFailure
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```
