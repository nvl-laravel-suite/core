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

## Shared adoption flow (7.x)

Adoption runs through `nvl:adopt` with reviewed, checkpointed batches and an explicit activate step. Committed batches survive a failure. Keep source and destination writers stopped from the final apply until verify and activate pass.

- Nothing changes until you opt in. To use it, set `nvl-core.adoption.migrations.enabled` to `true`, or publish `nvl-core-migrations` once, and migrate each destination connection a package adapter writes to.
- Run `plan`, review its fingerprint and collisions, then `apply --fingerprint=<reviewed>`. After a failure, resume with `apply --fingerprint=<reviewed> --run=<id>` and the same manifest. A changed source, different options, a different fingerprint for the same scope or drift behind the cursor stops the run, and planning again does not unblock it: the scope keeps its first run. Either revert the source or destination change and resume with the original fingerprint, or delete the run, its checkpoints and the staged destination rows, then plan and apply again.
- `verify` must pass before `activate`. Activation re-verifies under a lock and changes no configuration: deploy the host switch afterwards. Integrations that must wait call `AdoptionGate::assertActive($package)`.
