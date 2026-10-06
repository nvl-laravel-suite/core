<?php

declare(strict_types=1);

namespace Nvl\Support\Traits;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Nvl\Support\Config\PackageConfigurationMerger;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Schema\SchemaIdentities;
use RuntimeException;

/**
 * Loads package defaults with recursive maps and atomic host lists.
 *
 * @mixin ServiceProvider
 */
trait MergesPackageConfiguration
{
    protected function mergePackageConfiguration(string $path, string $key): void
    {
        if ($key !== 'nvl-core') {
            $this->app->register(SupportServiceProvider::class);
        }
        if ($this->app->configurationIsCached()) {
            return;
        }

        $defaults = $this->configurationMap($this->app->make(Filesystem::class)->getRequire($path));

        $defaults = PackageStorage::normalize($key, $defaults);
        $package = $key === 'nvl-auth' ? 'auth' : $key;
        $tables = $this->configurationMap($defaults['tables'] ?? []);
        foreach (SchemaIdentities::package($package)['tables'] ?? [] as $logical => $definition) {
            $tables[$logical] ??= $definition['default'];
        }
        if ($tables !== []) {
            $defaults['tables'] = $tables;
        }
        if (SchemaIdentities::package($package) !== []) {
            $defaults['connection'] ??= null;
            $defaults['queue'] ??= ['connection' => null, 'name' => null];
            $defaults['locks'] ??= ['store' => null];
            $defaults['routes'] ??= ['middleware' => null];
            $defaults['authorization'] ??= ['guard' => null];
        }

        $configuration = $this->app->make(Repository::class);

        if (! $configuration->has($key)) {
            $configuration->set($key, PackageStorage::mirror($package, $defaults));

            return;
        }

        $host = $this->configurationMap($configuration->get($key));

        $explicit = array_values(array_filter(array_keys(Arr::dot($host)), static fn (string $path): bool => $path === 'connection' || str_starts_with($path, 'tables.') || str_starts_with($path, 'connections.')));
        $configuration->set("nvl-core.storage_explicit.{$package}", $explicit);
        $configuration->set("nvl-core.options_explicit.{$package}", array_keys(Arr::dot($host)));

        $host = PackageStorage::normalize($key, $host, reportDeprecated: true);

        $configuration->set(
            $key,
            PackageStorage::mirror($package, $this->configurationMap(PackageConfigurationMerger::merge($defaults, $host))),
        );
    }

    /** @return array<string, mixed> */
    private function configurationMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('Package configuration must be a map.');
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RuntimeException('Package configuration keys must be strings.');
            }
            $map[$key] = $item;
        }

        return $map;
    }
}
