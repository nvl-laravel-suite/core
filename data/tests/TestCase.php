<?php

declare(strict_types=1);

namespace Nvl\Data\Tests;

use Nvl\Data\Providers\DataServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Data in an isolated Laravel application.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Enable the opt-in generated-types API for its HTTP contract tests.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('nvl-data.typescript.routes.enabled', true);
        $app['config']->set('nvl-data.typescript.routes.middleware', ['throttle:60,1']);
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DataServiceProvider::class];
    }
}
