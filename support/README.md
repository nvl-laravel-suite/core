# NVL Support

Support ships inside `nvl/core`; it is not a separate Composer package. Read the [Core overview](../README.md) for native contracts, configuration, owner identity, locale services and optional Ownership ports.

Core preserves host bindings and native Laravel bus/batch behavior. Consumers own inputs, Actions, transport adapters, resources and frontend types. The retired Data, Tenancy bridge and consumer analyzer are historical fixtures, not runtime integrations.

Use `DomainEventDispatcher` with the actual writer connection to publish after its outer commit. Source callbacks are not a durable outbox. See [event delivery](../docs/events.md).

MIT. See [LICENSE](../LICENSE).
