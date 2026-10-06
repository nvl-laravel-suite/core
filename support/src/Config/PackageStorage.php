<?php

declare(strict_types=1);

namespace Nvl\Support\Config;

use BackedEnum;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Nvl\Support\Schema\SchemaIdentities;
use UnitEnum;

/** Resolves package storage and translates explicit historical configuration. */
final class PackageStorage
{
    /** Resolve a logical key, historical constant value, or canonical constant value. */
    public static function resolveTable(string $package, string $identity): string
    {
        foreach (SchemaIdentities::package($package)['tables'] ?? [] as $key => $definition) {
            if (in_array($identity, [$key, $definition['constant'], $definition['legacy'], $definition['default']], true)) {
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
        $definition = SchemaIdentities::package($package)['tables'][$key] ?? null;
        if ($definition !== null) {
            $host = self::runtimeAlias($package, $host, "tables.{$key}", $default, self::tableAliases($package, $key, $definition));
        }
        $value = Arr::get($host, "tables.{$key}", $default);

        if (! is_string($value) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?\z/D', $value) !== 1) {
            throw new InvalidArgumentException("{$namespace}.tables.{$key} must be a safe table identifier.");
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
        $host = self::runtimeAlias($package, $host, 'connection', null, ['storage.connection']);
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
        $host = self::runtimeAlias($package, $host, $canonical, null, ['owner_slots.idempotency.connection']);
        $value = Arr::get($host, $canonical);
        if ($value !== null && (! is_string($value) || trim($value) === '')) {
            throw new InvalidArgumentException('media.connections.owner_slot_operations must be a connection name or null.');
        }

        return $value ?? self::connection($package);
    }

    /**
     * Translate an explicit host overlay before merging canonical defaults.
     *
     * @param  array<string, mixed>  $host
     * @return array<string, mixed>
     */
    public static function normalize(string $package, array $host, bool $reportDeprecated = false): array
    {
        $package = PackageConfiguration::logical($package);
        $host = PackageOptions::normalize($package, $host, $reportDeprecated);
        $host = self::alias($host, 'storage.connection', 'connection');
        if ($package === 'media') {
            if ($reportDeprecated && Arr::has($host, 'owner_slots.idempotency.connection')) {
                PackageOptions::recordDeprecation($package, 'owner_slots.idempotency.connection', 'connections.owner_slot_operations', Arr::get($host, 'connections.owner_slot_operations', Arr::get($host, 'owner_slots.idempotency.connection')), Arr::has($host, 'connections.owner_slot_operations') && Arr::get($host, 'connections.owner_slot_operations') !== Arr::get($host, 'owner_slots.idempotency.connection'));
            }
            $host = self::alias($host, 'owner_slots.idempotency.connection', 'connections.owner_slot_operations');
        }

        foreach (SchemaIdentities::package($package)['tables'] ?? [] as $key => $definition) {
            foreach (self::tableAliases($package, $key, $definition) as $old) {
                if ($reportDeprecated && $old !== "tables.{$key}" && Arr::has($host, $old)) {
                    $conflict = Arr::has($host, "tables.{$key}") && Arr::get($host, "tables.{$key}") !== Arr::get($host, $old);
                    PackageOptions::recordDeprecation($package, $old, "tables.{$key}", Arr::get($host, "tables.{$key}", Arr::get($host, $old)), $conflict);
                }
                $host = self::alias($host, $old, "tables.{$key}");
            }
        }

        if ($package === 'activity') {
            $host = self::alias($host, 'storage.table', 'tables.log');
        } elseif ($package === 'settings') {
            $host = self::alias($host, 'storage.table', 'tables.settings');
        }

        return $host;
    }

    /**
     * Add only declared historical shapes for validating explicit legacy roots.
     *
     * @param  array<string, mixed>  $defaults  Canonical defaults
     * @return array<string, mixed> Accepted canonical and historical shapes
     */
    public static function legacyDefaults(string $package, array $defaults): array
    {
        $package = PackageConfiguration::logical($package);
        $aliases = PackageOptions::aliases($package);
        $aliases['storage.connection'] = 'connection';
        if ($package === 'media') {
            $aliases['owner_slots.idempotency.connection'] = 'connections.owner_slot_operations';
        }
        foreach (SchemaIdentities::package($package)['tables'] ?? [] as $key => $definition) {
            foreach (self::tableAliases($package, $key, $definition) as $old) {
                $aliases[$old] = "tables.{$key}";
            }
        }
        foreach ($aliases as $old => $canonical) {
            if (! Arr::has($defaults, $old)) {
                self::addLegacyDefault($defaults, $old, Arr::get($defaults, $canonical));
            }
        }

        return $defaults;
    }

    /** @param array<string, mixed> $defaults Preserve the root option-map type while adding a declared dotted alias. */
    private static function addLegacyDefault(array &$defaults, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $root = array_shift($segments);
        if ($root === '' || ctype_digit($root)) {
            throw new InvalidArgumentException('Legacy option aliases must begin with a named configuration group.');
        }
        if ($segments === []) {
            $defaults[$root] = $value;

            return;
        }
        $group = $defaults[$root] ?? [];
        if (! is_array($group)) {
            throw new InvalidArgumentException("Legacy option group [{$root}] must be a map.");
        }
        Arr::set($group, implode('.', $segments), $value);
        $defaults[$root] = $group;
    }

    /**
     * Keep deprecated readers aligned with normalized canonical settings.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public static function mirror(string $package, array $configuration): array
    {
        $package = PackageConfiguration::logical($package);
        foreach (SchemaIdentities::package($package)['tables'] ?? [] as $key => $definition) {
            foreach (self::tableAliases($package, $key, $definition) as $alias) {
                if ($alias !== "tables.{$key}" && (Arr::has($configuration, $alias) || str_starts_with($alias, 'table_names.') && Arr::has($configuration, 'table_names'))) {
                    Arr::set($configuration, $alias, Arr::get($configuration, "tables.{$key}", $definition['default']));
                }
            }
        }
        foreach (PackageOptions::aliases($package) as $alias => $canonical) {
            if (Arr::has($configuration, $alias) && Arr::has($configuration, $canonical)) {
                $value = Arr::get($configuration, $canonical) ?? Config::get("nvl-core.{$canonical}");
                Arr::set($configuration, $alias, $value);
            }
        }

        return self::configuration($configuration);
    }

    /**
     * Preserve runtime legacy overrides when only an implicit canonical default exists.
     *
     * @param  array<string, mixed>  $host
     * @param  list<string>  $aliases
     * @return array<string, mixed>
     */
    private static function runtimeAlias(string $package, array $host, string $canonical, mixed $default, array $aliases): array
    {
        $explicit = Config::get("nvl-core.storage_explicit.{$package}", []);
        if (Arr::get($host, $canonical, $default) !== $default
            || (is_array($explicit) && in_array($canonical, $explicit, true))) {
            return $host;
        }
        foreach ($aliases as $alias) {
            if ($alias !== $canonical && Arr::has($host, $alias) && Arr::get($host, $alias) !== $default) {
                Arr::set($host, $canonical, Arr::get($host, $alias));

                return self::configuration($host);
            }
        }

        return $host;
    }

    /**
     * List historical table settings retained for one major release.
     *
     * @param  array{constant: string, legacy: string, default: string}  $definition
     * @return list<string>
     */
    private static function tableAliases(string $package, string $key, array $definition): array
    {
        $aliases = ["tables.{$definition['legacy']}", "tables.{$definition['default']}", "table_names.{$definition['legacy']}", "table_names.{$definition['default']}", "storage.tables.{$key}"];
        if (($package === 'activity' && $key === 'log') || ($package === 'settings' && $key === 'settings')) {
            $aliases[] = 'storage.table';
        }
        if ($package === 'media' && $key === 'owner_slot_operations') {
            $aliases[] = 'owner_slots.idempotency.table';
        }

        return $aliases;
    }

    /**
     * Copy an old explicit value only when its canonical replacement is absent.
     *
     * @param  array<string, mixed>  $host
     * @return array<string, mixed>
     */
    private static function alias(array $host, string $old, string $canonical): array
    {
        if (! Arr::has($host, $old) || Arr::has($host, $canonical)) {
            return $host;
        }
        Arr::set($host, $canonical, Arr::get($host, $old));

        return self::configuration($host);
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
