<?php

declare(strict_types=1);

namespace Nvl\Support\Traits;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Nvl\Support\Config\PackageConfigurationMerger;
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
        if ($this->app->configurationIsCached()) {
            return;
        }

        $defaults = $this->app->make(Filesystem::class)->getRequire($path);

        if (! is_array($defaults)) {
            throw new RuntimeException("Package configuration [{$path}] must return an array.");
        }

        $configuration = $this->app->make(Repository::class);

        if (! $configuration->has($key)) {
            $configuration->set($key, $defaults);

            return;
        }

        $host = $configuration->get($key);

        if (! is_array($host)) {
            throw new RuntimeException("Configuration [{$key}] must contain an array.");
        }

        $configuration->set(
            $key,
            PackageConfigurationMerger::merge($defaults, $host),
        );
    }
}
