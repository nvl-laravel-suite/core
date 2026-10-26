<?php

declare(strict_types=1);

namespace Nvl\Data\Console\Commands;

use Illuminate\Console\Command;
use JsonException;
use Nvl\Data\Services\GeneratedTypeFileCatalog;
use Nvl\Data\Services\GeneratedTypesLock;
use Nvl\Data\Services\GeneratedTypesManifestWriter;

/**
 * Displays or persists the current generated-types integrity manifest.
 */
final class ShowTypesManifestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nvl:data:types:manifest {--write : Persist the manifest beside declarations}';

    /**
     * @var string
     */
    protected $description = 'Display the deterministic NVL generated-types manifest';

    /**
     * Display or write the generated-types manifest.
     *
     * @throws JsonException
     */
    public function handle(
        GeneratedTypeFileCatalog $catalog,
        GeneratedTypesManifestWriter $manifestWriter,
        GeneratedTypesLock $lock,
    ): int {
        if ((bool) $this->option('write')) {
            $this->line($lock->publish(
                static fn (): string => $manifestWriter->write(),
            ));

            return self::SUCCESS;
        }

        $this->line(json_encode(
            $catalog->manifest(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
