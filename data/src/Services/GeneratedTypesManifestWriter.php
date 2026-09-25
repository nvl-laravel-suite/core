<?php

declare(strict_types=1);

namespace Nvl\Data\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;

/**
 * Persists generated-type manifest metadata beside declaration artifacts.
 */
final readonly class GeneratedTypesManifestWriter
{
    /**
     * Create a generated-types manifest writer.
     */
    public function __construct(
        private Repository $config,
        private Filesystem $files,
        private GeneratedTypeFileCatalog $catalog,
    ) {}

    /**
     * Build and write the current manifest atomically.
     *
     * @throws JsonException
     */
    public function write(): string
    {
        return $this->writeManifest($this->catalog->freshManifest());
    }

    /**
     * Write one pre-built manifest atomically and return its absolute path.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @throws JsonException
     */
    public function writeManifest(array $manifest): string
    {
        $path = $this->catalog->manifestPath();
        $contents = $this->encodedManifest($manifest);

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->replace($path, $contents);

        return $path;
    }

    /**
     * Fail before publication when a generated manifest exceeds its write bound.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @throws JsonException
     */
    public function assertWithinLimits(array $manifest): void
    {
        $this->encodedManifest($manifest);
    }

    /**
     * Encode and bound one integrity manifest.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @throws JsonException
     */
    private function encodedManifest(array $manifest): string
    {
        $contents = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
        $maximumBytes = $this->config->get(
            'nvl-data.typescript.max_manifest_bytes',
            5 * 1024 * 1024,
        );

        if (! is_int($maximumBytes) || $maximumBytes < 1) {
            throw new RuntimeException(
                'Configuration [nvl-data.typescript.max_manifest_bytes] must be a positive integer.',
            );
        }

        if (strlen($contents) > $maximumBytes) {
            throw new RuntimeException(
                'The generated types integrity manifest exceeds its configured size limit.',
            );
        }

        return $contents;
    }
}
