<?php

declare(strict_types=1);

namespace Nvl\Support\Config;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Resolves shared infrastructure from explicit package settings, Core, and Laravel.
 *
 * @api
 */
final class PackageOptions
{
    /**
     * Translate only explicit host aliases before canonical defaults are merged.
     *
     * @param  array<string, mixed>  $host
     * @return array<string, mixed>
     *
     * @internal
     */
    public static function normalize(string $package, array $host, bool $reportDeprecated = true): array
    {
        foreach (self::aliases($package) as $old => $canonical) {
            if (! Arr::has($host, $old)) {
                continue;
            }

            $canonicalValue = Arr::get($host, $canonical);
            $legacyValue = Arr::get($host, $old);
            $hasCanonical = Arr::has($host, $canonical);
            $conflict = $hasCanonical && $canonicalValue !== $legacyValue;

            if (! $hasCanonical) {
                Arr::set($host, $canonical, $legacyValue);
            }

            if ($reportDeprecated) {
                self::recordDeprecation($package, $old, $canonical, Arr::get($host, $canonical), $conflict);
            }
        }

        $normalized = [];
        foreach ($host as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Package configuration must use named options.');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Return explicit compatibility mappings without changing operation lifetimes or capabilities.
     *
     * @return array<string, string>
     *
     * @internal
     */
    public static function aliases(string $package): array
    {
        $package = PackageConfiguration::logical($package);
        $aliases = ['auth.guard' => 'authorization.guard'];

        if (in_array($package, ['activity', 'mail-notifications', 'settings', 'taxonomy'], true)) {
            $aliases['storage.connection'] = 'connection';
        }

        if ($package === 'activity') {
            $aliases['retention.queue'] = 'queue.name';
        } elseif ($package === 'tasks') {
            $aliases['activity.queue'] = 'queue.name';
        } elseif ($package === 'auth') {
            $aliases['guard'] = 'authorization.guard';
        } elseif ($package === 'templates') {
            $aliases['rendering.connection'] = 'queue.connection';
            $aliases['rendering.queue'] = 'queue.name';
        } elseif ($package === 'media') {
            $aliases['mutation_lock.store'] = 'locks.mutation.store';
            $aliases['deduplication_lock.store'] = 'locks.deduplication.store';
            $aliases['multipart.lock.store'] = 'locks.multipart.store';
            $aliases['routes.api_middleware'] = 'routes.middleware';
        } elseif ($package === 'comments') {
            $aliases['mutation_lock.store'] = 'locks.mutation.store';
        } elseif ($package === 'translations') {
            $aliases['lock.store'] = 'locks.store';
        }

        if (in_array($package, ['seo', 'settings', 'mail-notifications'], true)) {
            foreach (['enabled', 'prefix', 'path', 'name', 'middleware'] as $key) {
                $aliases["management.{$key}"] = 'routes.management.'.($key === 'path' ? 'prefix' : $key);
            }
        }

        return $aliases;
    }

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

    /**
     * Store a compatibility report in serializable configuration for cached applications.
     *
     * @param  mixed  $value  Effective canonical value before inheritance
     *
     * @internal
     */
    public static function recordDeprecation(string $package, string $old, string $canonical, mixed $value, bool $conflict): void
    {
        $namespace = self::namespace($package);
        $reports = Config::get('nvl-core.configuration.deprecations', []);
        $reports = is_array($reports) ? $reports : [];
        $reports["{$namespace}.{$old}"] ??= [
            'replacement' => "{$namespace}.{$canonical}",
            'value' => $value,
            'conflict' => $conflict,
        ];
        ksort($reports);
        Config::set('nvl-core.configuration.deprecations', $reports);
    }

    /**
     * Return deterministic compatibility diagnostics, optionally limited to one package.
     *
     * @return array<string, array{replacement: string, value: mixed, conflict: bool}>
     */
    public static function deprecations(?string $package = null): array
    {
        $reports = Config::get('nvl-core.configuration.deprecations', []);
        if (! is_array($reports)) {
            return [];
        }

        $valid = [];
        foreach ($reports as $key => $report) {
            if (! is_string($key)
                || ! is_array($report)
                || ! is_string($report['replacement'] ?? null)
                || ! is_bool($report['conflict'] ?? null)
                || ($package !== null && ! str_starts_with($key, self::namespace($package).'.'))) {
                continue;
            }
            $valid[$key] = [
                'replacement' => $report['replacement'],
                'value' => $report['value'] ?? null,
                'conflict' => $report['conflict'],
            ];
        }
        ksort($valid);

        return $valid;
    }

    /** Resolve one non-null package setting, configuration fallback, and Core default in that order. */
    private static function value(string $package, string $canonical, bool $inheritCore = true): mixed
    {
        $namespace = self::namespace($package);
        $value = Config::get("{$namespace}.{$canonical}");
        if ($value !== null) {
            return $value;
        }

        $logical = PackageConfiguration::logical($namespace);
        $explicit = Config::get("nvl-core.options_explicit.{$logical}", Config::get("nvl-core.storage_explicit.{$logical}", []));
        if (is_array($explicit) && in_array($canonical, $explicit, true)) {
            return $inheritCore ? Config::get("nvl-core.{$canonical}") : null;
        }

        foreach (self::aliases($package) as $old => $replacement) {
            if ($replacement === $canonical) {
                $value = Config::get("{$namespace}.{$old}");
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return $inheritCore ? Config::get("nvl-core.{$canonical}") : null;
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
