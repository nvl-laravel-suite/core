<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Nvl\Support\Traits\MergesPackageConfiguration;

/**
 * Exposes the package configuration merge hook for trait contract tests.
 */
final class ConfigurationServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;

    public function mergeConfiguration(string $path, string $key): void
    {
        $this->mergePackageConfiguration($path, $key);
    }
}
