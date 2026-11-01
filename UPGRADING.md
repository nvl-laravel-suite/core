# NVL Core Light

Light targets PHP 8.4 and Laravel 13 (minimum 13.30). It is a breaking family 7 API; install matching package versions.

- Consumers own data classes, Actions, HTTP controllers, routes, resources and frontend types.
- Native services own complete domain validation, authorization, ownership, transactions, revisions and recovery.
- Use canonical `nvl-<package>` configuration, `NVL_` environment variables and `nvl-<package>-<resource>` publication tags. Old roots, option aliases, environment fallbacks, global aliases and package routes have been removed.
- The deprecated Tenancy bridge and package Data/TypeScript runtime are retired. Enabled isolation uses native Ownership (`nvl/ownership`) and its explicit adoption protocol.
- Core auto-discovers only Support and the deferred Locale provider. Optional combined diagnostics and configuration publication require explicit `DoctorServiceProvider` registration.
- Replace only advertised model roots with concrete subclasses. Keep native identity, casts, ownership and persistence invariants. Host migrations own additional columns.
- Preserve existing database and morph identities deliberately. Code replacement never adopts, renames or rewrites stored data automatically. Follow each owning package's supported migration/adoption procedure; choose vendor loading or published host migrations once.
- Keep durable outbox and queue recovery protocols. Process-local after-commit callbacks do not guarantee delivery after a crash.

See [installation](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/installation.md) and [native consumer and extension contracts](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/consumer-api.md). Refresh configuration caches and restart workers after applying reviewed configuration changes.
