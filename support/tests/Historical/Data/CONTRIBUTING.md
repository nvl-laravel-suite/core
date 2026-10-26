# Contributing to NVL Data

Data ships in `nvl/core`. See the [Core contribution guide](../CONTRIBUTING.md) for the public issue path and release workflow.

Changes must preserve deterministic DTO and TypeScript behavior on PHP 8.3–8.5 and Laravel 13.

Test optional/null semantics, nested transforms, path boundaries, duplicate symbols, manifests, archives, and combined generation. Run Pest, Pint, PHPStan at maximum strictness, `nvl:data:types:check`, `tsc --noEmit`, Composer validation, dependency analysis, and package distribution validation.

Public DTO or generated-symbol changes require changelog, README, skill, and upgrade documentation.
