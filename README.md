# NVL Core

Native Laravel support contracts, configuration, model identity, locale services and optional Ownership ports. Requires PHP 8.4 and Laravel 13 (minimum 13.30).

Core auto-discovers `SupportServiceProvider` and the deferred `LocaleServiceProvider`. It supplies safe unowned defaults without activating domain packages, routes, Data, TypeScript, consumer-policy analysis or the retired Tenancy bridge. Consumers own inputs, Actions, transport adapters, resources and frontend types.

Register application owner models in `nvl-core.owners`. Identity registration does not authorize access. Supply explicit principals, `SystemActor::Background` or `PublicActor::Anonymous` to the owning package's native service; background and public execution remain distinct and subject to host policy.

`SystemActor` implements Laravel's `Authorizable` ability contract for compatible global Gate hooks, including optional permission integrations. Its `can` method evaluates the Gate for that explicit enum; it supplies no login identity or default grant and does not implement `Authenticatable`. Host hooks used by native background operations must accept the actual actor type rather than assume an application User model.

Native Ownership declarations, model guards, queue envelopes and committed-event dispatch remain available. Owned or previously adopted storage fails closed when its enforcing runtime is absent. Install `nvl/ownership` for enabled isolation and recovery. Historical legacy source and tests are excluded fixtures under `support/tests/Historical`, never production aliases or data conversion.

Core preserves host bindings and Laravel bus/batch behavior. `DomainEventDispatcher` publishes on the actual source connection's outer commit; rollback discards publication. Local callbacks are not a durable outbox. See [event delivery](docs/events.md).

Configuration defaults require no publication. The supported tags are `nvl-core-config`, `nvl-core-translations` and `nvl-core-skills`. Domain diagnostics and migrations remain with their owning packages.

Canonical configuration uses `nvl-core` and each capability's `nvl-<package>` root. Legacy roots, environment fallbacks, option aliases and global-name compatibility switches are unavailable. Host registrations remain authoritative.

For optional console setup and combined diagnostics, explicitly register `Nvl\Support\Providers\DoctorServiceProvider` in `bootstrap/providers.php`. It supplies `nvl:install` (including `--dry-run`) and `nvl:doctor`; Core's auto-discovered providers remain passive. See the [installation guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/getting-started.md) and [service and model extension contracts](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/consumer-api.md).
