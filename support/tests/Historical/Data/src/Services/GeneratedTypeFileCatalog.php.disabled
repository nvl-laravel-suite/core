<?php

declare(strict_types=1);

namespace Nvl\Data\Services;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;
use ZipArchive;

/**
 * Catalogs, verifies, and archives manifest-bound generated declaration files.
 *
 * @phpstan-type ArtifactRecord array{path: string, filename: string, bytes: int, hash: string, lastModified: string}
 * @phpstan-type ScopeRecord array{scope: string, path: string, filename: string, bytes: int, hash: string, lastModified: string}
 * @phpstan-type TypesManifest array{
 *     schemaVersion: int,
 *     hash: string,
 *     revision: string,
 *     transformerHash: string,
 *     generatedAt: string,
 *     packages: array<string, string>,
 *     transformers: array<string, string>,
 *     sources: list<array{package: string|null, priority: int}>,
 *     symbols: list<array{phpType: string, typescriptType: string, package: string|null, source: string}>,
 *     entrypoint: ArtifactRecord,
 *     files: list<ScopeRecord>,
 *     archive: array{enabled: bool, filename: string}
 * }
 */
final readonly class GeneratedTypeFileCatalog
{
    private const int HASH_PREFIX_LENGTH = 12;

    private const int ARCHIVE_ENTRY_TIMESTAMP = 315532800;

    private const int MANIFEST_SCHEMA_VERSION = 2;

    /**
     * Create the generated declaration catalog.
     */
    public function __construct(
        private Repository $config,
        private Filesystem $files,
        private TypeScriptPathGuard $pathGuard,
        private TypeScriptSourceRegistry $sources,
        private TypeScriptSourceInspector $sourceInspector,
        private GeneratedArtifactSet $artifacts,
        private GeneratedTypesLock $lock,
        private GeneratedTypesRouteConfiguration $routes,
    ) {}

    /**
     * Read the persisted manifest that is safe for request-time delivery.
     *
     * @return TypesManifest
     */
    public function manifest(): array
    {
        return $this->lock->read(function (): array {
            $manifest = $this->persistedManifest();
            $this->assertPublishedArtifactsMatch($manifest);

            return $manifest;
        });
    }

    /**
     * Build a fresh manifest from one completed transform output directory.
     *
     * @return TypesManifest
     */
    public function freshManifest(?string $directory = null): array
    {
        $directory = $directory === null
            ? $this->outputDirectory()
            : $this->pathGuard->outputDirectory($directory);
        $artifactPaths = $this->artifacts->paths($directory);
        $this->artifacts->assertTransformerChecksums($directory, $artifactPaths);
        $entrypointPath = $this->entrypointPath();

        if (! in_array($entrypointPath, $artifactPaths, true)) {
            throw new RuntimeException("Generated entrypoint [{$entrypointPath}] is absent from the transformer manifest.");
        }

        $entrypoint = $this->metadataFor($entrypointPath, $directory);
        $files = $this->scopeRecords($artifactPaths, $entrypointPath, $directory);
        $hashParts = [$entrypoint['path'].':'.$entrypoint['hash']];

        foreach ($files as $file) {
            $hashParts[] = $file['path'].':'.$file['hash'];
        }

        $hash = hash('sha256', implode('|', $hashParts));
        $timestamps = [
            strtotime($entrypoint['lastModified']) ?: 0,
            ...array_map(
                static fn (array $file): int => strtotime($file['lastModified']) ?: 0,
                $files,
            ),
        ];

        $manifest = [
            'schemaVersion' => self::MANIFEST_SCHEMA_VERSION,
            'hash' => $hash,
            'transformerHash' => hash(
                'sha256',
                $this->artifacts->transformerManifest($directory),
            ),
            'generatedAt' => gmdate(DATE_ATOM, max($timestamps)),
            'packages' => $this->packageVersions(),
            'transformers' => $this->transformerVersions(),
            'sources' => array_map(
                static fn (array $source): array => [
                    'package' => $source['package'],
                    'priority' => $source['priority'],
                ],
                $this->sources->descriptors(),
            ),
            'symbols' => $this->sourceInspector->symbols(),
            'entrypoint' => $entrypoint,
            'files' => $files,
            'archive' => [
                'enabled' => $this->archiveEnabled(),
                'filename' => $this->archiveFilename($hash),
            ],
        ];

        $manifest['revision'] = $this->manifestRevision($manifest);

        return $manifest;
    }

    /**
     * Read and verify the persisted declaration entrypoint.
     *
     * @return array{
     *     path: string,
     *     filename: string,
     *     bytes: int,
     *     hash: string,
     *     lastModified: string,
     *     contents: string
     * }
     */
    public function entrypoint(): array
    {
        return $this->lock->read(function (): array {
            $manifest = $this->persistedManifest();
            $this->assertPublishedArtifactsMatch($manifest);

            return [
                ...$manifest['entrypoint'],
                'contents' => $this->verifiedContents($manifest['entrypoint']),
            ];
        });
    }

    /**
     * Find, read, and verify one persisted declaration scope.
     *
     * @return array{
     *     scope: string,
     *     path: string,
     *     filename: string,
     *     bytes: int,
     *     hash: string,
     *     lastModified: string,
     *     contents: string
     * }|null
     */
    public function findScope(string $scope): ?array
    {
        return $this->lock->read(function () use ($scope): ?array {
            $manifest = $this->persistedManifest();
            $this->assertPublishedArtifactsMatch($manifest);

            foreach ($manifest['files'] as $record) {
                if ($record['scope'] !== $scope) {
                    continue;
                }

                return [
                    ...$record,
                    'contents' => $this->verifiedContents($record),
                ];
            }

            return null;
        });
    }

    /**
     * Create a verified temporary archive containing every persisted declaration.
     *
     * @return array{path: string, hash: string, filename: string}
     */
    public function createArchive(): array
    {
        return $this->lock->read(function (): array {
            if (! $this->archiveEnabled()) {
                throw new RuntimeException('Generated type archives are disabled or the ZIP extension is unavailable.');
            }

            $manifest = $this->persistedManifest();
            $this->assertPublishedArtifactsMatch($manifest);
            $records = [$manifest['entrypoint'], ...$manifest['files']];
            $this->assertArchiveBounds($records);
            $archivePath = $this->temporaryArchivePath();
            $zip = new ZipArchive;
            $archiveCreated = false;
            $archiveOpen = false;

            try {
                if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('Unable to create the generated types archive.');
                }

                $archiveOpen = true;

                foreach ($records as $record) {
                    if (! $zip->addFromString($record['path'], $this->verifiedContents($record))) {
                        throw new RuntimeException("Unable to add generated declaration [{$record['path']}] to the archive.");
                    }

                    if (! $zip->setMtimeName($record['path'], self::ARCHIVE_ENTRY_TIMESTAMP)) {
                        throw new RuntimeException(
                            "Unable to normalize the timestamp for generated declaration [{$record['path']}].",
                        );
                    }
                }

                $archiveOpen = false;

                if (! $zip->close()) {
                    throw new RuntimeException('Unable to finalize the generated types archive.');
                }

                $archiveCreated = true;

                return [
                    'path' => $archivePath,
                    'hash' => $manifest['hash'],
                    'filename' => $manifest['archive']['filename'],
                ];
            } finally {
                if ($archiveOpen) {
                    $zip->close();
                }

                if (! $archiveCreated) {
                    $this->files->delete($archivePath);
                }
            }
        });
    }

    /**
     * Build a content-addressed archive filename.
     */
    public function archiveFilename(string $hash): string
    {
        $name = $this->config->get('nvl-data.typescript.routes.archive_name', 'generated-types');
        $safeName = is_string($name) ? preg_replace('/[^A-Za-z0-9_-]/', '-', $name) : 'generated-types';

        return ($safeName ?: 'generated-types')
            .'-'
            .substr($hash, 0, self::HASH_PREFIX_LENGTH)
            .'.zip';
    }

    /**
     * Determine whether archive downloads can be served.
     */
    public function archiveEnabled(): bool
    {
        return $this->routes->archiveEnabled()
            && class_exists(ZipArchive::class);
    }

    /**
     * Return the absolute persisted NVL manifest path.
     */
    public function manifestPath(): string
    {
        return $this->pathGuard->artifactPath($this->outputDirectory(), $this->manifestFilename());
    }

    /**
     * Build scoped records for every non-entrypoint generated declaration.
     *
     * @param  list<string>  $artifactPaths
     * @return list<ScopeRecord>
     */
    private function scopeRecords(
        array $artifactPaths,
        string $entrypointPath,
        string $directory,
    ): array {
        $records = [];
        $seenScopes = [];

        foreach ($artifactPaths as $path) {
            if ($path === $entrypointPath) {
                continue;
            }

            $scope = $this->scopeFor($path);

            if (isset($seenScopes[$scope])) {
                throw new RuntimeException("Generated declaration scope [{$scope}] is not unique.");
            }

            $seenScopes[$scope] = true;
            $records[] = [
                'scope' => $scope,
                ...$this->metadataFor($path, $directory),
            ];
        }

        usort($records, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);

        return $records;
    }

    /**
     * Build safe public metadata for one declaration path.
     *
     * @return ArtifactRecord
     */
    private function metadataFor(string $relativePath, string $directory): array
    {
        $absolutePath = $this->absolutePath($relativePath, $directory);
        $hash = hash_file('sha256', $absolutePath);

        if ($hash === false) {
            throw new RuntimeException("Unable to hash generated declaration [{$relativePath}].");
        }

        return [
            'path' => $relativePath,
            'filename' => basename($relativePath),
            'bytes' => $this->files->size($absolutePath),
            'hash' => $hash,
            'lastModified' => gmdate(DATE_ATOM, $this->files->lastModified($absolutePath)),
        ];
    }

    /**
     * Read the persisted manifest and validate its public shape.
     *
     * @return TypesManifest
     */
    private function persistedManifest(): array
    {
        $path = $this->manifestPath();

        if (! $this->files->isFile($path)) {
            throw new RuntimeException('The generated types integrity manifest does not exist.');
        }

        $maximumBytes = $this->positiveInteger(
            'nvl-data.typescript.max_manifest_bytes',
            5 * 1024 * 1024,
        );

        if ($this->files->size($path) > $maximumBytes) {
            throw new RuntimeException('The generated types integrity manifest exceeds its configured size limit.');
        }

        try {
            $decoded = json_decode(
                $this->files->sharedGet($path),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('The generated types integrity manifest is invalid JSON.', 0, $exception);
        }

        $this->assertManifestShape($decoded);

        /** @var TypesManifest $decoded */
        return $decoded;
    }

    /**
     * Verify that a decoded value has the complete persisted manifest contract.
     */
    private function assertManifestShape(mixed $manifest): void
    {
        if (
            ! is_array($manifest)
            || ($manifest['schemaVersion'] ?? null) !== self::MANIFEST_SCHEMA_VERSION
            || ! $this->isHash($manifest['hash'] ?? null)
            || ! $this->isHash($manifest['revision'] ?? null)
            || ! $this->isHash($manifest['transformerHash'] ?? null)
            || ! is_string($manifest['generatedAt'] ?? null)
            || ! is_array($manifest['packages'] ?? null)
            || ! is_array($manifest['transformers'] ?? null)
            || ! is_array($manifest['sources'] ?? null)
            || ! is_array($manifest['symbols'] ?? null)
            || ! is_array($manifest['entrypoint'] ?? null)
            || ! is_array($manifest['files'] ?? null)
            || ! is_array($manifest['archive'] ?? null)
        ) {
            throw new RuntimeException('The generated types integrity manifest has an invalid shape.');
        }

        $this->assertArtifactRecord($manifest['entrypoint']);
        $seenScopes = [];

        foreach ($manifest['files'] as $record) {
            if (! is_array($record) || ! is_string($record['scope'] ?? null)) {
                throw new RuntimeException('The generated types integrity manifest contains an invalid scope record.');
            }

            $scope = $record['scope'];

            if (preg_match('/^[A-Za-z0-9_-]+$/', $scope) !== 1 || isset($seenScopes[$scope])) {
                throw new RuntimeException('The generated types integrity manifest contains an invalid or duplicate scope.');
            }

            $seenScopes[$scope] = true;
            $this->assertArtifactRecord($record);
        }

        $entrypointPath = $manifest['entrypoint']['path'] ?? null;
        $entrypointHash = $manifest['entrypoint']['hash'] ?? null;

        if (! is_string($entrypointPath) || ! is_string($entrypointHash)) {
            throw new RuntimeException('The generated types integrity manifest has an invalid entrypoint hash.');
        }

        $hashParts = [$entrypointPath.':'.$entrypointHash];

        foreach ($manifest['files'] as $record) {
            $recordPath = $record['path'] ?? null;
            $recordHash = $record['hash'] ?? null;

            if (! is_string($recordPath) || ! is_string($recordHash)) {
                throw new RuntimeException('The generated types integrity manifest has an invalid scope hash.');
            }

            $hashParts[] = $recordPath.':'.$recordHash;
        }

        $manifestHash = $manifest['hash'] ?? null;

        if (
            ! is_string($manifestHash)
            || ! hash_equals($manifestHash, hash('sha256', implode('|', $hashParts)))
        ) {
            throw new RuntimeException('The generated types integrity manifest has an invalid combined hash.');
        }

        foreach ([$manifest['packages'], $manifest['transformers']] as $versions) {
            foreach ($versions as $name => $version) {
                if (! is_string($name) || ! is_string($version)) {
                    throw new RuntimeException('The generated types integrity manifest contains invalid version metadata.');
                }
            }
        }

        foreach ($manifest['sources'] as $source) {
            if (
                ! is_array($source)
                || ! (is_string($source['package'] ?? null) || ($source['package'] ?? null) === null)
                || ! is_int($source['priority'] ?? null)
            ) {
                throw new RuntimeException('The generated types integrity manifest contains invalid source metadata.');
            }
        }

        foreach ($manifest['symbols'] as $symbol) {
            if (
                ! is_array($symbol)
                || ! is_string($symbol['phpType'] ?? null)
                || ! is_string($symbol['typescriptType'] ?? null)
                || ! (is_string($symbol['package'] ?? null) || ($symbol['package'] ?? null) === null)
                || ! is_string($symbol['source'] ?? null)
            ) {
                throw new RuntimeException('The generated types integrity manifest contains invalid symbol metadata.');
            }
        }

        $archiveFilename = $manifest['archive']['filename'] ?? null;

        if (
            ! is_bool($manifest['archive']['enabled'] ?? null)
            || ! is_string($archiveFilename)
            || preg_match('/^[A-Za-z0-9_-]+-[a-f0-9]+\\.zip$/', $archiveFilename) !== 1
            || $archiveFilename !== $this->archiveFilename($manifestHash)
        ) {
            throw new RuntimeException('The generated types integrity manifest contains invalid archive metadata.');
        }

        $revision = $manifest['revision'] ?? null;

        if (! is_string($revision) || ! hash_equals($revision, $this->manifestRevision($manifest))) {
            throw new RuntimeException('The generated types integrity manifest has an invalid revision.');
        }
    }

    /**
     * Hash every manifest field using a key-order-independent canonical encoding.
     *
     * @param  array<array-key, mixed>  $manifest
     */
    private function manifestRevision(array $manifest): string
    {
        unset($manifest['revision']);

        return hash('sha256', json_encode(
            $this->canonicalValue($manifest),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Canonicalize nested manifest values while preserving list order.
     */
    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalValue($item),
                $value,
            );
        }

        ksort($value);

        return array_map(
            fn (mixed $item): mixed => $this->canonicalValue($item),
            $value,
        );
    }

    /**
     * Verify one manifest artifact record.
     *
     * @param  array<array-key, mixed>  $record
     */
    private function assertArtifactRecord(array $record): void
    {
        if (
            ! is_string($record['path'] ?? null)
            || ! is_string($record['filename'] ?? null)
            || ! is_int($record['bytes'] ?? null)
            || $record['bytes'] < 0
            || ! $this->isHash($record['hash'] ?? null)
            || ! is_string($record['lastModified'] ?? null)
        ) {
            throw new RuntimeException('The generated types integrity manifest contains an invalid artifact record.');
        }

        $path = $this->artifacts->normalizeDeclarationPath($record['path']);

        if ($record['filename'] !== basename($path)) {
            throw new RuntimeException('The generated types integrity manifest contains inconsistent artifact metadata.');
        }
    }

    /**
     * Verify that persisted records exactly match Spatie's current artifact manifest.
     *
     * @param  TypesManifest  $manifest
     */
    private function assertPublishedArtifactsMatch(array $manifest): void
    {
        $publishedPaths = [
            $manifest['entrypoint']['path'],
            ...array_column($manifest['files'], 'path'),
        ];
        sort($publishedPaths);

        if ($publishedPaths !== $this->artifacts->paths($this->outputDirectory())) {
            throw new RuntimeException('Published declarations do not match the generated types integrity manifest.');
        }

        $transformerHash = hash(
            'sha256',
            $this->artifacts->transformerManifest($this->outputDirectory()),
        );

        if (! hash_equals($manifest['transformerHash'], $transformerHash)) {
            throw new RuntimeException('The transformer and integrity manifests do not describe the same publication.');
        }
    }

    /**
     * Read one artifact and verify its length and SHA-256 checksum.
     *
     * @param  ArtifactRecord|ScopeRecord  $record
     */
    private function verifiedContents(array $record): string
    {
        $contents = $this->files->sharedGet(
            $this->absolutePath($record['path'], $this->outputDirectory()),
        );

        if (
            strlen($contents) !== $record['bytes']
            || ! hash_equals($record['hash'], hash('sha256', $contents))
        ) {
            throw new RuntimeException("Generated declaration [{$record['path']}] failed its integrity check.");
        }

        return $contents;
    }

    /**
     * Assert configured archive count and uncompressed-size bounds.
     *
     * @param  list<ArtifactRecord|ScopeRecord>  $records
     */
    private function assertArchiveBounds(array $records): void
    {
        $maximumFiles = $this->positiveInteger(
            'nvl-data.typescript.routes.archive_max_files',
            1_000,
        );
        $maximumBytes = $this->positiveInteger(
            'nvl-data.typescript.routes.archive_max_bytes',
            25 * 1024 * 1024,
        );

        if (count($records) > $maximumFiles) {
            throw new RuntimeException('Generated type declarations exceed the configured archive file-count limit.');
        }

        if (array_sum(array_column($records, 'bytes')) > $maximumBytes) {
            throw new RuntimeException('Generated type declarations exceed the configured archive size limit.');
        }
    }

    /**
     * Resolve a safe public scope from a generated declaration filename.
     */
    private function scopeFor(string $relativePath): string
    {
        $filename = basename($relativePath);
        $scope = substr($filename, 0, -5);

        if ($scope === '' || preg_match('/^[A-Za-z0-9_-]+$/', $scope) !== 1) {
            throw new RuntimeException("Generated declaration [{$relativePath}] cannot form a public scope.");
        }

        return $scope;
    }

    /**
     * Return the configured entrypoint path relative to the output directory.
     */
    private function entrypointPath(): string
    {
        $path = $this->config->get('nvl-data.typescript.output_file', 'generated.d.ts');

        if (! is_string($path)) {
            throw new RuntimeException('The configured generated type entrypoint is invalid.');
        }

        return $this->artifacts->normalizeDeclarationPath($path);
    }

    /**
     * Return the safe manifest filename relative to the output directory.
     */
    private function manifestFilename(): string
    {
        $filename = $this->config->get(
            'nvl-data.typescript.manifest_file',
            'generated.manifest.json',
        );
        $normalized = is_string($filename)
            ? str_replace('\\', '/', $filename)
            : null;
        $segments = is_string($normalized) ? explode('/', $normalized) : [];

        if (
            ! is_string($normalized)
            || $normalized === ''
            || $normalized !== trim($normalized)
            || preg_match('/^[A-Za-z0-9._\\/-]+\\.json$/', $normalized) !== 1
            || str_starts_with($normalized, '/')
            || array_filter(
                $segments,
                static fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..',
            ) !== []
        ) {
            throw new RuntimeException('nvl-data.typescript.manifest_file must be a safe relative JSON path.');
        }

        if ($normalized === $this->artifacts->transformerManifestFilename()) {
            throw new RuntimeException(
                'nvl-data.typescript.manifest_file cannot overwrite the TypeScript transformer manifest.',
            );
        }

        return $normalized;
    }

    /**
     * Return the configured canonical declaration output directory.
     */
    private function outputDirectory(): string
    {
        $path = $this->config->get('nvl-data.typescript.output_directory');

        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException('The configured generated type output directory is invalid.');
        }

        return $this->pathGuard->outputDirectory($path);
    }

    /**
     * Resolve one safe existing declaration path under an output directory.
     */
    private function absolutePath(string $relativePath, string $directory): string
    {
        $relativePath = $this->artifacts->normalizeDeclarationPath($relativePath);
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $path = $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $resolvedPath = realpath($path);

        if (
            $resolvedPath === false
            || ! $this->files->isFile($resolvedPath)
            || ! str_starts_with($resolvedPath, $directory.DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException("Generated declaration [{$relativePath}] is unavailable.");
        }

        return $resolvedPath;
    }

    /**
     * Create an empty temporary archive path.
     */
    private function temporaryArchivePath(): string
    {
        $directory = storage_path('app/nvl-data/archives');
        $this->files->ensureDirectoryExists($directory);
        $path = tempnam($directory, 'types-');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary generated types archive.');
        }

        return $path;
    }

    /**
     * Return installed versions for every registered package source.
     *
     * @return array<string, string>
     */
    private function packageVersions(): array
    {
        $packages = ['nvl/core' => true];

        foreach ($this->sources->descriptors() as $source) {
            if ($source['package'] !== null) {
                $packages[$source['package']] = true;
            }
        }

        $versions = [];

        foreach (array_keys($packages) as $package) {
            $versions[$package] = InstalledVersions::isInstalled($package)
                ? (InstalledVersions::getPrettyVersion($package) ?? 'unknown')
                : 'source';
        }

        ksort($versions);

        return $versions;
    }

    /**
     * Return transformer versions that produced the declaration artifacts.
     *
     * @return array<string, string>
     */
    private function transformerVersions(): array
    {
        $packages = [
            'spatie/laravel-data',
            'spatie/laravel-typescript-transformer',
            'spatie/typescript-transformer',
        ];
        $versions = [];

        foreach ($packages as $package) {
            $versions[$package] = InstalledVersions::isInstalled($package)
                ? (InstalledVersions::getPrettyVersion($package) ?? 'unknown')
                : 'not-installed';
        }

        return $versions;
    }

    /**
     * Determine whether a value is a lowercase SHA-256 hash.
     */
    private function isHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
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
