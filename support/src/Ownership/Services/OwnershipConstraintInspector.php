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
}
