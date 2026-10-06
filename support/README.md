# NVL Support

Support ships inside `nvl/core:^5.0`; it is not a separate Composer package. Read the [Core quickstart](../README.md#quickstart) and [Data guide](../data/README.md).

## Quickstart

Install Core, run `php artisan nvl:install core --dry-run`, then publish only the common configuration your host needs. Support owns canonical package configuration merging, native owner identities, neutral tenant boundaries, schema preflight/upgrades, loaded-package installation metadata, Doctor, exact source-commit callbacks, safe optional failure rendering and package logging. Data owns DTO/type generation. Neither component adopts the host's auth/locale/tenant state merely by installation.

Inject `Nvl\Support\Contracts\LocaleCatalog` and call `supported()` for the configured content locale list. Its discovered provider is deferred; prebound host catalogs win. Register owners by their native Eloquent class and Laravel morph identity in nvl-core.owners. Core emits no domain events itself; callers may dispatch their own DomainEvent through DomainEventDispatcher with the actual writer connection inside its transaction.

## Testing your app

Replace a public contract through Laravel's container for a host unit test. Core has no persistent factory model. Its FakeCalls helpers support the shipped Media/Stripe test doubles; assertions are explicit and unscripted return calls fail. Include `vendor/nvl/core/support/consumer-audit.neon` in host PHPStan with exact test paths and documented narrow exceptions. See [Core testing](../README.md#testing-your-app).

## Runtime policy

Package logging resolves policy on every call and retains no actor/tenant/job context. Default nvl/normal and csv/quiet preserve incident logs. A host nvl channel wins; otherwise a stack of the host default is installed lazily. Doctor rejects missing/cyclic channels. No global shareContext is mutated.

The host may register PackageExceptionRenderer in withExceptions for JSON RespondableException failures. Its safe localized envelope is message/code/context; request locale stays host-owned and marker-only errors retain host handling. Missing required bindings are configuration failures. See [Core error table](../README.md#error-codes-and-events), [events](../docs/events.md), and [upgrading](../UPGRADING.md). Source commit callbacks are not a durable outbox.

## License

MIT. See [LICENSE](../LICENSE).
