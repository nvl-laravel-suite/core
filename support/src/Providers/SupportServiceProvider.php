<?php

declare(strict_types=1);

namespace Nvl\Support\Providers;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\ServiceProvider;
use Nvl\Support\Console\SchemaUpgradeCommand;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Schema\PackageMigrator;
use Nvl\Support\Schema\SchemaPreflight;
use Nvl\Support\Traits\MergesPackageConfiguration;

/**
 * Publishes the dependency-free Support package guidance.
 */
final class SupportServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;

    /** Register shared infrastructure and application-owned identity. */
    public function register(): void
    {
        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-core.php', 'nvl-core');
        $this->app->singleton(OwnerRegistry::class);
        $this->app->register(LocaleServiceProvider::class);
        $this->app->register(TenantServiceProvider::class);
        $this->app->register(DoctorServiceProvider::class);
        $this->app->extend('migrator', function (Migrator $migrator): PackageMigrator {
            return PackageMigrator::guard($migrator, $this->app->make(SchemaPreflight::class));
        });
    }

    /**
     * Publish Support's agent guidance for consumer applications.
     */
    public function boot(): void
    {
        $this->app->make(OwnerRegistry::class)->all();

        if ($this->app->runningInConsole()) {
            $this->commands([SchemaUpgradeCommand::class]);
            $this->publishes([
                __DIR__.'/../../config/nvl-core.php' => config_path('nvl-core.php'),
            ], 'nvl-core-config');
            $this->publishes([
                __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
            ], 'support-skills');
        }
    }
}
