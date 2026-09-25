<?php

declare(strict_types=1);

namespace Nvl\Support\Tests;

use Illuminate\Foundation\Application;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Support in an isolated Laravel application.
 */
abstract class TestCase extends Orchestra
{
    /**
     * Return the providers required to boot Support in isolation.
     *
     * @param  Application  $app  Isolated Testbench application
     * @return list<class-string> Support package providers
     */
    protected function getPackageProviders($app): array
    {
        return [SupportServiceProvider::class];
    }
}
