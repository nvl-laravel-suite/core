# NVL Core events

This document describes the implemented source behavior. Acceptance is exercised by the owning package suites and Core committed-event regression tests; current release execution evidence is tracked in consumer-readiness.md. The authoritative machine-readable schema is [event-catalog.json](../resources/event-catalog.json), catalog version `1`. Event `schemaVersion` is independent of catalog version.

This package declares **no domain events**. Shared dispatch and failure infrastructure has no package-owned domain mutation event.

The native host dispatcher receives the captured event after the supplied source connection outer commit, or immediately when that connection has no active transaction.

Callbacks attach to the matching native connection and current nesting record; native outer/savepoint rollback discards the corresponding callbacks.

Missing source transaction records fail before commit; Mail Notifications reports and drops unusable observations.

Host after-commit listeners and queue after_commit policies can add their own deferral after publication. Host transaction infrastructure and dispatcher bindings are preserved.

Local callbacks are not an outbox. Process exit between commit and callback can lose delivery; no crash durability or exactly-once delivery is promised.

One canonical object is dispatched per qualifying producer call. This is local publication, not cross-process deduplication or a guarantee that repeated observations are unique.

Use Nvl\Support\Events\DomainEventDispatcher::dispatch($event, $writerConnection). Native Event::dispatch() is immediate and has no package interception.

`Nvl\Support\Contracts\DomainEvent::schemaVersion(): int` describes the public immutable event schema version. Canonical package objects also expose `$schemaVersion = 1`; they do not use `ShouldDispatchAfterCommit` or `SerializesModels`. The scheduler selects the pending native `DatabaseTransactionRecord` matching the supplied connection name and current nesting level. No global event dispatcher or transaction manager is replaced.

```php
app(\Nvl\Support\Events\DomainEventDispatcher::class)->dispatch($event, $writerConnection);
```

The host must supply its actual writer connection and construct the immutable snapshot before scheduling. A live model in a listener payload is not a substitute for a stable reference. Framework events remain framework-owned and are outside the package catalogs.

## Canonical listeners and queue migration

Event listeners, fakes, subscribers, cached discovery and filters use canonical class names from the package event catalog. Core preserves the host dispatcher and supplies no retired-name aliases or listener bridge. Migrate old exact names and suffix-specific wildcards before enabling current producers.

Drain old queued model-bearing payloads and restart workers before upgrade. Current immutable event snapshots retain scalar identities and schema versions; they do not restore live models or translate retired serialized event classes.

## Deferred acceptance checks

Final testing must compare catalog types and defaults with actual classes, recursively inspect producer payloads, and prove source outer commit, nested rollback, unrelated connection independence and retry behavior without an uncommitted test-harness transaction. Cover canonical exact/cached/queued listeners, fakes and wildcard delivery, captured owner context, package no-op guards and observational failure containment for the selected deployment profile.

## Consumer event assertions

Use the canonical event class listed in the catalog for `Event::fake([...])` and `Event::assertDispatched(...)`. Laravel fake filters compare the emitted class name. Assert immutable snapshots and their captured owner identity, and migrate retired listener names to the current catalog before deployment.

