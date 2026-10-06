<?php

declare(strict_types=1);

namespace Nvl\Support\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Nvl\Support\Schema\SchemaIdentities;
use Nvl\Support\Schema\SchemaMigrationPaths;
use Nvl\Support\Schema\SchemaPreflight;
use Throwable;

/** Provides an explicit deployment gate for the selected native migration file set. */
final class SchemaPreflightCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:schema:preflight {--package=* : Restrict to logical NVL packages} {--path=* : Native migration paths or files} {--realpath : Paths are absolute} {--database= : Migration repository connection} {--format=text : text or json}';

    /** @var string */
    protected $description = 'Validate selected pending NVL migration ownership and storage without running DDL';

    /** Inspect the selected files using the host migrator connection and preserve its state. */
    public function handle(Migrator $migrator, Filesystem $files, SchemaMigrationPaths $inventory, SchemaPreflight $preflight): int
    {
        try {
            $format = $this->option('format');
            if (! in_array($format, ['text', 'json'], true)) {
                throw new InvalidArgumentException('The format must be text or json.');
            }
            $packages = [];
            foreach ($this->option('package') as $package) {
                if (! is_string($package) || SchemaIdentities::package($package) === []) {
                    throw new InvalidArgumentException('Each --package must select a known logical schema package.');
                }
                $packages[] = $package;
            }
            $paths = [];
            foreach ($this->option('path') as $path) {
                if (! is_string($path) || $path === '') {
                    throw new InvalidArgumentException('Each --path must name a migration file or directory.');
                }
                $paths[] = $this->option('realpath') === true ? $path : base_path($path);
            }
            $paths = $paths === []
                ? array_merge($migrator->paths(), [database_path('migrations')])
                : $paths;
            $selected = [];
            foreach ($paths as $path) {
                foreach (str_ends_with($path, '.php') ? [$path] : $files->glob($path.'/*_*.php') as $file) {
                    if (! is_string($file)) {
                        throw new InvalidArgumentException('Selected migration files must be paths.');
                    }
                    $identity = $inventory->identity($file);
                    if ($identity !== null && ($packages === [] || in_array($identity['package'], $packages, true))) {
                        $selected[] = $file;
                    }
                }
            }
            $connection = $this->option('database') ?? config('database.default');
            if (! is_string($connection) || $connection === '') {
                throw new InvalidArgumentException('The migration repository connection must be a configured connection name.');
            }
            $migrator->usingConnection($connection, fn () => $preflight->validate($selected));
            if ($format === 'json') {
                $this->line(json_encode(['schema_version' => 1, 'migrations' => $selected, 'valid' => true], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            } else {
                $this->info('Selected NVL migrations validated; no migrations executed.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('format') === 'json') {
                $this->line(json_encode(['schema_version' => 1, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
