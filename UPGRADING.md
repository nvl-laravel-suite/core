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

## Leaving 6.x: reconcile schema before upgrading

Family 7 retired `nvl:schema:preflight` and `nvl:schema:upgrade`; Core 7 registers neither. They are the only commands that rename legacy NVL tables and migration records, so upgrading Composer dependencies first removes them before the schema is reconciled. Work in this order:

1. Still on 6.x, run `php artisan nvl:doctor --strict`. For each installed NVL package, run `php artisan nvl:schema:upgrade --package=<slug> --claim-legacy --migration-owner=<owner> --dry-run`, review the plan, then run it again without `--dry-run`.
2. Choose the migration owner once, for every package. `vendor` keeps loading package migrations from the vendor directory and archives superseded copies. `published` makes the host's published migrations the sole owner; it requires the current package migration code and `nvl-<package>.migrations.enabled=false`. Never load both.
3. Upgrade the NVL packages to 7.x.
4. From then on run plain Laravel migrations (`php artisan migrate`). There is no NVL preflight step in family 7.

## Shared adoption flow (7.x)

Adoption runs through `nvl:adopt` with reviewed, checkpointed batches and an explicit activate step. Committed batches survive a failure. Keep source and destination writers stopped from the final apply until verify and activate pass.

- Nothing changes until you opt in. To use it, set `nvl-core.adoption.migrations.enabled` to `true`, or publish `nvl-core-migrations` once, and migrate each destination connection a package adapter writes to.
- Run `plan`, review its fingerprint and collisions, then `apply --fingerprint=<reviewed>`. After a failure, resume with `apply --fingerprint=<reviewed> --run=<id>` and the same manifest. A changed source, different options, a different fingerprint for the same scope or drift behind the cursor stops the run, and planning again does not unblock it: the scope keeps its first run. Either revert the source or destination change and resume with the original fingerprint, or abandon the unfinished run with `nvl:adopt <package> abandon --run=<id>` (Core 7.3+), then plan and apply again. Abandon refuses an active run and keeps staged rows: identical ones are reused, and differing ones are reported as collisions. The same applies when a package upgrade changes its adapter version mid-run.
- `verify` must pass before `activate`. Activation re-verifies under a lock and changes no configuration: deploy the host switch afterwards. Integrations that must wait call `AdoptionGate::assertActive($package)`.
- Core 7.3 adds two adapter hooks. Resuming now calls `AdoptionAdapter::assertCheckpoint()` under the run lock for each step's stored checkpoint, completed ones included, and refuses the resume when committed output behind the cursor was deleted. The default fails closed, so a custom adapter must implement it before it can resume a run; a fresh run that completes in one invocation never calls it. `AdoptionAdapter::aroundVerification()` wraps verify's and activate's run-locked transaction and passes through by default; an adapter that holds a lock in `aroundBatch()` should take it there too, so every phase acquires its locks in the same order.
