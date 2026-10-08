# Security Policy

Submit reports through [this package's private vulnerability reporting form](https://github.com/nvl-laravel-suite/core/security/advisories/new).

Security fixes are provided for the published `5.x` release line. Composer declares PHP `^8.4` and Laravel `^12.0|^13.0`. The local Dagger release gate verifies PHP 8.4/Laravel 13 with MySQL 8.4 and PostgreSQL 17 persistence contracts; PHP 8.5, Laravel 12 and MariaDB require separate compatibility evidence. Upstream security lifecycle limits still apply.

Report vulnerabilities privately through the repository host's security-advisory feature. Include the response code, status, serialized public context, exception chain, and impact.

Never place stack traces, SQL, filesystem paths, tokens, credentials, or arbitrary exception objects in public context. Internal diagnostic context must remain outside serialized responses.
