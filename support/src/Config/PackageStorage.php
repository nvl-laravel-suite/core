<?php

declare(strict_types=1);

namespace Nvl\Support\Config;

use BackedEnum;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Nvl\Support\Schema\SchemaIdentities;
use UnitEnum;

/** Resolves canonical package tables and connections. */
final class PackageStorage
{
    /** Resolve a logical key, native constant value, or configured table name. */
    public static function resolveTable(string $package, string $identity): string
    {
        foreach (SchemaIdentities::package($package)['tables'] ?? [] as $key => $definition) {
            if (in_array($identity, [$key, $definition['constant'], $definition['default']], true)) {
                return self::table($package, $key, $definition['default']);
            }
        }

        foreach (SchemaIdentities::package($package)['tables'] ?? [] as $key => $definition) {
            if (self::table($package, $key, $definition['default']) === $identity) {
                return $identity;
            }
        }

        throw new InvalidArgumentException("Unknown {$package} table identity [{$identity}].");
    }

    /** Return one validated effective table name. */
    public static function table(string $package, string $key, string $default): string
    {
        $namespace = PackageConfiguration::key($package);
        $host = self::configuration(Config::get($namespace, []));
        $value = Arr::get($host, "tables.{$key}", $default);

        if (! is_string($value) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?\z/D', $value) !== 1) {
            throw new InvalidArgumentException("{$namespace}.tables.{$key} must be a safe table identifier.");
        }

        if ($package === 'ownership' && $value !== $default) {
            throw new InvalidArgumentException('Ownership protocol identities are fixed and cannot be renamed.');
        }

        return $value;
    }

    /** Normalize Laravel's explicit model connection aliases for storage APIs. */
    public static function connectionName(string|UnitEnum|null $connection): ?string
    {
        if ($connection instanceof BackedEnum) {
            return (string) $connection->value;
        }

        return $connection instanceof UnitEnum ? $connection->name : $connection;
    }

    /** Return the package connection, inheriting the suite default when omitted. */
    public static function connection(string $package): ?string
    {
        $namespace = PackageConfiguration::key($package);
        $host = self::configuration(Config::get($namespace, []));
        $value = $host['connection'] ?? Config::get('nvl-core.connection');

        if ($value !== null && (! is_string($value) || trim($value) === '')) {
            throw new InvalidArgumentException("{$namespace}.connection must be a connection name or null.");
        }

        return $value;
    }

    /** Resolve storage that can explicitly use a separate package connection. */
    public static function tableConnection(string $package, string $key): ?string
    {
        if ($package !== 'media' || $key !== 'owner_slot_operations') {
            return self::connection($package);
        }
        $host = self::configuration(Config::get('nvl-media', []));
        $canonical = 'connections.owner_slot_operations';
        $value = Arr::get($host, $canonical);
        if ($value !== null && (! is_string($value) || trim($value) === '')) {
            throw new InvalidArgumentException('media.connections.owner_slot_operations must be a connection name or null.');
        }

        return $value ?? self::connection($package);
    }

    /** Validate configuration maps at Laravel's untyped repository boundary.
     * @return array<string, mixed>
     */
    private static function configuration(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Package storage configuration must be a map.');
        }
        $configuration = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Package storage configuration keys must be strings.');
            }
            $configuration[$key] = $item;
        }

        return $configuration;
    }
}
