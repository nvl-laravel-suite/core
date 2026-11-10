<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipStorageTables;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use PDO;

/** Owns bounded fixture declarations, real queue storage and permissive marker schemas. */
final class PublicationScenario
{
    public static function install(): void
    {
        PublicationResource::$sources = [];
        PublicationResource::$tables = [];
        PublicationResource::$constructions = 0;
        $schema = DB::getSchemaBuilder();
        $schema->create('publication_records', static fn (Blueprint $table) => $table->id());
        $schema->create('publication_events', static function (Blueprint $table): void {
            $table->string('identity')->primary();
            $table->string('mode');
        });
        $schema->create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        config(['nvl-ownership.enabled' => false, 'queue.connections.database.connection' => DB::getDefaultConnection(), 'queue.connections.database.after_commit' => true]);
    }

    /** Build fixed, bounded model types while allowing aliases over the same physical table. */
    public static function register(int $count, ?Connection $second = null, bool $distinctTables = false): OwnershipResourceRegistry
    {
        if ($count < 1 || $count > 189) {
            throw new LogicException('Publication fixture size must be between one and 189.');
        }
        $resources = new OwnershipResourceRegistry;
        for ($index = 1; $index <= $count; $index++) {
            $model = self::model($index);
            PublicationResource::$sources[$model] = $second !== null && $index % 2 === 0 ? $second : DB::connection();
            if ($distinctTables) {
                $table = 'publication_records_'.$index;
                PublicationResource::$sources[$model]->getSchemaBuilder()->create($table, static fn (Blueprint $schema) => $schema->id());
                PublicationResource::$tables[$model] = $table;
            }
            $resources->register(new OwnershipResourceDefinition('publication.record_'.$index, 'publication', $model, OwnershipDefinition::unscoped()));
        }
        app()->instance(OwnershipResourceRegistry::class, $resources);

        return $resources;
    }

    /** @param list<array{resource?: string|null, physical_table?: string|null, state?: string|null, generation?: string|null}> $markers */
    public static function markers(Connection $source, array $markers = []): void
    {
        $source->getSchemaBuilder()->create(OwnershipStorageTables::Markers, static function (Blueprint $table): void {
            $table->string('resource')->nullable();
            $table->string('physical_table')->nullable();
            $table->string('state')->nullable();
            $table->string('generation')->nullable();
        });
        foreach ($markers as $marker) {
            $source->table(OwnershipStorageTables::Markers)->insert($marker);
        }
    }

    /** Create a distinct real source while deliberately retaining identical connection labels. */
    public static function secondSource(): Connection
    {
        $primary = DB::connection();
        $configuration = $primary->getConfig();
        if (! is_array($configuration)) {
            throw new LogicException('Expected actual source connection configuration.');
        }
        if ($primary->getDriverName() === 'sqlite') {
            $second = new SQLiteConnection(new PDO('sqlite::memory:'), $primary->getDatabaseName(), '', $configuration);
        } else {
            $database = getenv('NVL_PUBLICATION_REMOTE_DATABASE');
            if (! is_string($database) || preg_match('/^nvl_core_test_[a-z0-9_]+_ci$/D', $database) !== 1 || $database === $primary->getDatabaseName()) {
                throw new LogicException('Publication tests require a separate disposable Core source.');
            }
            config(['database.connections.publication_secondary' => [...$configuration, 'database' => $database, 'url' => null]]);
            $remote = DB::connection('publication_secondary');
            $remote->getSchemaBuilder()->dropAllTables();
            $second = new ($primary::class)($remote->getPdo(), $primary->getDatabaseName(), '', $configuration);
        }
        $events = $primary->getEventDispatcher();
        if ($events !== null) {
            $second->setEventDispatcher($events);
        }
        $second->getSchemaBuilder()->create('publication_records', static fn (Blueprint $table) => $table->id());

        return $second;
    }

    /** @return class-string<PublicationResource> */
    private static function model(int $index): string
    {
        $short = 'PublicationRecord'.$index;
        $model = __NAMESPACE__.'\\'.$short;
        if (! class_exists($model, false)) {
            eval('namespace '.__NAMESPACE__.'; class '.$short.' extends PublicationResource {}');
        }
        if (! is_a($model, PublicationResource::class, true)) {
            throw new LogicException('Invalid publication fixture model type.');
        }

        return $model;
    }
}
