<?php

declare(strict_types=1);

namespace Nvl\Data\Services;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Confines TypeScript sources and generated artifacts to configured project roots.
 */
final readonly class TypeScriptPathGuard
{
    /**
     * Create a TypeScript path guard.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Resolve an existing source directory inside an allowed root.
     */
    public function existingDirectory(string $path): string
    {
        $resolvedPath = realpath($path);

        if ($resolvedPath === false || ! is_dir($resolvedPath)) {
            throw new RuntimeException("TypeScript source directory [{$path}] does not exist.");
        }

        $this->assertInsideAllowedRoot($resolvedPath);

        return rtrim($resolvedPath, DIRECTORY_SEPARATOR);
    }

    /**
     * Validate an output directory before it is created or written.
     */
    public function outputDirectory(string $path): string
    {
        $path = trim($path);

        if ($path === '' || str_contains($path, "\0") || ! $this->isAbsolute($path)) {
            throw new RuntimeException('The TypeScript output directory must be an absolute path.');
        }

        $existingAncestor = $path;

        while (! file_exists($existingAncestor)) {
            $parent = dirname($existingAncestor);

            if ($parent === $existingAncestor) {
                throw new RuntimeException("TypeScript output directory [{$path}] has no resolvable ancestor.");
            }

            $existingAncestor = $parent;
        }

        $resolvedAncestor = realpath($existingAncestor);

        if ($resolvedAncestor === false || ! is_dir($resolvedAncestor)) {
            throw new RuntimeException("TypeScript output ancestor [{$existingAncestor}] is invalid.");
        }

        $this->assertInsideConfiguredRoot($resolvedAncestor);

        $relativeSuffix = ltrim(substr($path, strlen($existingAncestor)), DIRECTORY_SEPARATOR);
        $normalizedSuffix = $this->normalizeRelativePath($relativeSuffix);

        return rtrim(
            $resolvedAncestor.($normalizedSuffix === '' ? '' : DIRECTORY_SEPARATOR.$normalizedSuffix),
            DIRECTORY_SEPARATOR,
        );
    }

    /**
     * Resolve an artifact path without following existing or dangling output symlinks.
     */
    public function artifactPath(string $directory, string $relativePath): string
    {
        $directory = $this->outputDirectory($directory);
        $normalized = str_replace('\\', '/', $relativePath);
        $segments = explode('/', $normalized);

        if ($normalized === ''
            || $normalized !== trim($normalized)
            || str_contains($normalized, "\0")
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:/', $normalized) === 1
            || array_filter(
                $segments,
                static fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..',
            ) !== []) {
            throw new RuntimeException('Generated artifact paths must be safe and relative.');
        }

        $path = $directory;

        foreach ($segments as $segment) {
            $path .= DIRECTORY_SEPARATOR.$segment;
            clearstatcache(true, $path);

            if (is_link($path)) {
                throw new RuntimeException('Generated artifact paths cannot contain symbolic links.');
            }
        }

        return $path;
    }

    /**
     * Assert that an existing path resolves within one configured allowed root.
     */
    public function assertInsideAllowedRoot(string $path): void
    {
        $this->assertInsideRoots($path, includeInstalledPackages: true);
    }

    /**
     * Assert that an output path resolves within an explicitly configured root.
     */
    private function assertInsideConfiguredRoot(string $path): void
    {
        $this->assertInsideRoots($path, includeInstalledPackages: false);
    }

    /**
     * Assert that an existing path resolves within one permitted root.
     */
    private function assertInsideRoots(string $path, bool $includeInstalledPackages): void
    {
        $resolvedPath = realpath($path);

        if ($resolvedPath === false) {
            throw new RuntimeException("Path [{$path}] cannot be resolved.");
        }

        foreach ($this->allowedRoots($includeInstalledPackages) as $root) {
            if ($resolvedPath === $root || str_starts_with($resolvedPath, $root.DIRECTORY_SEPARATOR)) {
                return;
            }
        }

        throw new RuntimeException("Path [{$path}] is outside nvl-data.typescript.allowed_roots.");
    }

    /**
     * Return configured, canonical allowed roots.
     *
     * @return list<string>
     */
    private function allowedRoots(bool $includeInstalledPackages): array
    {
        $configuredRoots = $this->config->get('nvl-data.typescript.allowed_roots', [base_path()]);

        if (! is_array($configuredRoots) || $configuredRoots === []) {
            throw new RuntimeException('nvl-data.typescript.allowed_roots must contain at least one path.');
        }

        $roots = [];

        foreach ($configuredRoots as $root) {
            if (! is_string($root)) {
                continue;
            }

            $resolvedRoot = realpath($root);

            if ($resolvedRoot !== false && is_dir($resolvedRoot)) {
                $roots[] = rtrim($resolvedRoot, DIRECTORY_SEPARATOR);
            }
        }

        if ($includeInstalledPackages) {
            foreach (InstalledVersions::getInstalledPackages() as $package) {
                if (! str_starts_with($package, 'nvl/')) {
                    continue;
                }

                $installPath = InstalledVersions::getInstallPath($package);
                $resolvedPath = is_string($installPath) ? realpath($installPath) : false;

                if ($resolvedPath !== false && is_dir($resolvedPath)) {
                    $roots[] = rtrim($resolvedPath, DIRECTORY_SEPARATOR);
                }
            }
        }

        if ($roots === []) {
            throw new RuntimeException('No configured TypeScript allowed root exists.');
        }

        sort($roots);

        return array_values(array_unique($roots));
    }

    /**
     * Normalize a relative suffix and reject traversal segments.
     */
    private function normalizeRelativePath(string $path): string
    {
        $segments = preg_split('~[\\\\/]~', $path);

        if ($segments === false) {
            throw new RuntimeException('Unable to normalize the TypeScript output directory.');
        }

        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new RuntimeException('TypeScript paths cannot traverse parent directories.');
            }

            $normalized[] = $segment;
        }

        return implode(DIRECTORY_SEPARATOR, $normalized);
    }

    /**
     * Determine whether a path is absolute on Unix or Windows.
     */
    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
