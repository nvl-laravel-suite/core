<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Database\Connection;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use stdClass;

/** Reads fixed protocol identities without requiring the optional runtime. @internal */
final readonly class PersistedOwnershipStorage
{
    /** Reject prepared and active storage on the supplied actual connection. */
    public function assertUnadopted(Connection $connection, ?string $resource = null, ?string $physicalTable = null): void
    {
        $probe = $this->probe($connection);
        $this->assertMarkers($probe['markers'], $resource, $physicalTable);
        if ($probe['protocol'] && $physicalTable !== null) {
            $this->assertNoPartition($connection, $physicalTable);
        }
    }

    /**
     * Inspect both protocol catalogs in one native schema query per connection generation.
     *
     * @return array{legacy: bool, protocol: bool, markers: list<stdClass>}
     */
    public function probe(Connection $connection, ?string $legacyTable = null): array
    {
        $schema = $connection->getSchemaBuilder();
        $selections = [];
        $bindings = [];
        foreach (['owned' => OwnershipStorageTables::Markers, 'legacy' => $legacyTable] as $alias => $table) {
            if ($table === null) {
                $selections[] = '0 as '.$alias;

                continue;
            }
            [$namespace, $name] = $schema->parseSchemaAndTable($table, true);
            $name = $connection->getTablePrefix().$name;
            if ($connection->getDriverName() === 'sqlite') {
                $catalog = $connection->getQueryGrammar()->wrap($namespace ?? 'main').'.sqlite_master';
                $selections[] = "exists(select 1 from {$catalog} where type = 'table' and name = ?) as {$alias}";
                $bindings[] = $name;
            } else {
                $namespace ??= $connection->getDatabaseName();
                $selections[] = "exists(select 1 from information_schema.tables where table_schema = ? and table_name = ?) as {$alias}";
                array_push($bindings, $namespace, $name);
            }
        }
        $suffix = OwnershipStorageTables::Markers;
        if ($connection->getDriverName() === 'sqlite') {
            $selections[] = "exists(select 1 from main.sqlite_master where type = 'table' and substr(name, -?) = ?) as protocol";
            array_push($bindings, strlen($suffix), $suffix);
        } else {
            $selections[] = 'exists(select 1 from information_schema.tables where right(table_name, ?) = ?'.($connection->getDriverName() === 'pgsql' ? '' : ' and table_schema = DATABASE()').') as protocol';
            array_push($bindings, strlen($suffix), $suffix);
        }
        $row = $connection->selectOne('select '.implode(', ', $selections), $bindings, false);
        if (! $row instanceof stdClass) {
            throw new OwnershipSchemaNotReady('The native protocol catalog could not be inspected.');
        }
        $markers = in_array($row->owned, [true, 1, '1'], true)
            ? array_values($connection->table(OwnershipStorageTables::Markers)->useWritePdo()->get()->all()) : [];

        return ['legacy' => in_array($row->legacy, [true, 1, '1'], true), 'protocol' => in_array($row->protocol, [true, 1, '1'], true), 'markers' => $markers];
    }

    /** A native technical partition survives protocol prefix and schema-search changes. */
    public function assertNoPartition(Connection $connection, string $table): void
    {
        $present = $connection->getDriverName() === 'pgsql'
            ? $connection->selectOne("SELECT attname FROM pg_attribute WHERE attrelid = to_regclass(?) AND attname = 'owner_scope_key' AND NOT attisdropped", [$connection->getQueryGrammar()->wrapTable($table)], false) !== null
            : $connection->getSchemaBuilder()->hasColumn($table, 'owner_scope_key');
        if ($present) {
            throw new OwnershipSchemaNotReady('Physical ownership partitioning requires its enforcing runtime.');
        }
    }

    /** @param list<stdClass> $markers */
    public function assertMarkers(array $markers, ?string $resource = null, ?string $physicalTable = null): void
    {
        foreach ($markers as $marker) {
            if (! is_string($marker->resource) || ! is_string($marker->physical_table)
                || ($resource === null && $physicalTable === null) || $marker->resource === $resource || $marker->physical_table === $physicalTable) {
                throw new OwnershipSchemaNotReady('Persisted ownership requires its enforcing runtime and active generation.');
            }
        }
    }
}
