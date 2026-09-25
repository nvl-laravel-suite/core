# Security Policy

Security fixes are provided for the current `2.x` release line on PHP 8.3–8.4 and Laravel 13.

Report vulnerabilities privately through the repository host's security-advisory feature. Include the response code, status, serialized public context, exception chain, and impact.

Never place stack traces, SQL, filesystem paths, tokens, credentials, or arbitrary exception objects in public context. Internal diagnostic context must remain outside serialized responses.
