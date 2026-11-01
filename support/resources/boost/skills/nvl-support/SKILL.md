---
name: nvl-support
description: Use when integrating or reviewing the native Support namespace in nvl/core on PHP 8.4 and Laravel 13, including errors, locale state, owner identity, infrastructure configuration and package boundaries.
---

# NVL Light Support

Core discovers Support and Locale providers. It supplies narrow shared contracts and safe unowned defaults. It does not activate a Data/TypeScript runtime, generic HTTP renderer, routes, Doctor/schema/install commands, consumer-policy analyzer or deprecated Tenancy bridge.

## Read by task

- [Installation and publication](references/installation.md): Composer discovery, exact config/skill tags and migration absence.
- [Consumer recipes](references/usage.md): owner registration, locale state, safe errors, commit-aware events, required bindings and optional Ownership ports.
- [Public API](references/api.md): exact namespaces, arguments, generic/array contracts, defaults and return values. Search the relevant capability rather than loading the whole catalog.

The host must choose its model classes, policy and storage connection. Identity registration and actor normalization supply facts, not authorization. Read the complete operation's owning package skill when composing domain changes.

## Package and consumer ownership

Package services own complete domain operations: validation, authorization seams, ownership, transactions, concurrency, idempotency and recovery. Consumers own DTOs, Actions, controllers, requests, routes, resources and frontend types. Use native typed arguments and documented arrays; keep useful models available through their documented safe-read contract. Do not add a generic DTO/Result framework or a forwarding Action layer.

Preserve omitted versus explicit null. Validate every service call and reject unknown/protected input. Consumer validation never supplies a missing domain guarantee. Domain effects follow the actual outer transaction commit; preserve durable recovery where external effects need it.

## Errors and identity

Use stable backed ResponseCode values and BusinessException for safe code/message/public context. Preserve previous exceptions internally; never serialize traces, SQL, tokens, paths or arbitrary diagnostic context. Suggested presentation status is adapter guidance; the consumer owns rendering.

Register owner classes in `nvl-core.owners` and retain Laravel morph identities. Identity registration does not authorize access or enforce a morph map. `PublicActor::Anonymous` and `SystemActor::Background` are separate explicit identities; neither grants access automatically and null never grants privileged execution.

Use LocaleCatalog and scoped locale state; restore nested state after exceptions and job completion. Constructor-inject domain collaborators. Core preserves conditional host bindings and native Laravel bus/batch defaults.

## Optional Ownership

Core's ports admit explicitly unowned canonical storage. Owned or previously adopted storage requires the enforcing Ownership runtime and fails closed when it is unavailable. Do not replace absence checks with permissive no-ops or infer ownership from submitted fields.

Core guards captured queue authority before supported worker/retry restoration. Enabled bus/batch/producer enforcement and recovery commands belong to explicitly enabled `nvl/ownership`. Native batch reads outside that runtime are not an ownership service guarantee. Retired Tenancy envelopes require rejection, never translation into current authority.

## Configuration and verification

Use canonical `nvl-core` configuration and `nvl-core-config`, `nvl-core-translations`, `nvl-core-skills` publication tags. Resolve shared storage/options through PackageStorage and PackageOptions where supported. Keep authorization/capability decisions separate from infrastructure defaults.

Core alone has no `nvl:doctor`, `nvl:install`, schema or Data commands. Use normal Laravel discovery, config/route caches and explicit migrations; do not install a host analyzer or HTTP renderer from an old Core runbook. Follow native Ownership guidance when isolation is enabled.

Verify production archives with optional libraries physically absent, zero boot ownership queries, native bus/batch defaults, owned-storage rejection, safe exception serialization and worker state restoration. Use instance-owned Testing\FakeCalls for supported leaf fakes; retain real persistence/authorization/recovery tests. Package-local evidence does not certify the whole suite.
