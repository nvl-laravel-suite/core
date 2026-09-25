# Upgrading NVL Data

## Upgrading to 1.0

Version 1.0 removes application-specific source paths, old TypeScript namespaces, and speculative DTO doctrine.

1. Register source roots through `TypeScriptSourceRegistry` with stable keys.
2. Move pagination contracts to `Nvl\Data\Data\PaginatedCollection`.
3. Replace previous generated symbols with the `Nvl.<Package>.*` namespace.
4. Keep the default split writer, or set `writer=global` when a consumer requires one physical declaration file.
5. Remove legacy `RecordTypeScriptType` attributes. Use native PHP types,
   `DataCollectionOf`, nested DTOs, or
   `LiteralTypeScriptType('Record<string, unknown>')` for a genuinely dynamic
   record under TypeScript Transformer 3.3.
6. Run `php artisan nvl:data:types:generate`; this writes manifest schema version 2 with separate artifact `hash` and full metadata `revision`.
7. Commit the declaration files and manifest, then run `php artisan nvl:data:types:check`.
8. Move v2 `default_type_replacements` into
   `nvl-data.typescript.type_replacements` when practical. Legacy host maps are
   merged for staged upgrades.
9. Keep generated-type routes disabled unless middleware and artifact roots are explicitly configured.
10. On suite 1.0.2+, CI may pass `--fail-on-warning` explicitly to both
    generation and freshness checks. Suite 1.0.1 rejects that CLI option even
    though warning-free output is already enforced.
11. Exclude the configured declaration entrypoint, split declaration directory,
    and integrity manifest from ESLint and Prettier. Publish
    `nvl-data-generated-types-tooling` for the default-path fragments and keep
    `nvl:data:types:check` as their content-integrity gate.

Generation in an HTTP request is not supported in 1.0.
