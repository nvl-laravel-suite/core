# NVL Core

Native Laravel support contracts, configuration, model identity, locale services and optional Ownership ports. Requires PHP 8.4 and Laravel 13 (minimum 13.30).

Core auto-discovers `SupportServiceProvider` and the deferred `LocaleServiceProvider`. It supplies safe unowned defaults without activating domain packages, routes, Data, TypeScript, consumer-policy analysis or the retired Tenancy bridge. Consumers own inputs, Actions, transport adapters, resources and frontend types.

Register application owner models in `nvl-core.owners`. Identity registration does not authorize access. Supply explicit principals, `SystemActor::Background` or `PublicActor::Anonymous` to the owning package's native service; background and public execution remain distinct and subject to host policy.

`SystemActor` implements Laravel's `Authorizable` ability contract for compatible global Gate hooks, including optional permission integrations. Its `can` method evaluates the Gate for that explicit enum; it supplies no login identity or default grant and does not implement `Authenticatable`. Host hooks used by native background operations must accept the actual actor type rather than assume an application User model.

Native Ownership declarations, model guards, queue envelopes and committed-event dispatch remain available. Owned or previously adopted storage fails closed when its enforcing runtime is absent. Install `nvl/ownership` for enabled isolation and recovery. Core registers `nvl:queue:retry <failed-job-id>` itself: it requeues a captured or quarantined failed job verbatim, without restoring its command, whether or not `nvl/ownership` is installed; native `queue:retry` is refused for such records.

Core preserves host bindings and Laravel bus/batch behavior. `DomainEventDispatcher` publishes on the actual source connection's outer commit; rollback discards publication. Local callbacks are not a durable outbox. See [event delivery](docs/events.md).

Configuration defaults require no publication. The supported tags are `nvl-core-config`, `nvl-core-migrations`, `nvl-core-translations` and `nvl-core-skills`. Domain diagnostics and migrations remain with their owning packages; Core's only migration is the opt-in adoption run store.

Canonical configuration uses `nvl-core` and each capability's `nvl-<package>` root. Legacy roots, environment fallbacks, option aliases and global-name compatibility switches are unavailable. Host registrations remain authoritative.

Bind `Nvl\Support\Contracts\TextMatcher` to share application-specific text folding across packages that use it. The default matches literal substrings, with opt-in SQL wildcards, Unicode case folding on SQLite, and `ILIKE` on PostgreSQL. PostgreSQL uses `pg_catalog.pg_c_utf8` on 17 and newer and `pg_catalog.und-x-icu` on 16. Set `nvl-core.search.postgres_collation` to a deterministic collation name, optionally qualified by schema, to override it. `nvl:doctor` checks the selected Core PostgreSQL connection.

For optional console setup and combined diagnostics, explicitly register `Nvl\Support\Providers\DoctorServiceProvider` in `bootstrap/providers.php`. It supplies `nvl:install` (including `--dry-run`) and `nvl:doctor`; Core's auto-discovered providers remain passive. See the [installation guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/getting-started.md) and [service and model extension contracts](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/consumer-api.md).

## Adoption

`nvl:adopt {package} {phase=plan} {manifest?}` (phases `plan`, `apply`, `verify`, `activate` and `abandon`) runs a package's registered adoption adapter as `SystemActor::Background`, through that package's normal authorization. The optional manifest is a JSON options file. Phases run in order:

1. `plan` reads the source graph and destination, reports the fingerprint, expected counts and collisions, and writes nothing.
2. `apply --fingerprint=<reviewed>` refuses collisions, a changed fingerprint or an open transaction, then commits keyset batches (`--batch-size`, default `nvl-core.adoption.batch_size`). Each batch's rows and checkpoint commit together. Resume a stopped run with `--run=<id>`, the original fingerprint and the same manifest.
3. `verify` scans committed source and destination independently and records the verification digest.
4. `activate --fingerprint=<reviewed>` locks the run, re-authorizes, verifies again and marks it active. It changes no configuration.

A scope keeps its first run. If the source, the options, the adapter version or a staged row changes, or a staged row behind a checkpoint is deleted, apply refuses to resume and planning again does not help. Revert the change and resume with the original fingerprint, or run `abandon --run=<id>` and then plan and apply again. Abandon authorizes like apply, locks the run, refuses an active run and deletes the run and its checkpoints. It leaves staged destination rows in place: the next plan treats identical staged rows as already staged and reports differing ones as collisions, which you resolve before applying.

Custom adapters own two drift checks. `plan()` reports staged rows that differ from their source as collisions. `assertCheckpoint($step, $cursor, $processed)` runs under the run lock for each step's stored checkpoint, completed ones included, the first time a resumed apply reads it: re-read the source rows at or before the cursor (`AdoptionStep::through()`), map them as `writeBatch()` does and throw unless every mapped row, child rows included, is still staged. Its default fails closed. An adapter that locks around batches in `aroundBatch()` takes the same lock in `aroundVerification()`, which wraps verify's and activate's run-locked transaction, so every phase locks in the same order.

Keep source and destination writers stopped from the final apply until activation. Runs and checkpoints store hashes, cursors and counts only, in `nvl_core_adoption_runs` and `nvl_core_adoption_checkpoints` on each adapter's destination connection. Enable `nvl-core.adoption.migrations.enabled` or publish `nvl-core-migrations`, then migrate those connections. `--format=json` reports the run, fingerprint, state, cursors and counts for every phase, the collisions for plan and apply, and the verification for verify.
