<?php

declare(strict_types=1);

namespace Nvl\Support\Schema;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use LogicException;

/** Adds a batch-wide ownership preflight to Laravel's normal migration lifecycle. */
class PackageMigrator extends Migrator
{
    /** Construct Laravel's migrator with its required ownership preflight. */
    public function __construct(MigrationRepositoryInterface $repository, ConnectionResolverInterface $resolver, Filesystem $files, ?Dispatcher $events, private readonly SchemaPreflight $preflight)
    {
        parent::__construct($repository, $resolver, $files, $events);
    }

    /** Preserve Laravel's configured state and require custom migrators to retain the safety boundary. */
    public static function guard(Migrator $migrator, SchemaPreflight $preflight): self
    {
        if ($migrator instanceof self) {
            return $migrator;
        }
        if ($migrator::class !== Migrator::class) {
            throw new LogicException('NVL cannot replace a custom host migrator safely. Make the host migrator extend Nvl\\Support\\Schema\\PackageMigrator and retain its preflight before running migrations.');
        }
        $guarded = new self($migrator->repository, $migrator->resolver, $migrator->files, $migrator->events, $preflight);
        $guarded->connection = $migrator->connection;
        $guarded->paths = $migrator->paths;
        $guarded->output = $migrator->output;

        return $guarded;
    }

    /**
     * Validate the entire pending batch before executing any migration.
     *
     * @param  list<string>  $migrations
     * @param  array<string, mixed>  $options
     */
    public function runPending(array $migrations, array $options = []): void
    {
        $this->preflight->validate($migrations);
        parent::runPending($migrations, $options);
    }

    /**
     * Resolve verified published identities before Laravel filters pending records.
     * Reject duplicate package owners before Laravel's keyed collection hides one.
     *
     * @param  string|list<string>  $paths
     * @return array<string, string>
     */
    public function getMigrationFiles($paths): array
    {
        $files = [];
        $owners = [];
        foreach ((array) $paths as $path) {
            foreach (str_ends_with($path, '.php') ? [$path] : $this->files->glob($path.'/*_*.php') as $file) {
                if (! is_string($file)) {
                    throw new LogicException('Migration files must be paths.');
                }
                $published = SchemaIdentities::publishedMigration($file);
                $name = $published['name'] ?? parent::getMigrationName($file);
                if (isset($owners[$name]) && realpath($owners[$name]) !== realpath($file)) {
                    throw new LogicException("Migration [{$name}] has two owners [{$owners[$name]}] and [{$file}]. Disable vendor loading or remove the verified published copy before migrating.");
                }
                $owners[$name] = $file;
                $files[$name] = $published['path'] ?? $file;
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * Return the canonical repository identity of a verified published copy.
     *
     * @param  string  $path
     */
    public function getMigrationName($path): string
    {
        return SchemaIdentities::publishedMigration($path)['name'] ?? parent::getMigrationName($path);
    }
}
