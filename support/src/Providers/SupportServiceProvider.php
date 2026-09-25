<?php

declare(strict_types=1);

namespace Nvl\Support\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Publishes the dependency-free Support package guidance.
 */
final class SupportServiceProvider extends ServiceProvider
{
    /**
     * Publish Support's agent guidance for consumer applications.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
            ], 'support-skills');
        }
    }
}
