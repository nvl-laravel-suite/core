<?php

declare(strict_types=1);

namespace Nvl\Support\Traits;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Installation\PackageInstallation;
use RuntimeException;

/** Publishes only package-owned tags and preserves existing host routes and aliases. @mixin ServiceProvider */
trait RegistersNamespacedResources
{
    /** Derive a package's public component name from its provider namespace. */
    protected function publicationPackage(): string
    {
        $namespace = explode('\\', static::class)[1];

        return $namespace === 'Support' ? 'core' : Str::kebab($namespace);
    }

    /**
     * Namespace resource groups; legacy tags require explicit collision-safe compatibility.
     *
     * @param  array<string, string>  $paths
     * @param  string|list<string>|null  $groups
     */
    protected function publishes(mixed $paths, mixed $groups = null): void
    {
        $paths = $this->publicationPaths($paths);
        $package = $this->publicationPackage();
        PackageInstallation::register($this->app, 'nvl/'.($package === 'data' ? 'core' : $package), []);
        $configurationPaths = [];
        foreach ($paths as $source => $target) {
            $name = basename($source);
            if (preg_match('/^nvl-[a-z][a-z0-9-]*\.php$/D', $name) === 1 && basename(dirname($source)) === 'config') {
                $template = dirname($source).'/../resources/config/'.$name;
                if (! is_file($template)) {
                    throw new RuntimeException('The package common configuration template ['.$name.'] is missing.');
                }
                $configurationPaths[$template] = $target;
            } else {
                $configurationPaths[$source] = $target;
            }
        }
        $paths = $configurationPaths;
        $groups = is_array($groups) ? $groups : ($groups === null ? [] : [$groups]);
        $names = $this->app->make(GlobalNames::class);
        $registered = [];
        $owner = $package === 'data' ? 'core' : $package;
        $exists = function (string $name): bool {
            if (! array_key_exists($name, ServiceProvider::$publishGroups)) {
                return false;
            }
            $owned = $this->publicationPaths(ServiceProvider::$publishes[static::class] ?? []);
            if ($name === 'nvl-core-config') {
                foreach (ServiceProvider::$publishes as $provider => $resources) {
                    if (is_string($provider) && (str_starts_with($provider, 'Nvl\\Support\\') || str_starts_with($provider, 'Nvl\\Data\\'))) {
                        $owned = array_merge($owned, $this->publicationPaths($resources));
                    }
                }
            }

            return $owned === [] || array_diff_assoc($this->publicationPaths(ServiceProvider::$publishGroups[$name]), $owned) !== [];
        };
        $install = function (string $name) use ($paths): void {
            parent::publishes($paths, $name);
        };
        foreach ($groups as $group) {
            if ($group === 'config') {
                continue;
            }
            $canonical = str_starts_with($group, 'nvl-') ? $group : 'nvl-'.($package === 'core' ? preg_replace('/^support-/', 'core-', $group) : $group);
            if (! isset($registered[$canonical])) {
                $names->reserve($owner, 'publish', $canonical, $exists, $install, append: true);
                $registered[$canonical] = true;
            }
            if ($canonical !== $group) {
                $names->register($package === 'data' ? 'core' : $package, 'publish', $group, $canonical,
                    static fn (string $name): bool => array_key_exists($name, ServiceProvider::$publishGroups),
                    function (string $name) use ($paths): void {
                        parent::publishes($paths, $name);
                    });
            }
            if (in_array($package, ['data', 'core'], true) && str_ends_with($canonical, '-config')) {
                $names->reserve($owner, 'publish', 'nvl-core-config', $exists, $install, append: true);
            }
        }
        if ($groups === []) {
            parent::publishes($paths);
        }
    }

    /** @return array<string, string> Validate Laravel's untyped publication registry. */
    private function publicationPaths(mixed $paths): array
    {
        if (! is_array($paths)) {
            throw new \InvalidArgumentException('Publication resources must be a path map.');
        }
        $validated = [];
        foreach ($paths as $source => $target) {
            if (! is_string($source) || ! is_string($target)) {
                throw new \InvalidArgumentException('Publication resources require string source and target paths.');
            }
            $validated[$source] = $target;
        }

        return $validated;
    }

    /** Preserve host route names and method paths while loading a package-owned route file. */
    protected function loadRoutesFrom(mixed $path): void
    {
        $this->app->make(GlobalNames::class)->loadRoutes($this->publicationPackage(), $this->app->make(Router::class),
            function () use ($path): void {
                parent::loadRoutesFrom($path);
            });
    }
}
