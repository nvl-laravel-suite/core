<?php

declare(strict_types=1);

namespace Nvl\Data\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/**
 * Publishes a staged generated declaration set with rollback and atomic file replacement.
 */
final readonly class GeneratedTypesPublisher
{
    /**
     * Create a generated declaration publisher.
     */
    public function __construct(
        private Repository $config,
        private Filesystem $files,
        private TypeScriptPathGuard $pathGuard,
        private GeneratedArtifactSet $artifacts,
        private GeneratedTypeFileCatalog $catalog,
        private GeneratedTypesManifestWriter $manifestWriter,
        private GeneratedTypesLock $lock,
    ) {}

    /**
     * Publish a completed staged transform and return the integrity-manifest path.
     */
    public function publish(string $stagingDirectory): string
    {
        $stagingDirectory = $this->pathGuard->outputDirectory($stagingDirectory);
        $newPaths = $this->artifacts->paths($stagingDirectory);
        $newManifest = $this->catalog->freshManifest($stagingDirectory);
        $this->manifestWriter->assertWithinLimits($newManifest);
        $transformerManifest = $this->artifacts->transformerManifest($stagingDirectory);

        return $this->lock->publish(function () use (
            $stagingDirectory,
            $newPaths,
            $newManifest,
            $transformerManifest,
        ): string {
            $targetDirectory = $this->outputDirectory();
            $this->files->ensureDirectoryExists($targetDirectory);

            foreach ([
                ...$newPaths,
                $this->artifacts->transformerManifestFilename(),
                $this->relativeManifestPath($targetDirectory),
            ] as $path) {
                $this->absolutePath($targetDirectory, $path);
            }

            $oldPaths = $this->publishedPaths($targetDirectory);

            foreach ($oldPaths as $path) {
                $this->absolutePath($targetDirectory, $path);
            }

            $backupDirectory = $stagingDirectory.DIRECTORY_SEPARATOR.'.publication-backup';
            $backup = $this->backupPublishedFiles($targetDirectory, $oldPaths, $backupDirectory);

            try {
                foreach ($newPaths as $path) {
                    $this->replaceFromDirectory($stagingDirectory, $targetDirectory, $path);
                }

                foreach (array_diff($oldPaths, $newPaths) as $stalePath) {
                    $this->files->delete($this->absolutePath($targetDirectory, $stalePath));
                }

                $this->files->replace(
                    $this->absolutePath($targetDirectory, $this->artifacts->transformerManifestFilename()),
                    $transformerManifest,
                );

                return $this->manifestWriter->writeManifest($newManifest);
            } catch (Throwable $exception) {
                $this->restorePublishedFiles(
                    targetDirectory: $targetDirectory,
                    oldPaths: $oldPaths,
                    newPaths: $newPaths,
                    backupDirectory: $backupDirectory,
                    backup: $backup,
                );

                throw $exception;
            }
        });
    }

    /**
     * Return generated paths from an existing publication or an empty first-run set.
     *
     * @return list<string>
     */
    private function publishedPaths(string $targetDirectory): array
    {
        $manifestPath = $targetDirectory
            .DIRECTORY_SEPARATOR
            .$this->artifacts->transformerManifestFilename();

        if (! $this->files->isFile($manifestPath)) {
            return [];
        }

        return $this->artifacts->paths($targetDirectory);
    }

    /**
     * Back up every currently published declaration and manifest.
     *
     * @param  list<string>  $oldPaths
     * @return array{transformerManifest: bool, integrityManifest: bool}
     */
    private function backupPublishedFiles(
        string $targetDirectory,
        array $oldPaths,
        string $backupDirectory,
    ): array {
        $this->files->ensureDirectoryExists($backupDirectory);

        foreach ($oldPaths as $path) {
            $this->copyToBackup($targetDirectory, $backupDirectory, $path);
        }

        $transformerManifest = $this->artifacts->transformerManifestFilename();
        $integrityManifest = $this->relativeManifestPath($targetDirectory);
        $hasTransformerManifest = $this->files->isFile(
            $this->absolutePath($targetDirectory, $transformerManifest),
        );
        $hasIntegrityManifest = $this->files->isFile(
            $this->absolutePath($targetDirectory, $integrityManifest),
        );

        if ($hasTransformerManifest) {
            $this->copyToBackup($targetDirectory, $backupDirectory, $transformerManifest);
        }

        if ($hasIntegrityManifest) {
            $this->copyToBackup($targetDirectory, $backupDirectory, $integrityManifest);
        }

        return [
            'transformerManifest' => $hasTransformerManifest,
            'integrityManifest' => $hasIntegrityManifest,
        ];
    }

    /**
     * Restore the prior publication after a failed replacement.
     *
     * @param  list<string>  $oldPaths
     * @param  list<string>  $newPaths
     * @param  array{transformerManifest: bool, integrityManifest: bool}  $backup
     */
    private function restorePublishedFiles(
        string $targetDirectory,
        array $oldPaths,
        array $newPaths,
        string $backupDirectory,
        array $backup,
    ): void {
        foreach ($oldPaths as $path) {
            $this->replaceFromDirectory($backupDirectory, $targetDirectory, $path);
        }

        foreach (array_diff($newPaths, $oldPaths) as $newPath) {
            $this->files->delete($this->absolutePath($targetDirectory, $newPath));
        }

        $transformerManifest = $this->artifacts->transformerManifestFilename();
        $integrityManifest = $this->relativeManifestPath($targetDirectory);

        $this->restoreManifest(
            $targetDirectory,
            $backupDirectory,
            $transformerManifest,
            $backup['transformerManifest'],
        );
        $this->restoreManifest(
            $targetDirectory,
            $backupDirectory,
            $integrityManifest,
            $backup['integrityManifest'],
        );
    }

    /**
     * Restore or remove one publication manifest according to its prior state.
     */
    private function restoreManifest(
        string $targetDirectory,
        string $backupDirectory,
        string $path,
        bool $existed,
    ): void {
        if ($existed) {
            $this->replaceFromDirectory($backupDirectory, $targetDirectory, $path);

            return;
        }

        $this->files->delete($this->absolutePath($targetDirectory, $path));
    }

    /**
     * Copy one target file into the publication backup.
     */
    private function copyToBackup(
        string $targetDirectory,
        string $backupDirectory,
        string $path,
    ): void {
        $source = $this->absolutePath($targetDirectory, $path);
        $destination = $this->absolutePath($backupDirectory, $path);
        $this->files->ensureDirectoryExists(dirname($destination));

        if (! $this->files->copy($source, $destination)) {
            throw new RuntimeException("Unable to back up generated artifact [{$path}].");
        }

        if (! touch($destination, $this->files->lastModified($source))) {
            throw new RuntimeException("Unable to preserve the timestamp for generated artifact [{$path}].");
        }
    }

    /**
     * Atomically replace one target file with staged or backup contents.
     */
    private function replaceFromDirectory(
        string $sourceDirectory,
        string $targetDirectory,
        string $path,
    ): void {
        $source = $this->absolutePath($sourceDirectory, $path);
        $target = $this->absolutePath($targetDirectory, $path);
        $lastModified = $this->files->lastModified($source);
        $this->files->ensureDirectoryExists(dirname($target));
        $this->files->replace($target, $this->files->sharedGet($source));

        if (! touch($target, $lastModified)) {
            throw new RuntimeException("Unable to preserve the timestamp for published artifact [{$path}].");
        }
    }

    /**
     * Resolve a path beneath a known publication directory.
     */
    private function absolutePath(string $directory, string $relativePath): string
    {
        return $this->pathGuard->artifactPath($directory, $relativePath);
    }

    /**
     * Return the integrity manifest path relative to the output directory.
     */
    private function relativeManifestPath(string $targetDirectory): string
    {
        $absolutePath = $this->catalog->manifestPath();
        $prefix = rtrim($targetDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($absolutePath, $prefix)) {
            throw new RuntimeException('The integrity manifest must be inside the generated output directory.');
        }

        return str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($absolutePath, strlen($prefix)),
        );
    }

    /**
     * Return the configured canonical declaration output directory.
     */
    private function outputDirectory(): string
    {
        $path = $this->config->get('nvl-data.typescript.output_directory');

        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException('The generated-types output directory is invalid.');
        }

        return $this->pathGuard->outputDirectory($path);
    }
}
