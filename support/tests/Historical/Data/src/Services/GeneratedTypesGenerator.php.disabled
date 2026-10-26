<?php

declare(strict_types=1);

namespace Nvl\Data\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Spatie\TypeScriptTransformer\Enums\RunnerMode;
use Spatie\TypeScriptTransformer\Runners\Runner;
use Spatie\TypeScriptTransformer\Support\Loggers\Logger;

/**
 * Generates declarations in isolation before publishing one complete artifact set.
 */
final readonly class GeneratedTypesGenerator
{
    /**
     * Create a staged generated-types runner.
     */
    public function __construct(
        private Filesystem $files,
        private Runner $runner,
        private TypeScriptConfigurator $configurator,
        private GeneratedTypesPublisher $publisher,
        private GeneratedTypesLock $lock,
    ) {}

    /**
     * Generate and publish declarations, returning the transformer exit code.
     */
    public function generate(Logger $logger): int
    {
        return $this->lock->generate(function () use ($logger): int {
            $temporaryDirectory = storage_path('app/nvl-data/generate-'.Str::uuid());
            $this->files->ensureDirectoryExists($temporaryDirectory);
            $diagnostics = new TypeScriptDiagnosticsLogger($logger);

            try {
                $exitCode = $this->runner->run(
                    logger: $diagnostics,
                    config: $this->configurator->isolatedConfiguration($temporaryDirectory),
                    mode: RunnerMode::Direct,
                );

                if ($exitCode !== 0 || $diagnostics->failed()) {
                    if ($exitCode === 0) {
                        $logger->error('TypeScript generation failed because the transformer emitted warnings.');
                    }

                    return $exitCode !== 0 ? $exitCode : 1;
                }

                $this->publisher->publish($temporaryDirectory);

                return 0;
            } finally {
                $this->files->deleteDirectory($temporaryDirectory);
            }
        });
    }
}
