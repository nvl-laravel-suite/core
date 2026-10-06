<?php

declare(strict_types=1);

namespace Nvl\Support\Schema;

use RuntimeException;

/**
 * Preserves the table and migration identities used by published NVL packages.
 *
 * @phpstan-type SchemaKey array{type: string, columns: list<string>}
 * @phpstan-type ForeignIdentity array{columns: list<string>, package: string, table: string, references: list<string>, required: bool}
 * @phpstan-type TableIdentity array{constant: string, legacy: string, default: string, columns: array<string, string>, create_migrations: list<string>, keys: list<SchemaKey>, foreign_keys: list<ForeignIdentity>}
 * @phpstan-type MigrationIdentity array{name: string, path: string, create: bool, legacy_checksum: string, creates: list<string>}
 * @phpstan-type PackageIdentity array{config: string, tables: array<string, TableIdentity>, migrations: array<string, MigrationIdentity>}
 */
final class SchemaIdentities
{
    /** @var array<string, PackageIdentity>|null */
    private static ?array $manifest = null;

    /**
     * Return the complete versioned schema identity map.
     *
     * @return array<string, PackageIdentity>
     */
    public static function all(): array
    {
        if (self::$manifest === null) {
            $contents = file_get_contents(__DIR__.'/../../resources/schema-identities.json');
            if ($contents === false) {
                throw new RuntimeException('The NVL schema identity manifest is unavailable.');
            }
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException('The NVL schema manifest must be a package map.');
            }
            $manifest = [];
            foreach ($decoded as $package => $definition) {
                if (! is_string($package) || ! is_array($definition) || ! is_string($definition['config'] ?? null)
                    || ! is_array($definition['tables'] ?? null) || ! is_array($definition['migrations'] ?? null)) {
                    throw new RuntimeException('Invalid schema package identity.');
                }
                $tables = [];
                foreach ($definition['tables'] as $key => $table) {
                    if (! is_string($key) || ! is_array($table)
                        || ! is_string($table['constant'] ?? null) || ! is_string($table['legacy'] ?? null) || ! is_string($table['default'] ?? null)) {
                        throw new RuntimeException('Invalid schema table identity.');
                    }
                    $tables[$key] = [
                        'constant' => $table['constant'], 'legacy' => $table['legacy'], 'default' => $table['default'],
                        'columns' => self::strings($table['columns'] ?? null),
                        'create_migrations' => self::stringList($table['create_migrations'] ?? null),
                        'keys' => self::keys($table['keys'] ?? null),
                        'foreign_keys' => self::foreignKeys($table['foreign_keys'] ?? null),
                    ];
                }
                $migrations = [];
                foreach ($definition['migrations'] as $key => $migration) {
                    if (! is_string($key) || ! is_array($migration) || ! is_string($migration['name'] ?? null)
                        || ! is_string($migration['path'] ?? null) || ! is_bool($migration['create'] ?? null)
                        || ! is_string($migration['legacy_checksum'] ?? null)) {
                        throw new RuntimeException('Invalid schema migration identity.');
                    }
                    $migrations[$key] = [
                        'name' => $migration['name'], 'path' => $migration['path'], 'create' => $migration['create'],
                        'legacy_checksum' => $migration['legacy_checksum'],
                        'creates' => self::stringList($migration['creates'] ?? null),
                    ];
                }
                $manifest[$package] = ['config' => $definition['config'], 'tables' => $tables, 'migrations' => $migrations];
            }
            foreach ($manifest as $definition) {
                foreach ($definition['tables'] as $table) {
                    foreach ($table['foreign_keys'] as $foreign) {
                        if (! isset($manifest[$foreign['package']]['tables'][$foreign['table']])) {
                            throw new RuntimeException('A released foreign key references an unknown package table.');
                        }
                        $parent = $manifest[$foreign['package']]['tables'][$foreign['table']];
                        if (array_diff($foreign['columns'], array_keys($table['columns'])) !== []
                            || array_diff($foreign['references'], array_keys($parent['columns'])) !== []) {
                            throw new RuntimeException('A released foreign key references an undeclared column.');
                        }
                    }
                }
            }
            self::$manifest = $manifest;
        }

        return self::$manifest;
    }

    /**
     * Return one package's historical and canonical storage identities.
     *
     * @return PackageIdentity|array{}
     */
    public static function package(string $package): array
    {
        return self::all()[$package] ?? [];
    }

    /**
     * Map an unmodified released or newly published file to its package migration.
     *
     * @return array{name: string, path: string}|null
     */
    public static function publishedMigration(string $path): ?array
    {
        $identity = (new SchemaMigrationPaths)->identity($path);

        return $identity !== null && $identity['published']
            ? ['name' => $identity['name'], 'path' => $identity['canonical']]
            : null;
    }

    /** @return list<SchemaKey> */
    private static function keys(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('Schema keys must be a list.');
        }
        $keys = [];
        foreach ($value as $key) {
            if (! is_array($key) || ! is_string($key['type'] ?? null)) {
                throw new RuntimeException('Invalid schema key.');
            }
            $keys[] = ['type' => $key['type'], 'columns' => self::stringList($key['columns'] ?? null)];
        }

        return $keys;
    }

    /** @return list<ForeignIdentity> */
    private static function foreignKeys(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('Foreign keys must be a list.');
        }

        $keys = [];
        foreach ($value as $key) {
            if (! is_array($key) || ! is_string($key['package'] ?? null) || ! is_string($key['table'] ?? null)
                || ! is_bool($key['required'] ?? null)) {
                throw new RuntimeException('Invalid released foreign key identity.');
            }
            $columns = self::stringList($key['columns'] ?? null);
            $references = self::stringList($key['references'] ?? null);
            if ($columns === [] || count($columns) !== count($references)) {
                throw new RuntimeException('Released foreign keys require matching local and referenced columns.');
            }
            $keys[] = ['columns' => $columns, 'package' => $key['package'], 'table' => $key['table'], 'references' => $references, 'required' => $key['required']];
        }

        return $keys;
    }

    /**
     * Validate a packaged string map or list without trusting decoded JSON types.
     *
     * @return array<string, string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Schema identity values must be a string map or list.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($item) || ! is_string($key)) {
                throw new RuntimeException('Invalid schema identity string.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('Schema migration identities must be string lists.');
        }
        $result = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new RuntimeException('Invalid schema migration identity string.');
            }
            $result[] = $item;
        }

        return $result;
    }

    private function __construct() {}
}
