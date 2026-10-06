<?php

declare(strict_types=1);

namespace Nvl\Support\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Support\ServiceProvider;
use Nvl\Support\Console\SchemaPreflightCommand;
use Nvl\Support\Console\SchemaUpgradeCommand;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Schema\SchemaMigrationEvents;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;

/**
 * Publishes the dependency-free Support package guidance.
 */
final class SupportServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    /** Register shared infrastructure and application-owned identity. */
    public function register(): void
    {
        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-core.php', 'nvl-core');
        $this->app->singleton(OwnerRegistry::class);
        $this->app->singleton(GlobalNames::class);
        $this->app->register(LocaleServiceProvider::class);
        $this->app->register(TenantServiceProvider::class);
        $this->app->register(DoctorServiceProvider::class);
    }

    /**
     * Publish Support's agent guidance for consumer applications.
     */
    public function boot(Dispatcher $events): void
    {
        $this->app->make(OwnerRegistry::class)->all();
        $this->app->booted(function (): void {
            $this->app->make(GlobalNames::class)->bootRoutes($this->app);
        });
        $events->listen(MigrationStarted::class, [SchemaMigrationEvents::class, 'before']);

        if ($this->app->runningInConsole()) {
            $this->commands([SchemaUpgradeCommand::class, SchemaPreflightCommand::class]);
            $this->publishes([
                __DIR__.'/../../config/nvl-core.php' => config_path('nvl-core.php'),
            ], 'nvl-core-config');
            $this->publishes([
                __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
            ], 'support-skills');
        }
    }
}
