# Security Policy

Submit reports through [this package's private vulnerability reporting form](https://github.com/nvl-laravel-suite/core/security/advisories/new).

The prepared `5.x` release line targets PHP 8.4–8.5 and Laravel 12–13. This compatibility matrix remains candidate/unverified until same-source execution evidence is reviewed; upstream security lifecycle limits still apply.

Report vulnerabilities privately through the repository host's security-advisory feature. Include the response code, status, serialized public context, exception chain, and impact.

Never place stack traces, SQL, filesystem paths, tokens, credentials, or arbitrary exception objects in public context. Internal diagnostic context must remain outside serialized responses.
