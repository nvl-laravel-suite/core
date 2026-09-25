# Upgrading NVL Core

## Moving to Core 2.0

Install `nvl/core` in place of `nvl/support` and `nvl/data`. The `Nvl\Support` and `Nvl\Data` PHP namespaces and their service providers remain available from Core.

## Historical Support 1.0 guidance

Version 1.0 is a deliberately small foundation.

1. Move paginated DTOs to `nvl/data`.
2. Register TypeScript sources with `nvl/data`.
3. Keep HTTP response creation in the application exception handler.
4. Use the exception status only as a suggested presentation status.
5. Separate public exception context from internal diagnostics.
6. Remove consumer helpers, models, controllers, routes, and migrations from Support integrations.
