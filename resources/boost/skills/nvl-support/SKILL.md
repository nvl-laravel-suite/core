---
name: nvl-support
description: Implement, integrate, test, or review nvl/support in Laravel 13. Use for transport-neutral business exceptions, stable response codes, safe public error context, internal diagnostic context, exception chaining, or package-family foundation boundaries.
---

# NVL Support

Keep Support tiny, transport-neutral, and dependency-free inside the NVL family.

## Model failures

- Implement `ResponseCode` with stable machine-readable backed values.
- Pass suggested presentation status to `BusinessException` as adapter guidance.
- Throw `BusinessException` when callers need a safe code, message, and public context.
- Preserve the previous exception for internal diagnostics.
- Keep internal diagnostic context separate from serialized public context.
- Treat suggested HTTP status as presentation guidance; controllers or exception handlers own the response.

## Protect the boundary

- Do not add DTOs, pagination, Eloquent models, controllers, routes, migrations, or domain helpers.
- Do not add dependencies on other NVL packages.
- Do not serialize stack traces, SQL, storage paths, tokens, or arbitrary exception context.

## Verify

Test code and status validation, exception chaining, serialization safety, enum completeness, standalone installation, and architecture constraints.

## Configurable-tenancy release discipline

- Preserve disabled compatibility and package independence; tenant support never creates an undeclared Auth or Suite dependency.
- Use registered package-owned resources, adoption adapters, Actions, and lifecycle APIs. Never add a generic tenant delete-all path or raw cross-package cleanup.
- Treat mapping/configuration hashes, interruption checkpoints, conservation evidence, worker context, tenant-leading queries, and standalone consumption as release contracts.
- The P2 implementation is present, but consolidated runtime verification is pending. Do not claim release readiness until the complete matrix passes.
