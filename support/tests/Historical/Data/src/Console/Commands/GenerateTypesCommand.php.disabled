<?php

declare(strict_types=1);

namespace Nvl\Data\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\LockTimeoutException;
use Nvl\Data\Services\GeneratedTypesGenerator;
use Spatie\LaravelTypeScriptTransformer\Support\LaravelConsoleLogger;

/**
 * Generates TypeScript declarations and their integrity manifest.
 */
final class GenerateTypesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nvl:data:types:generate
        {--fail-on-warning : Explicitly require warning-free output (available since nvl/laravel-suite 1.0.2)}';

    /**
     * @var string
     */
    protected $description = 'Generate deterministic NVL TypeScript declarations and a manifest';

    /**
     * Generate declarations in isolation and atomically publish their manifest.
     */
    public function handle(GeneratedTypesGenerator $generator): int
    {
        try {
            $result = $generator->generate(new LaravelConsoleLogger($this));
        } catch (LockTimeoutException) {
            $this->components->error('Another generated-types build is already running.');

            return self::FAILURE;
        }

        if ($result === self::SUCCESS) {
            $this->components->info('Generated TypeScript declarations and integrity manifest published.');
        }

        return $result;
    }
}
