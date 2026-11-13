# Security Policy

Submit reports through [this package's private vulnerability reporting form](https://github.com/nvl-laravel-suite/core/security/advisories/new).

Security fixes are provided for the published `7.x` release line. Earlier lines no longer receive fixes. Composer declares PHP `^8.4` and Laravel `^13.30`. The local Dagger release gate verifies PHP 8.4/Laravel 13 with SQLite and PostgreSQL 17 persistence contracts. PHP 8.5 requires separate compatibility evidence. Upstream security lifecycle limits still apply.

Report vulnerabilities privately through the repository host's security-advisory feature. Include the response code, status, serialized public context, exception chain, and impact.

Never place stack traces, SQL, filesystem paths, tokens, credentials, or arbitrary exception objects in public context. Internal diagnostic context must remain outside serialized responses.
