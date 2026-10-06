<?php

declare(strict_types=1);

namespace Nvl\Support\Schema;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Nvl\Support\Config\PackageStorage;

/**
 * Verifies the released column and relational key contracts before claiming existing storage.
 *
 * @phpstan-import-type ForeignIdentity from SchemaIdentities
 */
final class SchemaShape
{
    /**
     * Require each released column and a compatible portable type family.
     *
     * @param  array<string, string>  $expected
     * @param  list<array{type: string, columns: list<string>}>  $keys
     * @param  list<ForeignIdentity>  $foreignKeys
     */
    public static function assertCompatible(Builder $schema, string $table, array $expected, array $keys = [], array $foreignKeys = []): void
    {
        $actual = [];
        foreach ($schema->getColumns($table) as $column) {
            $actual[$column['name']] = preg_match('/^tinyint\(1\)(?: unsigned)?$/i', $column['type']) === 1
                ? 'boolean'
                : self::family($column['type_name']);
        }
        foreach ($expected as $name => $type) {
            $family = self::family($type);
            $actualFamily = $actual[$name] ?? null;
            $driver = $schema->getConnection()->getDriverName();
            if ($actualFamily === $family || ($family === 'ip' && $driver !== 'pgsql' && $actualFamily === 'string') || ($driver === 'sqlite'
                && (($family === 'json' && $actualFamily === 'text') || ($family === 'boolean' && $actualFamily === 'integer')))) {
                continue;
            }
            throw new LogicException("Table [{$table}] does not match the released NVL schema at column [{$name}]. Run nvl:doctor --strict; no ownership can be inferred from its name.");
        }
        $indexes = $schema->getIndexes($table);
        foreach ($keys as $key) {
            $valid = array_any($indexes, static function (array $index) use ($key): bool {
                $columns = $index['columns'];
                $expectedColumns = $key['columns'];
                if ($key['type'] === 'index') {
                    return array_diff($expectedColumns, $columns) === [];
                }
                $sameColumns = $columns === $expectedColumns || $columns === ['tenant_id', ...$expectedColumns] || $columns === ['ownership_key', ...$expectedColumns];
                $adapted = $columns === ['tenant_id', ...$expectedColumns] || $columns === ['ownership_key', ...$expectedColumns];

                return $sameColumns && ($key['type'] === 'primary' ? $index['primary'] || ($adapted && $index['unique']) : $index['unique']);
            });
            if (! $valid) {
                $columns = implode(', ', $key['columns']);
                throw new LogicException("Table [{$table}] is missing its released {$key['type']} key on [{$columns}]. No ownership can be inferred from column names alone.");
            }
        }
        $actualForeignKeys = $schema->getForeignKeys($table);
        foreach ($foreignKeys as $expectedForeign) {
            $candidates = array_values(array_filter($actualForeignKeys, static fn (array $foreign): bool => self::partition($foreign['columns'], $expectedForeign['columns']) !== null));
            if ($candidates === [] && ! $expectedForeign['required']) {
                continue;
            }
            $targets = self::parentTargets($schema, $expectedForeign);
            if (! array_any($candidates, static function (array $foreign) use ($expectedForeign, $targets): bool {
                $partition = self::partition($foreign['columns'], $expectedForeign['columns']);
                if ($partition === null) {
                    return false;
                }
                $references = [...$partition, ...$expectedForeign['references']];

                return $foreign['foreign_columns'] === $references
                    && array_any($targets, static fn (array $target): bool => $foreign['foreign_table'] === $target['table'] && $foreign['foreign_schema'] === $target['schema']);
            })) {
                throw new LogicException("Table [{$table}] is missing its released foreign key on [".implode(', ', $expectedForeign['columns'])."] to [{$expectedForeign['package']}.{$expectedForeign['table']}].");
            }
        }
    }

    /**
     * Resolve only the historical or effective parent identity on this database connection.
     *
     * @param  ForeignIdentity  $foreign
     * @return list<array{schema: string|null, table: string}>
     */
    private static function parentTargets(Builder $schema, array $foreign): array
    {
        $definition = SchemaIdentities::package($foreign['package'])['tables'][$foreign['table']] ?? null;
        if ($definition === null) {
            throw new LogicException('The released foreign key parent identity is unknown.');
        }
        $parentConnection = DB::connection(PackageStorage::tableConnection($foreign['package'], $foreign['table']));
        if ($parentConnection->getName() !== $schema->getConnection()->getName()) {
            return [];
        }
        $targets = [];
        foreach (array_unique([$definition['legacy'], PackageStorage::table($foreign['package'], $foreign['table'], $definition['default'])]) as $parent) {
            [$namespace, $name] = $schema->parseSchemaAndTable($parent);
            foreach ($namespace === null ? ($schema->getCurrentSchemaListing() ?? [null]) : [$namespace] as $candidate) {
                if ($schema->hasTable(($candidate === null ? '' : $candidate.'.').$name)) {
                    $targets[] = ['schema' => $candidate, 'table' => $schema->getConnection()->getTablePrefix().$name];
                    break;
                }
            }
        }

        return $targets;
    }

    /**
     * Permit a matching tenant partition only when both sides carry the same prefix.
     *
     * @param  list<string>  $actual
     * @param  list<string>  $expected
     * @return list<string>|null
     */
    private static function partition(array $actual, array $expected): ?array
    {
        foreach ([[], ['tenant_id'], ['ownership_key']] as $prefix) {
            if ($actual === [...$prefix, ...$expected]) {
                return $prefix;
            }
        }

        return null;
    }

    /** Normalize supported database types into portable storage families. */
    private static function family(string $type): string
    {
        $type = strtolower($type);

        return match (true) {
            in_array($type, ['uuid', 'bpchar', 'varchar', 'character varying', 'character', 'char', 'string', 'enum'], true) => 'string',
            str_contains($type, 'int'), in_array($type, ['serial', 'bigserial'], true) => 'integer',
            str_contains($type, 'text') => 'text',
            str_contains($type, 'timestamp'), str_contains($type, 'datetime') => 'datetime',
            in_array($type, ['bool', 'boolean'], true) => 'boolean',
            in_array($type, ['json', 'jsonb'], true) => 'json',
            in_array($type, ['ipaddress', 'inet'], true) => 'ip',
            in_array($type, ['float', 'double', 'double precision', 'real', 'float4', 'float8'], true) => 'float',
            in_array($type, ['binary', 'varbinary', 'blob', 'bytea'], true) => 'binary',
            str_contains($type, 'decimal'), str_contains($type, 'numeric') => 'decimal',
            default => $type,
        };
    }

    private function __construct() {}
}
