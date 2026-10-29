<?php

declare(strict_types=1);

namespace Nvl\Support\Config;

use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Resolves shared infrastructure from explicit package settings, Core, and Laravel.
 *
 * @api
 */
final class PackageOptions
{
    /** Return the effective named database connection. */
    public static function connection(string $package): string
    {
        return self::name($package, 'connection', 'database.default');
    }

    /** Return the effective queue connection, including a deliberate sync driver. */
    public static function queueConnection(string $package): string
    {
        return self::name($package, 'queue.connection', 'queue.default');
    }

    /** Return the queue name selected by the effective queue connection when no override exists. */
    public static function queueName(string $package): string
    {
        $value = self::value($package, 'queue.name');
        if ($value !== null) {
            return self::validName($value, self::namespace($package).'.queue.name');
        }

        $connection = self::queueConnection($package);
        $value = Config::get("queue.connections.{$connection}.queue", 'default');

        return self::validName($value ?? 'default', "queue.connections.{$connection}.queue");
    }

    /** Return an operation-specific lock store before the package-wide inherited store. */
    public static function lockStore(string $package, ?string $operation = null): string
    {
        if ($operation !== null) {
            $value = self::value($package, "locks.{$operation}.store", inheritCore: false);
            if ($value !== null) {
                return self::validName($value, self::namespace($package).".locks.{$operation}.store");
            }
        }

        return self::name($package, 'locks.store', 'cache.default');
    }

    /** Return the effective authentication guard without granting an authorization ability. */
    public static function authGuard(string $package): string
    {
        return self::name($package, 'authorization.guard', 'auth.defaults.guard');
    }

    /**
     * Return an atomic middleware list and apply an explicitly selected guard to bare auth entries.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    public static function routeMiddleware(string $package, ?string $group = null, array $default = []): array
    {
        $namespace = self::namespace($package);
        $paths = $group === null ? [] : ["{$namespace}.routes.{$group}.middleware"];
        $paths[] = "{$namespace}.routes.middleware";
        if ($group !== null) {
            $paths[] = "nvl-core.routes.{$group}.middleware";
        }
        $paths[] = 'nvl-core.routes.middleware';
        $value = null;
        $source = "{$namespace}.routes.middleware";

        foreach ($paths as $path) {
            $value = Config::get($path);
            if ($value !== null) {
                $source = $path;
                break;
            }
        }

        $value ??= $default;
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("{$source} must be a list of middleware names or null.");
        }

        $middleware = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException("{$source} must contain nonempty middleware names.");
            }
            $middleware[] = $item === 'auth' && self::value($package, 'authorization.guard') !== null
                ? 'auth:'.self::authGuard($package)
                : $item;
        }

        return $middleware;
    }

    /** Preserve package migration opt-in defaults while validating an explicit override. */
    public static function migrationsEnabled(string $package, bool $default = true): bool
    {
        $value = Config::get(self::namespace($package).'.migrations.enabled') ?? $default;
        if (! is_bool($value)) {
            throw new InvalidArgumentException(self::namespace($package).'.migrations.enabled must be a boolean.');
        }

        return $value;
    }

    /** Resolve a non-null package option before its shared Core default. */
    private static function value(string $package, string $canonical, bool $inheritCore = true): mixed
    {
        return Config::get(self::namespace($package).'.'.$canonical)
            ?? ($inheritCore ? Config::get('nvl-core.'.$canonical) : null);
    }

    /** Resolve and validate a named infrastructure option. */
    private static function name(string $package, string $canonical, string $application): string
    {
        $value = self::value($package, $canonical) ?? Config::get($application);

        return self::validName($value, self::namespace($package).'.'.$canonical);
    }

    /** Reject empty and non-string settings rather than hiding configuration errors through inheritance. */
    private static function validName(mixed $value, string $key): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$key} must be a nonempty infrastructure name or null to inherit.");
        }

        return $value;
    }

    /** Resolve every package through its canonical configuration namespace. */
    private static function namespace(string $package): string
    {
        return PackageConfiguration::key($package);
    }

    private function __construct() {}
}
