<?php

declare(strict_types=1);

namespace Nvl\Support\Consumer;

use Composer\InstalledVersions;
use JsonException;
use RuntimeException;
use stdClass;

/**
 * Discovers consumer declarations from independently installed NVL packages.
 *
 * @api
 *
 * @phpstan-type InstalledPackage array{path: string, psr4: array<string, string>}
 */
final readonly class ConsumerApiCatalog
{
    private const array WorkbenchPackages = ['nvl/suite', 'nvl/laravel-suite', 'nvl/suite-workbench'];

    private const array IdentityMethods = ['getKey', 'getKeyName', 'getMorphClass', 'getRouteKey', 'getRouteKeyName', 'is', 'isNot', 'relationLoaded'];

    /**
     * Retain validated declarations and their canonical installed ownership.
     *
     * @param  array<string, ConsumerSymbol>  $symbols
     * @param  array<string, ConsumerModelPolicy>  $models
     * @param  array<string, string>  $tables
     * @param  array<string, InstalledPackage>  $packages
     * @param  array<string, list<string>>  $traits
     */
    private function __construct(
        private array $symbols,
        private array $models,
        private array $tables,
        private array $packages,
        private array $traits,
    ) {}

    /**
     * Load the catalogs of actual installed NVL code packages without booting them.
     */
    public static function installed(): self
    {
        $symbols = [];
        $models = [];
        $tables = [];
        $packages = [];
        $traits = [];

        foreach (self::installedPaths() as $package => $root) {
            $relativeCatalog = $package === 'nvl/core'
                ? 'support/resources/consumer-api.json'
                : 'resources/consumer-api.json';
            if (! is_file($root.'/'.$relativeCatalog)) {
                throw self::invalid($package, "Missing {$relativeCatalog}; regenerate the package catalog or upgrade to a release that ships it.");
            }

            $path = self::ownedPath($package, $root, $relativeCatalog, false);
            $catalog = self::readCatalog($package, $path);
            if (($catalog['schema_version'] ?? null) !== 1) {
                throw self::invalid($package, 'schema_version must be the supported integer 1; upgrade matching package/tooling releases.');
            }
            if (($catalog['package'] ?? null) !== $package) {
                throw self::invalid($package, 'package identity does not match its Composer installation.');
            }

            $relativeRoots = self::map($catalog['psr4'] ?? null, $package, 'psr4');
            if ($relativeRoots === []) {
                throw self::invalid($package, 'psr4 must declare its runtime source roots.');
            }
            $sourceRoots = [];
            foreach ($relativeRoots as $prefix => $relative) {
                if (! str_ends_with($prefix, '\\') || ! self::className(substr($prefix, 0, -1)) || ! is_string($relative)) {
                    throw self::invalid($package, 'psr4 requires namespace prefixes and relative source directories.');
                }
                $sourceRoots[$prefix] = self::ownedPath($package, $root, $relative, true);
                foreach ($packages as $previousPackage => $previous) {
                    foreach ($previous['psr4'] as $previousRoot) {
                        if (self::within($sourceRoots[$prefix], $previousRoot) || self::within($previousRoot, $sourceRoots[$prefix])) {
                            throw self::invalid($package, "Conflicting source ownership with [{$previousPackage}].");
                        }
                    }
                }
            }
            $packages[$package] = ['path' => $root, 'psr4' => $sourceRoots];

            foreach (self::map($catalog['symbols'] ?? null, $package, 'symbols') as $class => $declaration) {
                if (! self::className($class)) {
                    throw self::invalid($package, 'symbols require exact class names.');
                }
                if (isset($symbols[$class])) {
                    throw self::invalid($package, "Conflicting symbol ownership for [{$class}].");
                }
                $declaration = self::map($declaration, $package, "symbol [{$class}]");
                $kind = $declaration['kind'] ?? null;
                if (! is_string($kind) || ! in_array($kind, ['class', 'interface', 'trait', 'enum'], true)) {
                    throw self::invalid($package, "Invalid kind for symbol [{$class}].");
                }
                $file = $declaration['file'] ?? null;
                $location = self::symbolLocation($class, $relativeRoots);
                if (! is_string($file) || $location === null || $file !== $location['file']) {
                    throw self::invalid($package, "Symbol [{$class}] file does not match its declared PSR-4 identity.");
                }
                $sourcePath = self::ownedPath($package, $root, $file, false);
                if (! self::within($sourcePath, $sourceRoots[$location['prefix']])) {
                    throw self::invalid($package, "Symbol [{$class}] file resolves outside its declared PSR-4 source root.");
                }
                $aliasOf = null;
                if (array_key_exists('alias_of', $declaration)) {
                    $alias = $declaration['alias_of'];
                    if (! is_string($alias) || ! self::className($alias) || $alias === $class) {
                        throw self::invalid($package, "Symbol [{$class}] alias_of requires another exact class name.");
                    }
                    $aliasOf = $alias;
                }
                $symbols[$class] = new ConsumerSymbol(
                    package: $package,
                    class: $class,
                    kind: $kind,
                    file: $file,
                    methods: self::names($declaration['methods'] ?? null, $package, 'methods'),
                    properties: self::names($declaration['properties'] ?? null, $package, 'properties'),
                    constants: self::names($declaration['constants'] ?? null, $package, 'constants'),
                    canonicalSourcePath: $sourcePath,
                    aliasOf: $aliasOf,
                );
            }

            foreach (self::map($catalog['models'] ?? null, $package, 'models') as $class => $declaration) {
                $symbol = $symbols[$class] ?? null;
                if ($symbol === null || $symbol->package !== $package || $symbol->kind !== 'class') {
                    throw self::invalid($package, "model [{$class}] requires a selected class in this package.");
                }
                $declaration = self::map($declaration, $package, "model [{$class}]");
                $identityMethods = self::names($declaration['identity_methods'] ?? null, $package, 'identity_methods');
                if (array_diff($identityMethods, self::IdentityMethods) !== []) {
                    throw self::invalid($package, "model [{$class}] identity_methods contain unsupported operations.");
                }
                $models[$class] = new ConsumerModelPolicy(
                    class: $class,
                    readableFields: self::names($declaration['read'] ?? null, $package, 'read'),
                    identityMethods: $identityMethods,
                    capabilityRelations: self::names($declaration['capability_relations'] ?? null, $package, 'capability_relations'),
                );
            }

            foreach (self::map($catalog['capability_relations'] ?? null, $package, 'capability_relations') as $trait => $relations) {
                $symbol = $symbols[$trait] ?? null;
                if ($symbol === null || $symbol->package !== $package || $symbol->kind !== 'trait') {
                    throw self::invalid($package, "Capability trait [{$trait}] requires a selected trait in this package.");
                }
                $relations = self::names($relations, $package, 'capability_relations');
                if (array_diff($relations, $symbol->methods) !== []) {
                    throw self::invalid($package, "Capability trait [{$trait}] declares unavailable relation methods.");
                }
                $traits[$trait] = $relations;
            }

            foreach (self::map($catalog['tables'] ?? null, $package, 'tables') as $identity => $table) {
                if (! is_string($table) || $table === '' || trim($table) !== $table || str_contains($table, "\0")) {
                    throw self::invalid($package, "Table [{$identity}] requires an exact nonempty name.");
                }
                if (isset($tables[$table]) && $tables[$table] !== $package) {
                    throw self::invalid($package, "Conflicting table ownership for [{$table}].");
                }
                $tables[$table] = $package;
            }
        }

        self::validateAliases($symbols);

        return new self($symbols, $models, $tables, $packages, $traits);
    }

    /**
     * Return one supported symbol by its exact declared class name.
     */
    public function symbol(string $class): ?ConsumerSymbol
    {
        return $this->symbols[ltrim($class, '\\')] ?? null;
    }

    /**
     * Return the explicit in-memory policy of one package model handle.
     */
    public function model(string $class): ?ConsumerModelPolicy
    {
        return $this->models[ltrim($class, '\\')] ?? null;
    }

    /**
     * Return the installed owner of one exact canonical default table name.
     */
    public function tableOwner(string $table): ?string
    {
        return $this->tables[$table] ?? null;
    }

    /**
     * Retain installed package and source roots for infrastructure checks.
     *
     * @internal
     *
     * @return array<string, InstalledPackage>
     */
    public function installedRoots(): array
    {
        return $this->packages;
    }

    /**
     * Resolve a real source file's package without trusting its PHP namespace.
     *
     * @internal
     */
    public function packageForFile(string $file): ?string
    {
        $path = str_contains($file, "\0") ? false : realpath($file);
        if ($path === false || ! is_file($path)) {
            return null;
        }
        $path = self::normalizePath($path);
        foreach ($this->packages as $package => $installed) {
            foreach ($installed['psr4'] as $sourceRoot) {
                if (self::within($path, $sourceRoot)) {
                    return $package;
                }
            }
        }

        return null;
    }

    /**
     * Return a selected capability trait's exact relation names.
     *
     * @internal
     *
     * @return list<string>
     */
    public function capabilityRelations(string $trait): array
    {
        return $this->traits[ltrim($trait, '\\')] ?? [];
    }

    /**
     * Resolve actual package paths while excluding provided or replaced names.
     *
     * @return array<string, string>
     */
    private static function installedPaths(): array
    {
        $paths = [];
        foreach (InstalledVersions::getAllRawData() as $dataset) {
            foreach ($dataset['versions'] as $package => $version) {
                if (! str_starts_with($package, 'nvl/') || in_array($package, self::WorkbenchPackages, true)) {
                    continue;
                }
                $installedPath = $version['install_path'] ?? null;
                if ($installedPath === null) {
                    if (isset($version['provided']) || isset($version['replaced'])) {
                        continue;
                    }
                    throw self::invalid($package, 'Composer installation path is missing; reinstall or upgrade the code package.');
                }
                $root = str_contains($installedPath, "\0") ? false : realpath($installedPath);
                if ($root === false || ! is_dir($root)) {
                    throw self::invalid($package, 'Composer installation path is unavailable; reinstall or upgrade the package.');
                }
                $root = self::normalizePath($root);
                if (isset($paths[$package]) && $paths[$package] !== $root) {
                    throw self::invalid($package, 'Conflicting Composer installation paths.');
                }
                $paths[$package] = $root;
            }
        }
        ksort($paths);

        return $paths;
    }

    /**
     * Require public aliases to resolve without cycles to the same canonical surface.
     *
     * @param  array<string, ConsumerSymbol>  $symbols
     */
    private static function validateAliases(array $symbols): void
    {
        foreach ($symbols as $symbol) {
            if ($symbol->aliasOf === null) {
                continue;
            }
            $seen = [$symbol->class => true];
            $targetName = $symbol->aliasOf;
            while (true) {
                if (isset($seen[$targetName])) {
                    throw self::invalid($symbol->package, "Cyclic alias for [{$symbol->class}].");
                }
                $target = $symbols[$targetName] ?? null;
                if ($target === null) {
                    throw self::invalid($symbol->package, "Public alias [{$symbol->class}] has an unavailable target [{$targetName}].");
                }
                $seen[$targetName] = true;
                if ($target->aliasOf === null) {
                    break;
                }
                $targetName = $target->aliasOf;
            }
            if ($target->kind !== $symbol->kind
                || count($target->methods) !== count($symbol->methods) || array_diff($target->methods, $symbol->methods) !== []
                || count($target->properties) !== count($symbol->properties) || array_diff($target->properties, $symbol->properties) !== []
                || count($target->constants) !== count($symbol->constants) || array_diff($target->constants, $symbol->constants) !== []) {
                throw self::invalid($symbol->package, "Public alias [{$symbol->class}] differs from its canonical kind/member surface.");
            }
        }
    }

    /**
     * Decode only the already validated catalog JSON file.
     *
     * @return array<string, mixed>
     */
    private static function readCatalog(string $package, string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw self::invalid($package, 'consumer-api.json is unreadable; reinstall or regenerate the package catalog.');
        }
        try {
            $value = json_decode($contents, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid consumer API catalog for [{$package}]: malformed JSON; regenerate the package catalog.", previous: $exception);
        }

        return self::map($value, $package, 'catalog');
    }

    /**
     * Validate a catalog object without inferring values from executable PHP.
     *
     * @return array<string, mixed>
     */
    private static function map(mixed $value, string $package, string $field): array
    {
        if (! $value instanceof stdClass) {
            throw self::invalid($package, "{$field} must be an object map.");
        }
        $map = [];
        foreach (get_object_vars($value) as $key => $item) {
            if (! is_string($key) || $key === '') {
                throw self::invalid($package, "{$field} requires exact string keys.");
            }
            $map[$key] = $item;
        }

        return $map;
    }

    /**
     * Validate exact field or member names without wildcard permissions.
     *
     * @return list<string>
     */
    private static function names(mixed $value, string $package, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::invalid($package, "{$field} must be a list of exact names.");
        }
        $names = [];
        foreach ($value as $name) {
            if (! is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1 || in_array($name, $names, true)) {
                throw self::invalid($package, "{$field} requires unique exact names.");
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Match a symbol to the longest declared PSR-4 namespace prefix.
     *
     * @param  array<string, mixed>  $roots
     * @return array{file: string, prefix: string}|null
     */
    private static function symbolLocation(string $class, array $roots): ?array
    {
        $prefixes = array_keys($roots);
        usort($prefixes, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ($prefixes as $prefix) {
            $relative = $roots[$prefix];
            if (str_starts_with($class, $prefix) && is_string($relative)) {
                return ['file' => rtrim($relative, '/').'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php', 'prefix' => $prefix];
            }
        }

        return null;
    }

    /**
     * Require local relative paths whose canonical target belongs to the package.
     */
    private static function ownedPath(string $package, string $root, string $relative, bool $directory): string
    {
        $parts = explode('/', rtrim($relative, '/'));
        if ($relative === '' || str_contains($relative, '\\') || str_contains($relative, ':') || str_contains($relative, "\0")
            || in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw self::invalid($package, 'Catalog paths require local nonescaping relative identities.');
        }
        $path = realpath($root.'/'.$relative);
        if ($path === false || ($directory ? ! is_dir($path) : ! is_file($path))) {
            throw self::invalid($package, "Catalog path [{$relative}] is unavailable; regenerate or upgrade the package.");
        }
        $path = self::normalizePath($path);
        if (! self::within($path, $root)) {
            throw self::invalid($package, "Catalog path [{$relative}] resolves outside its installed package.");
        }

        return $path;
    }

    /**
     * Validate a declared namespace without treating that namespace as ownership.
     */
    private static function className(string $class): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $class) === 1;
    }

    /**
     * Compare canonical paths at directory boundaries.
     */
    private static function within(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root.'/');
    }

    /**
     * Normalize platform separators without changing path case or ownership.
     */
    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Produce an actionable infrastructure failure without HTTP response metadata.
     */
    private static function invalid(string $package, string $reason): RuntimeException
    {
        return new RuntimeException("Invalid consumer API catalog for [{$package}]: {$reason}");
    }
}
