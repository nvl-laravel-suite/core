<?php

declare(strict_types=1);

namespace Nvl\Data\Services;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Validates the opt-in generated declaration route boundary before registration.
 */
final readonly class GeneratedTypesRouteConfiguration
{
    /**
     * Create the generated-types route configuration.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Determine whether generated declaration routes are explicitly enabled.
     */
    public function enabled(): bool
    {
        $enabled = $this->config->get(
            'nvl-data.typescript.routes.enabled',
            false,
        );

        if (! is_bool($enabled)) {
            throw new RuntimeException(
                'nvl-data.typescript.routes.enabled must be a boolean.',
            );
        }

        return $enabled;
    }

    /**
     * Determine whether ZIP archive delivery is explicitly enabled.
     */
    public function archiveEnabled(): bool
    {
        $enabled = $this->config->get(
            'nvl-data.typescript.routes.archive_enabled',
            true,
        );

        if (! is_bool($enabled)) {
            throw new RuntimeException(
                'nvl-data.typescript.routes.archive_enabled must be a boolean.',
            );
        }

        return $enabled;
    }

    /**
     * Return the non-empty, route-safe endpoint prefix.
     */
    public function prefix(): string
    {
        $configured = $this->config->get(
            'nvl-data.typescript.routes.prefix',
            'api/v1/nvl/types',
        );
        $prefix = is_string($configured) ? trim($configured, '/') : null;

        if (
            ! is_string($prefix)
            || $prefix === ''
            || $prefix !== trim($prefix)
            || preg_match('/^[A-Za-z0-9_-]+(?:\\/[A-Za-z0-9_-]+)*$/', $prefix) !== 1
        ) {
            throw new RuntimeException(
                'nvl-data.typescript.routes.prefix must be a non-empty route-safe relative path.',
            );
        }

        return $prefix;
    }

    /**
     * Return validated middleware without silently dropping invalid entries.
     *
     * @return list<string>
     */
    public function middleware(): array
    {
        $configured = $this->config->get(
            'nvl-data.typescript.routes.middleware',
            ['web', 'auth', 'throttle:60,1'],
        );

        if (is_string($configured)) {
            $configured = [$configured];
        }

        if (! is_array($configured) || ! array_is_list($configured) || $configured === []) {
            throw new RuntimeException(
                'nvl-data.typescript.routes.middleware must be a non-empty list of middleware strings.',
            );
        }

        foreach ($configured as $middleware) {
            if (
                ! is_string($middleware)
                || trim($middleware) === ''
                || trim($middleware) !== $middleware
            ) {
                throw new RuntimeException(
                    'nvl-data.typescript.routes.middleware must contain only non-empty, trimmed strings.',
                );
            }
        }

        return $configured;
    }
}
