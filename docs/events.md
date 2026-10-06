# NVL Core events

This document describes the implemented source behavior. Executable acceptance proof is pending the final testing phase. The authoritative machine-readable schema is [event-catalog.json](../resources/event-catalog.json), catalog version `1`. Event `schemaVersion` is independent of catalog version.

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

## Major 5 listener and queue migration

Legacy names are deprecated for major 5 and removed no earlier than major 6. class_alias preserves imports, instanceof, listener type hints and the new versioned constructor, not the former model-bearing API.

The native Laravel exact-listener bridge reads getRawListeners at delivery time and prepares legacy listeners through makeListener; strict-identical canonical registrations are skipped. Late registration, subscribers, cached discovery and queued listener preparation use the native dispatcher seams; proof is pending.

One canonical object is emitted once. Native wildcard/interface listeners see the canonical event once; suffix-specific *Event wildcards must migrate. The bridge does not redispatch a legacy string.

Drain old queued model-bearing payloads and restart workers before upgrade. New serialized alias instances restore the canonical versioned class without model restoration.

Event fakes/filters and assertions use canonical class names. EventAliases::canonicalName() helps migrate old names; old fake filters are not automatically rewritten.

Host custom dispatchers are retained. Explicit EventAliases::listen($event, $listener) maps through the canonical adapter; absent native bridging/host adapter, Doctor reports the deprecated-name limitation.

```php
$aliases = app(\Nvl\Support\Events\EventAliases::class);
$canonical = $aliases->canonicalName($legacyClass);
$aliases->listen($canonical, $listener);
```

Exactly six aliases are supported: ActivityLogPurgeQueuedEvent, FormChangedEvent, FormEntryChangedEvent, MediaUploadedEvent, MetafieldSetEvent and MetafieldsSyncedEvent. Other canonical event names are retained.

## Deferred acceptance checks

Final testing must compare catalog types/defaults/aliases with actual classes, recursively inspect producer payloads, and prove source outer commit, nested rollback, unrelated connection independence and retry behavior without an uncommitted test-harness transaction. Where applicable it must cover legacy exact/cached/queued listeners, canonical fakes and wildcard delivery, tenant capture, package no-op guards and observational failure containment. This document does not report those checks as passing.
