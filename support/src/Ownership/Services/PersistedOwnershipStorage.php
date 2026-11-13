<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use stdClass;

/**
 * Reads fixed protocol identities without requiring the optional runtime.
 *
 * @internal
 */
final readonly class PersistedOwnershipStorage
{
    /**
     * Reject prepared and active storage on the supplied actual connection.
     *
     * This runs before every unowned model query and write, so the catalog is read once per
     * connection object within one request or job; queue admission of resources always reads it fresh.
     */
    public function assertUnadopted(Connection $connection, ?string $resource = null, ?string $physicalTable = null): void
    {
        $this->assertMarkers($this->rememberedProbe($connection)['markers'], $resource, $physicalTable);
        if ($physicalTable !== null) {
            $this->assertUnpartitioned($connection, [$physicalTable]);
        }
    }

    /** Inspect each actual source once for this admission, preserving every resource/table selector.
     *
     * @param  iterable<OwnershipResourceDefinition>  $resources
     */
    public function assertUnadoptedResources(iterable $resources): void
    {
        $sources = [];
        foreach ($resources as $resource) {
            $model = new $resource->model;
            $connection = $model->getConnection();
            $id = spl_object_id($connection);
            $sources[$id] ??= ['connection' => $connection, 'resources' => [], 'tables' => []];
            $sources[$id]['resources'][$resource->key] = true;
            $table = $model->getTable();
            $sources[$id]['tables'][$table] = $table;
        }
        foreach ($sources as $source) {
            $connection = $source['connection'];
            $probe = $this->probe($connection);
            $this->assertMarkerSelectors($probe['markers'], $source['resources'], $source['tables']);
            if ($probe['protocol']) {
                foreach ($source['tables'] as $table) {
                    $this->assertNoPartition($connection, $table);
                }
            }
        }
    }

    /** Inspect the tables of one read, sharing the request's catalog probe with single-model checks.
     *
     * @param  list<string>  $tables
     */
    public function assertUnadoptedTables(Connection $connection, array $tables): void
    {
        if ($tables === []) {
            return;
        }
        $selected = [];
        foreach ($tables as $table) {
            $selected[$table] = $table;
        }
        $this->assertMarkerSelectors($this->rememberedProbe($connection)['markers'], [], $selected);
        $this->assertUnpartitioned($connection, array_values($selected));
    }

    /**
     * Read the catalog once per connection object within one request or job.
     *
     * @return array{protocol: bool, markers: list<stdClass>}
     */
    private function rememberedProbe(Connection $connection): array
    {
        $probes = Container::getInstance()->make(OwnershipStorageProbes::class);

        return $probes->probe($connection) ?? $probes->rememberProbe($connection, $this->probe($connection));
    }

    /** @param  list<string>  $tables */
    private function assertUnpartitioned(Connection $connection, array $tables): void
    {
        if (! $this->rememberedProbe($connection)['protocol']) {
            return;
        }
        $probes = Container::getInstance()->make(OwnershipStorageProbes::class);
        foreach ($tables as $table) {
            if (! $probes->unpartitioned($connection, $table)) {
                $this->assertNoPartition($connection, $table);
                $probes->rememberUnpartitioned($connection, $table);
            }
        }
    }

    /**
     * Inspect the marker table and any prefixed protocol catalog in one fresh native schema query.
     *
     * @return array{protocol: bool, markers: list<stdClass>}
     */
    public function probe(Connection $connection): array
    {
        [$namespace, $name] = $connection->getSchemaBuilder()->parseSchemaAndTable(OwnershipStorageTables::Markers, true);
        $name = $connection->getTablePrefix().$name;
        $suffix = OwnershipStorageTables::Markers;
        if ($connection->getDriverName() === 'sqlite') {
            $catalog = $connection->getQueryGrammar()->wrap($namespace ?? 'main').'.sqlite_master';
            $row = $connection->selectOne("select exists(select 1 from {$catalog} where type = 'table' and name = ?) as owned, exists(select 1 from {$catalog} where type = 'table' and substr(name, -?) = ?) as protocol", [$name, strlen($suffix), $suffix], false);
        } elseif ($connection->getDriverName() === 'pgsql') {
            // Resolve through the server's effective search_path, exactly as the marker query below does; its first listed schema may not exist.
            $row = $connection->selectOne('select to_regclass(?) is not null as owned, exists(select 1 from information_schema.tables where right(table_name, ?) = ?) as protocol', [$connection->getQueryGrammar()->wrapTable($suffix), strlen($suffix), $suffix], false);
        } else {
            throw new OwnershipSchemaNotReady('The selected driver has no supported native ownership catalog.');
        }
        if (! $row instanceof stdClass) {
            throw new OwnershipSchemaNotReady('The native protocol catalog could not be inspected.');
        }
        $markers = in_array($row->owned, [true, 1, '1'], true)
            ? array_values($connection->table(OwnershipStorageTables::Markers)->useWritePdo()->get()->all()) : [];

        return ['protocol' => in_array($row->protocol, [true, 1, '1'], true), 'markers' => $markers];
    }

    /** A native technical partition survives protocol prefix and schema-search changes. */
    public function assertNoPartition(Connection $connection, string $table): void
    {
        $present = match ($connection->getDriverName()) {
            'pgsql' => $connection->selectOne("SELECT attname FROM pg_attribute WHERE attrelid = to_regclass(?) AND attname = 'owner_scope_key' AND NOT attisdropped", [$connection->getQueryGrammar()->wrapTable($table)], false) !== null,
            'sqlite' => $connection->getSchemaBuilder()->hasColumn($table, 'owner_scope_key'),
            default => throw new OwnershipSchemaNotReady('The selected driver has no supported native ownership catalog.'),
        };
        if ($present) {
            throw new OwnershipSchemaNotReady('Physical ownership partitioning requires its enforcing runtime.');
        }
    }

    /** @param list<stdClass> $markers */
    public function assertMarkers(array $markers, ?string $resource = null, ?string $physicalTable = null): void
    {
        $this->assertMarkerSelectors($markers, $resource === null ? [] : [$resource => true], $physicalTable === null ? [] : [$physicalTable => $physicalTable]);
    }

    /**
     * Match the complete source inventory once against this boundary's exact selectors.
     *
     * @param  list<stdClass>  $markers
     * @param  array<array-key, true>  $resources
     * @param  array<array-key, string>  $tables
     */
    private function assertMarkerSelectors(array $markers, array $resources, array $tables): void
    {
        foreach ($markers as $marker) {
            $attributes = get_object_vars($marker);
            $resource = $attributes['resource'] ?? null;
            $table = $attributes['physical_table'] ?? null;
            if (! is_string($resource) || ! is_string($table)
                || ($resources === [] && $tables === []) || isset($resources[$resource]) || isset($tables[$table])) {
                throw new OwnershipSchemaNotReady('Persisted ownership requires its enforcing runtime and active generation.');
            }
        }
    }
}
