<?php

declare(strict_types=1);

namespace Nvl\Data\Providers;

use Illuminate\Support\ServiceProvider;
use Nvl\Data\Console\Commands\CheckTypesCommand;
use Nvl\Data\Console\Commands\GenerateTypesCommand;
use Nvl\Data\Console\Commands\ShowTypesManifestCommand;
use Nvl\Data\Services\GeneratedArtifactSet;
use Nvl\Data\Services\GeneratedTypeFileCatalog;
use Nvl\Data\Services\GeneratedTypesGenerator;
use Nvl\Data\Services\GeneratedTypesLock;
use Nvl\Data\Services\GeneratedTypesManifestWriter;
use Nvl\Data\Services\GeneratedTypesPublisher;
use Nvl\Data\Services\GeneratedTypesRouteConfiguration;
use Nvl\Data\Services\TypeScriptConfigurator;
use Nvl\Data\Services\TypeScriptPathGuard;
use Nvl\Data\Services\TypeScriptSourceInspector;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Support\Doctor\DoctorCheck;
use Nvl\Support\Doctor\DoctorContributor;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use RuntimeException;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerServiceProvider;

/**
 * Registers the NVL Data transformation and generated TypeScript facilities.
 */
class DataServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    /**
     * Register configuration, discovery, and transformer services.
     */
    public function register(): void
    {
        $this->app->register(LaravelDataServiceProvider::class);
        $this->app->register(TypeScriptTransformerServiceProvider::class);

        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-data.php', 'nvl-data');

        $this->app->singleton(TypeScriptPathGuard::class);
        $this->app->singleton(TypeScriptSourceRegistry::class);
        $this->app->singleton(TypeScriptConfigurator::class);
        $this->app->singleton(TypeScriptSourceInspector::class);
        $this->app->singleton(GeneratedArtifactSet::class);
        $this->app->singleton(GeneratedTypesLock::class);
        $this->app->singleton(GeneratedTypeFileCatalog::class);
        $this->app->singleton(GeneratedTypesManifestWriter::class);
        $this->app->singleton(GeneratedTypesPublisher::class);
        $this->app->singleton(GeneratedTypesGenerator::class);
        $this->app->singleton(GeneratedTypesRouteConfiguration::class);

        if (! $this->app->bound('nvl.doctor.core.typescript-adoption')) {
            $this->app->singleton('nvl.doctor.core.typescript-adoption', static fn (): PackageDoctorContributor => new PackageDoctorContributor('nvl/core', static fn (): array => [
                new DoctorCheck('adoption.typescript', 'info', true, config('nvl-data.typescript.adopt_global_config') === true
                    ? 'Explicit TypeScript global configuration adoption from nvl-data.typescript.'
                    : 'Global TypeScript configuration remains host-owned; NVL generation uses isolated configuration.'),
            ]));
            $this->app->tag('nvl.doctor.core.typescript-adoption', DoctorContributor::class);
        }

        $configureTransformer = config('nvl-data.typescript.configure_transformer', true);

        if (! is_bool($configureTransformer)) {
            throw new RuntimeException(
                'nvl-data.typescript.configure_transformer must be a boolean.',
            );
        }

        $adoptGlobalConfig = config('nvl-data.typescript.adopt_global_config', false);
        if (! is_bool($adoptGlobalConfig)) {
            throw new RuntimeException('nvl-data.typescript.adopt_global_config must be a boolean.');
        }
        if ($configureTransformer && $adoptGlobalConfig) {
            $this->app->register(DataTypeScriptTransformerServiceProvider::class);
        }
    }

    /**
     * Publish package resources and load the optional declaration API.
     */
    public function boot(
        TypeScriptSourceRegistry $sourceRegistry,
        GeneratedTypesRouteConfiguration $routeConfiguration,
    ): void {
        $sourceRegistry->register(__DIR__.'/..', 'nvl/core');

        $this->publishes([
            __DIR__.'/../../config/nvl-data.php' => config_path('nvl-data.php'),
        ], ['data-config', 'nvl-data-config']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateTypesCommand::class,
                CheckTypesCommand::class,
                ShowTypesManifestCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
            ], 'data-skills');
            $this->publishes([
                __DIR__.'/../../resources/tooling/eslint.config.fragment.js' => base_path('nvl-data.eslint.config.js'),
                __DIR__.'/../../resources/tooling/prettierignore.fragment' => base_path('.nvl-data.prettierignore'),
            ], 'nvl-data-generated-types-tooling');
        }

        if ($routeConfiguration->enabled()) {
            $routeConfiguration->prefix();
            $routeConfiguration->middleware();
            $routeConfiguration->archiveEnabled();

            $this->loadRoutesFrom(__DIR__.'/../../routes/types.php');
        }
    }
}
