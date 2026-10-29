<?php

declare(strict_types=1);

namespace Nvl\Support\Traits;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Nvl\Support\Config\PackageConfiguration;
use Nvl\Support\Config\PackageConfigurationMerger;
use Nvl\Support\Installation\PackageInstallation;
use Nvl\Support\Providers\SupportServiceProvider;
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
        $key = PackageConfiguration::key($key);
        if ($key !== 'nvl-core') {
            $this->app->register(SupportServiceProvider::class);
        }
        $component = PackageConfiguration::logical($key);
        PackageInstallation::register($this->app, 'nvl/'.$component, [
            $key => [
                'source' => dirname($path).'/../resources/config/'.basename($path),
                'target' => $key.'.php',
                'tag' => $key.'-config',
            ],
        ]);
        if ($this->app->configurationIsCached()) {
            return;
        }

        $defaults = $this->configurationMap($this->app->make(Filesystem::class)->getRequire($path));

        $configuration = $this->app->make(Repository::class);

        $host = $this->configurationMap($configuration->get($key, []));
        $configuration->set($key, PackageConfigurationMerger::merge($defaults, $host));
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
