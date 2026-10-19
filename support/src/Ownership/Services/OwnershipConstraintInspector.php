<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Database\Connection;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;

/** Inspects package-owned protocol constraints on their actual physical source without runtime dependency. @internal */
final readonly class OwnershipConstraintInspector
{
    /** Resolve the stable protocol identifier including source schema and prefix. */
    public function name(Connection $connection, string $table, string $event, string $purpose): string
    {
        [$schema, $logicalTable] = $connection->getSchemaBuilder()->parseSchemaAndTable($table);
        $physical = ($schema === null ? '' : $schema.'.').$connection->getTablePrefix().$logicalTable;

        return 'nvl_own_'.substr(hash('sha256', $physical.'|'.$event.'|'.$purpose), 0, 32);
    }

    /** Require the actual live protocol trigger before package effects. */
    public function assertPresent(Connection $connection, string $table, string $event, string $purpose): void
    {
        $name = $this->name($connection, $table, $event, $purpose);
        $present = match ($connection->getDriverName()) {
            'sqlite' => $connection->selectOne("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name], false),
            'pgsql' => $connection->selectOne('SELECT tgname FROM pg_trigger WHERE tgname = ? AND tgrelid = ?::regclass AND NOT tgisinternal', [$name, $connection->getTablePrefix().$table], false),
            'mysql', 'mariadb' => $connection->selectOne('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? AND EVENT_OBJECT_TABLE = ?', [$name, $connection->getTablePrefix().$table], false),
            default => null,
        };
        if ($present === null) {
            throw new OwnershipSchemaNotReady('A required package ownership integrity constraint is missing.');
        }
    }

    /** Require the exact live unique column tuples on the actual source, independently from index naming.
     * @param  list<non-empty-list<string>>  $sets
     */
    public function assertUniqueIndexes(Connection $connection, string $table, array $sets): void
    {
        $indexes = $connection->getSchemaBuilder()->getIndexes($table);
        foreach ($sets as $columns) {
            $present = false;
            foreach ($indexes as $index) {
                if ($index['unique'] && $index['columns'] === $columns && $this->isCompleteIndex($connection, $table, $index['name'])) {
                    $present = true;
                    break;
                }
            }
            if (! $present) {
                throw new OwnershipSchemaNotReady('A required unique ownership or provider identity constraint is missing.');
            }
        }
    }

    /** Reject partial, invalid and prefix-only indexes that do not protect every complete provider key. */
    private function isCompleteIndex(Connection $connection, string $table, string $name): bool
    {
        $physical = $connection->getTablePrefix().$table;
        $complete = match ($connection->getDriverName()) {
            'sqlite' => $connection->selectOne('SELECT name FROM pragma_index_list(?) WHERE name = ? AND partial = 0 AND "unique" = 1', [$physical, $name], false),
            'pgsql' => $connection->selectOne('SELECT c.relname FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE i.indrelid = ?::regclass AND c.relname = ? AND i.indisvalid AND i.indisready AND i.indpred IS NULL AND i.indexprs IS NULL', [$physical, $name], false),
            'mysql', 'mariadb' => $connection->selectOne('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? GROUP BY INDEX_NAME HAVING COUNT(*) > 0 AND SUM(SUB_PART IS NOT NULL) = 0', [$physical, $name], false),
            default => null,
        };

        return $complete !== null;
    }
}
