# NVL Core — API and usage

[← NVL Laravel Suite](https://github.com/nvl-laravel-suite)

For support, [open an issue](https://github.com/nvl-laravel-suite/core/issues). For vulnerabilities, use
[private reporting](https://github.com/nvl-laravel-suite/core/security/advisories/new). See [Contributing](CONTRIBUTING.md).

See the [installation and publishing guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/installation.md) for Composer setup, configuration, migration ownership, and agent skills.

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/core:^2.0` |
| Package identifier | `nvl/core` |
| PHP namespaces | `Nvl\Support`, `Nvl\Data` |
| Service providers | `Nvl\Support\Providers\SupportServiceProvider`, `Nvl\Data\Providers\DataServiceProvider` |
| Configuration | Packaged default: `data/config/nvl-data.php`; optional application copy: `config/nvl-data.php` |

## Purpose

`nvl/core` combines the Support and Data foundations in one Composer package. It provides transport-neutral business exceptions, stable response-code contracts, and shared package configuration merging for Laravel 13 on PHP 8.4+.

The Support namespace provides transport-neutral contracts and exceptions. The Data namespace provides Spatie Data transforms, pagination, TypeScript source registration, and declaration generation. Core has no internal NVL dependency. See the [Data API and usage](data/README.md) for Data configuration and commands.

## Requirements and installation

```bash
composer require nvl/core:^2.0
```

Laravel auto-discovers the Support and Data providers. Defaults work without
publishing configuration. Publish only what the application needs:

```bash
php artisan vendor:publish --tag=data-config
php artisan vendor:publish --tag=support-skills
php artisan vendor:publish --tag=data-skills
php artisan vendor:publish --tag=nvl-data-generated-types-tooling
```

`data-config` publishes `config/nvl-data.php`; `nvl-data-config` is an alias for
the same file, so use one tag. The generated-types tooling tag copies optional
ESLint and Prettier fragments. The skill tags publish
`.agents/skills/nvl-support` and `.agents/skills/nvl-data`. Laravel Boost can
also discover the bundled skills during `boost:install` or
`boost:update --discover` after adding Core to an existing application.

## Define a stable response code

Response codes are backed enums implementing `ResponseCode`:

```php
use Nvl\Support\Contracts\ResponseCode;

enum AccountResponseCode: string implements ResponseCode
{
    case Locked = 'account.locked';
}
```

The backed value is the stable machine code. Renaming an enum case does not change the public contract; changing its backed value does.

## Throw a transport-neutral failure

```php
use Nvl\Support\Exceptions\BusinessException;

throw new BusinessException(
    message: 'The account is locked.',
    responseCode: AccountResponseCode::Locked,
    suggestedStatus: 423,
    publicContext: ['retryable' => false],
    diagnosticContext: ['rule' => 'failed-attempt-limit'],
);
```

The exception exposes:

- `responseCode()`: the backed machine code or `null`;
- `suggestedStatus()`: presentation guidance from 100 through 599;
- `publicContext()`: data a consumer adapter may serialize;
- `context()`: internal diagnostic data for logging and reporting;
- the standard previous-exception chain.

`BusinessException` does not render HTTP, JSON, CLI, or queue responses. The consuming application maps it to the appropriate transport and may choose a different presentation status.

## Context safety

Only deliberately safe scalar or structured values belong in `publicContext`. Do not include:

- credentials, tokens, or secrets;
- stack traces or exception objects;
- SQL or database connection information;
- storage paths;
- unredacted personal data;
- arbitrary model serialization.

Diagnostic context is never automatically safe to expose. Applications must keep it on trusted reporting and logging paths.

## Failure behavior

Constructing an exception with a suggested status outside 100–599 throws `SupportException`. A missing response code is valid for failures that have no stable public machine contract. The original exception can be retained through `previous`.

## Non-goals

- HTTP exception rendering
- validation response construction
- models, persistence, and migrations
- consumer-specific error catalogs

Pagination belongs to the `Nvl\Data` namespace in Core. Domain response codes belong to the package or application that owns the behavior.

## Verification

The Support tests cover standalone boundaries and discovery, backed response-code enforcement, status boundaries, public and diagnostic context separation, serialization safety, exception chaining, skill publication, and architecture constraints. Release checks run Pest, Pint, PHPStan at maximum strictness, Composer validation, dependency analysis, and distribution validation.

See [Data documentation](data/README.md), [upgrading](UPGRADING.md), [security](SECURITY.md), [contributing](CONTRIBUTING.md), and [changelog](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE).
