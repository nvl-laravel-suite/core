<?php

declare(strict_types=1);

namespace Nvl\Data\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Nvl\Data\Services\GeneratedArtifactSet;
use Nvl\Data\Services\GeneratedTypeFileCatalog;
use Nvl\Data\Services\TypeScriptConfigurator;
use Nvl\Data\Services\TypeScriptDiagnosticsLogger;
use Nvl\Data\Services\TypeScriptPathGuard;
use RuntimeException;
use Spatie\TypeScriptTransformer\Enums\RunnerMode;
use Spatie\TypeScriptTransformer\Runners\Runner;
use Spatie\TypeScriptTransformer\Support\Loggers\NullLogger;

/**
 * Verifies committed TypeScript declarations against an isolated fresh generation.
 */
final class CheckTypesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nvl:data:types:check
        {--fail-on-warning : Explicitly require warning-free output (available since nvl/laravel-suite 1.0.2)}';

    /**
     * @var string
     */
    protected $description = 'Fail when generated NVL TypeScript declarations are stale';

    /**
     * Compare current declarations with an isolated fresh transformation.
     */
    public function handle(
        Repository $config,
        Filesystem $files,
        Runner $runner,
        TypeScriptConfigurator $configurator,
        TypeScriptPathGuard $pathGuard,
        GeneratedArtifactSet $artifacts,
        GeneratedTypeFileCatalog $catalog,
    ): int {
        $configuredOutput = $config->get('nvl-data.typescript.output_directory');

        if (! is_string($configuredOutput) || trim($configuredOutput) === '') {
            throw new RuntimeException('The generated-types output directory is invalid.');
        }

        $targetDirectory = $pathGuard->outputDirectory($configuredOutput);
        $temporaryDirectory = storage_path('app/nvl-data/check-'.Str::uuid());
        $files->ensureDirectoryExists($temporaryDirectory);

        try {
            $diagnostics = new TypeScriptDiagnosticsLogger(new NullLogger);
            $exitCode = $runner->run(
                logger: $diagnostics,
                config: $configurator->isolatedConfiguration($temporaryDirectory),
                mode: RunnerMode::Direct,
            );

            if ($exitCode !== self::SUCCESS || $diagnostics->failed()) {
                if ($exitCode === self::SUCCESS) {
                    $this->components->error(
                        'Generated TypeScript declarations contain unresolved transformer warnings.',
                    );
                }

                return $exitCode !== self::SUCCESS ? $exitCode : self::FAILURE;
            }

            $expected = $artifacts->hashes($temporaryDirectory);

            try {
                $actual = $artifacts->hashes($targetDirectory);
            } catch (RuntimeException) {
                return $this->stale();
            }

            if ($expected !== $actual) {
                return $this->stale();
            }

            try {
                if (! $this->manifestsMatch($catalog->manifest(), $catalog->freshManifest())) {
                    return $this->stale();
                }
            } catch (RuntimeException) {
                return $this->stale();
            }

            $this->components->info('Generated TypeScript declarations are current.');

            return self::SUCCESS;
        } finally {
            $files->deleteDirectory($temporaryDirectory);
        }
    }

    /**
     * Report stale generated declarations and return the failure exit code.
     */
    private function stale(): int
    {
        $this->components->error(
            'Generated TypeScript declarations or their integrity manifest are stale. '
            .'Run nvl:data:types:generate.',
        );

        return self::FAILURE;
    }

    /**
     * Compare manifest contracts while ignoring checkout-dependent file timestamps.
     *
     * @param  array<string, mixed>  $persisted
     * @param  array<string, mixed>  $fresh
     */
    private function manifestsMatch(array $persisted, array $fresh): bool
    {
        return $this->stableManifest($persisted) === $this->stableManifest($fresh);
    }

    /**
     * Remove publication timestamps and their derived revision from one manifest.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function stableManifest(array $manifest): array
    {
        unset($manifest['generatedAt'], $manifest['revision']);

        $entrypoint = $manifest['entrypoint'] ?? null;

        if (is_array($entrypoint)) {
            unset($entrypoint['lastModified']);
            $manifest['entrypoint'] = $entrypoint;
        }

        $files = $manifest['files'] ?? null;

        if (is_array($files)) {
            foreach ($files as $index => $file) {
                if (is_array($file)) {
                    unset($file['lastModified']);
                    $files[$index] = $file;
                }
            }

            $manifest['files'] = $files;
        }

        return $manifest;
    }
}
