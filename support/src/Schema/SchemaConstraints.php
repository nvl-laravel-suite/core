<?php

declare(strict_types=1);

namespace Nvl\Support\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use LogicException;

/** Resolves database constraint names after configured or legacy table renames. */
final class SchemaConstraints
{
    /**
     * Drop a released key using its actual database name instead of a generated prefix.
     *
     * @param  list<string>  $columns
     */
    public static function drop(Builder $schema, Blueprint $table, string $type, array $columns = []): void
    {
        $keys = $type === 'foreign' ? $schema->getForeignKeys($table->getTable()) : $schema->getIndexes($table->getTable());
        foreach ($keys as $key) {
            if (($columns !== [] && $key['columns'] !== $columns)
                || ($type === 'primary' && ! ($key['primary'] ?? false))
                || ($type === 'unique' && (! ($key['unique'] ?? false) || $key['primary']))) {
                continue;
            }
            $identifier = is_string($key['name']) && $key['name'] !== '' ? $key['name'] : $columns;
            match ($type) {
                'foreign' => $table->dropForeign($identifier),
                'unique' => $table->dropUnique($identifier),
                'primary' => $table->dropPrimary($identifier),
                default => throw new LogicException("Unknown constraint type [{$type}]."),
            };

            return;
        }
        throw new LogicException("Missing {$type} constraint on [{$table->getTable()}]. Run nvl:doctor --strict before changing ownership.");
    }

    private function __construct() {}
}
