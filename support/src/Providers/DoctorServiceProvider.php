<?php

declare(strict_types=1);

namespace Nvl\Support\Providers;

use Illuminate\Support\ServiceProvider;
use Nvl\Support\Console\DoctorCommand;
use Nvl\Support\Doctor\CoreDoctor;
use Nvl\Support\Doctor\DoctorRegistry;
use Nvl\Support\Doctor\PackageDoctorContributor;

/**
 * Registers the standalone consumer Doctor without depending on the suite workbench.
 */
final class DoctorServiceProvider extends ServiceProvider
{
    /**
     * Bind the lazy registry of loaded package contributors.
     */
    public function register(): void
    {
        $this->app->singleton(DoctorRegistry::class);
        PackageDoctorContributor::register($this->app, 'nvl/core', fn (): array => $this->app->make(CoreDoctor::class)->inspect());
    }

    /**
     * Expose the consumer diagnostics command to Artisan.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DoctorCommand::class]);
        }
    }
}
