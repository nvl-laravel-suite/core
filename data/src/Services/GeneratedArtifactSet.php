<?php

declare(strict_types=1);

namespace Nvl\Data\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;

/**
 * Reads and validates the declaration files owned by Spatie's transform manifest.
 */
final readonly class GeneratedArtifactSet
{
    private const string TRANSFORMER_MANIFEST = 'typescript-transformer-manifest.json';

    /**
     * Create a generated artifact set reader.
     */
    public function __construct(
        private Repository $config,
        private Filesystem $files,
    ) {}

    /**
     * Return every generated declaration path in deterministic order.
     *
     * @return list<string>
     */
    public function paths(string $directory): array
    {
        $manifestPath = rtrim($directory, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .self::TRANSFORMER_MANIFEST;

        if (! $this->files->isFile($manifestPath)) {
            throw new RuntimeException('The TypeScript transformer manifest does not exist.');
        }

        $maximumManifestBytes = $this->positiveInteger(
            'nvl-data.typescript.max_manifest_bytes',
            5 * 1024 * 1024,
        );

        if ($this->files->size($manifestPath) > $maximumManifestBytes) {
            throw new RuntimeException('The TypeScript transformer manifest exceeds its configured size limit.');
        }

        try {
            $decoded = json_decode(
                $this->files->sharedGet($manifestPath),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('The TypeScript transformer manifest is invalid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('The TypeScript transformer manifest must contain an object.');
        }

        $maximumFiles = $this->positiveInteger('nvl-data.typescript.max_generated_files', 2_000);

        if (count($decoded) > $maximumFiles) {
            throw new RuntimeException('Generated declarations exceed the configured file-count limit.');
        }

        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $paths = [];
        $seenPaths = [];
        $totalBytes = 0;
        $maximumBytes = $this->positiveInteger(
            'nvl-data.typescript.max_generated_bytes',
            100 * 1024 * 1024,
        );

        foreach ($decoded as $path => $hash) {
            if (
                ! is_string($path)
                || ! is_string($hash)
                || preg_match('/^[a-f0-9]{32}$/', $hash) !== 1
            ) {
                throw new RuntimeException('The TypeScript transformer manifest contains an invalid record.');
            }

            $normalizedPath = $this->normalizeDeclarationPath($path);

            if (isset($seenPaths[$normalizedPath])) {
                throw new RuntimeException(
                    "The TypeScript transformer manifest contains duplicate path [{$normalizedPath}].",
                );
            }

            $seenPaths[$normalizedPath] = true;
            $absolutePath = $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);

            if (! $this->files->isFile($absolutePath)) {
                throw new RuntimeException("Generated declaration [{$normalizedPath}] does not exist.");
            }

            $resolvedPath = realpath($absolutePath);

            if (
                $resolvedPath === false
                || ! str_starts_with($resolvedPath, $directory.DIRECTORY_SEPARATOR)
            ) {
                throw new RuntimeException('Generated declaration symlinks cannot leave the output directory.');
            }

            $totalBytes += $this->files->size($resolvedPath);

            if ($totalBytes > $maximumBytes) {
                throw new RuntimeException('Generated declarations exceed the configured total-size limit.');
            }

            $paths[] = $normalizedPath;
        }

        sort($paths);

        return $paths;
    }

    /**
     * Return SHA-256 hashes keyed by generated declaration path.
     *
     * @return array<string, string>
     */
    public function hashes(string $directory): array
    {
        $hashes = [];
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);

        foreach ($this->paths($directory) as $path) {
            $absolutePath = $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
            $hash = hash_file('sha256', $absolutePath);

            if ($hash === false) {
                throw new RuntimeException("Unable to hash generated declaration [{$path}].");
            }

            $hashes[$path] = $hash;
        }

        return $hashes;
    }

    /**
     * Verify that Spatie's inventory hashes describe the completed declaration files.
     *
     * @param  list<string>  $paths
     */
    public function assertTransformerChecksums(string $directory, array $paths): void
    {
        try {
            $decoded = json_decode(
                $this->transformerManifest($directory),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('The TypeScript transformer manifest is invalid JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('The TypeScript transformer manifest must contain an object.');
        }

        $expectedHashes = [];

        foreach ($decoded as $path => $hash) {
            if (! is_string($path) || ! is_string($hash)) {
                throw new RuntimeException('The TypeScript transformer manifest contains an invalid record.');
            }

            $expectedHashes[$this->normalizeDeclarationPath($path)] = $hash;
        }

        $directory = rtrim($directory, DIRECTORY_SEPARATOR);

        foreach ($paths as $path) {
            $absolutePath = $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
            $actualHash = md5_file($absolutePath);
            $expectedHash = $expectedHashes[$path] ?? null;

            if (
                $actualHash === false
                || ! is_string($expectedHash)
                || ! hash_equals($expectedHash, $actualHash)
            ) {
                throw new RuntimeException(
                    "Generated declaration [{$path}] does not match the transformer manifest checksum.",
                );
            }
        }
    }

    /**
     * Read the raw Spatie transformer manifest.
     */
    public function transformerManifest(string $directory): string
    {
        $path = rtrim($directory, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .self::TRANSFORMER_MANIFEST;

        if (! $this->files->isFile($path)) {
            throw new RuntimeException('The TypeScript transformer manifest does not exist.');
        }

        $maximumManifestBytes = $this->positiveInteger(
            'nvl-data.typescript.max_manifest_bytes',
            5 * 1024 * 1024,
        );

        if ($this->files->size($path) > $maximumManifestBytes) {
            throw new RuntimeException('The TypeScript transformer manifest exceeds its configured size limit.');
        }

        return $this->files->sharedGet($path);
    }

    /**
     * Return the transformer manifest filename.
     */
    public function transformerManifestFilename(): string
    {
        return self::TRANSFORMER_MANIFEST;
    }

    /**
     * Normalize and validate one generated declaration path.
     */
    public function normalizeDeclarationPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $segments = explode('/', $normalized);

        if (
            $normalized === ''
            || $normalized !== trim($normalized)
            || str_contains($normalized, "\0")
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z0-9._\\/-]+\\.d\\.ts$/', $normalized) !== 1
            || array_filter(
                $segments,
                static fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..',
            ) !== []
        ) {
            throw new RuntimeException('Generated declaration paths must be safe relative .d.ts paths.');
        }

        return $normalized;
    }

    /**
     * Resolve a positive integer configuration value.
     */
    private function positiveInteger(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        if (! is_int($value) || $value < 1) {
            throw new RuntimeException("Configuration [{$key}] must be a positive integer.");
        }

        return $value;
    }
}
