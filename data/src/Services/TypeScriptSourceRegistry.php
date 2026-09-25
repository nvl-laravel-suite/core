<?php

declare(strict_types=1);

namespace Nvl\Data\Services;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Collects validated application and package source directories for TypeScript discovery.
 */
final class TypeScriptSourceRegistry
{
    /**
     * @var array<string, array{package: string|null, priority: int}>
     */
    private array $paths = [];

    /**
     * Create a source registry seeded from package configuration.
     */
    public function __construct(
        private readonly Repository $config,
        private readonly TypeScriptPathGuard $pathGuard,
    ) {
        $configuredPaths = $this->config->get('nvl-data.typescript.source_paths', []);

        if (! is_array($configuredPaths)) {
            throw new RuntimeException('nvl-data.typescript.source_paths must be an array.');
        }

        foreach ($configuredPaths as $path) {
            if (! is_string($path)) {
                throw new RuntimeException('Every configured TypeScript source path must be a string.');
            }

            $this->register($path, priority: 100);
        }
    }

    /**
     * Register one existing source directory.
     */
    public function register(string $path, ?string $package = null, int $priority = 0): self
    {
        $resolvedPath = $this->pathGuard->existingDirectory($path);

        if (isset($this->paths[$resolvedPath])) {
            $existing = $this->paths[$resolvedPath];

            if ($existing['package'] !== null && $package !== null && $existing['package'] !== $package) {
                throw new RuntimeException(
                    "TypeScript source [{$resolvedPath}] is already registered by [{$existing['package']}].",
                );
            }

            $this->paths[$resolvedPath] = [
                'package' => $existing['package'] ?? $package,
                'priority' => max($existing['priority'], $priority),
            ];

            return $this;
        }

        $this->paths[$resolvedPath] = [
            'package' => $package,
            'priority' => $priority,
        ];

        return $this;
    }

    /**
     * Register multiple existing source directories.
     *
     * @param  iterable<int, string>  $paths
     */
    public function registerMany(
        iterable $paths,
        ?string $package = null,
        int $priority = 0,
    ): self {
        foreach ($paths as $path) {
            $this->register($path, $package, $priority);
        }

        return $this;
    }

    /**
     * Return every unique registered source directory.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return array_column($this->descriptors(), 'path');
    }

    /**
     * Return deterministic source diagnostics for manifests and doctor commands.
     *
     * @return list<array{path: string, package: string|null, priority: int}>
     */
    public function descriptors(): array
    {
        $sources = [];

        foreach ($this->paths as $path => $metadata) {
            $sources[] = [
                'path' => $path,
                'package' => $metadata['package'],
                'priority' => $metadata['priority'],
            ];
        }

        usort(
            $sources,
            static fn (array $left, array $right): int => [
                -$left['priority'],
                $left['package'] ?? '',
                $left['path'],
            ] <=> [
                -$right['priority'],
                $right['package'] ?? '',
                $right['path'],
            ],
        );

        return $sources;
    }
}
