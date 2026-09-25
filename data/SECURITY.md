# Security Policy

Security fixes are provided for the current `2.x` release line on PHP 8.3–8.5 and Laravel 13.

Report vulnerabilities privately through the repository host's security-advisory feature. Include the affected version, reproduction, path configuration, impact, and mitigation. Do not disclose path-traversal or symlink-escape payloads publicly before a fix is available.

Generated-type routes must remain disabled by default. When enabled, protect them with middleware, serve only manifest-listed artifacts, and never expose arbitrary filesystem paths.
