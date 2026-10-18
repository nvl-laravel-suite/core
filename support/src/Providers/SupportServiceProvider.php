<?php

declare(strict_types=1);

namespace Nvl\Support\Providers;

use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Nvl\Support\Bindings\RequiredBindings;
use Nvl\Support\Console\InstallCommand;
use Nvl\Support\Console\SchemaPreflightCommand;
use Nvl\Support\Console\SchemaUpgradeCommand;
use Nvl\Support\Doctor\DoctorContributor;
use Nvl\Support\Doctor\PackageLoggingDoctor;
use Nvl\Support\Doctor\RequiredBindingsDoctor;
use Nvl\Support\Events\ConnectionCommitCallbacks;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Events\EventAliases;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Http\PackageExceptionPayload;
use Nvl\Support\Http\PackageExceptionRenderer;
use Nvl\Support\Installation\ConfigPublisher;
use Nvl\Support\Installation\InstallationRegistry;
use Nvl\Support\Logging\PackageLogger;
use Nvl\Support\Models\ConfiguredModelResolver;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Compatibility\LegacyOwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipBatchOptions;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipMaintenanceState;
use Nvl\Support\Ownership\Contracts\OwnershipModelGuard;
use Nvl\Support\Ownership\Contracts\OwnershipModelPersistence;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Services\DisabledOwnershipBatchOptions;
use Nvl\Support\Ownership\Services\DisabledOwnershipContext;
use Nvl\Support\Ownership\Services\DisabledOwnershipModelGuard;
use Nvl\Support\Ownership\Services\DisabledOwnershipQueueContext;
use Nvl\Support\Ownership\Services\InactiveOwnershipMaintenance;
use Nvl\Support\Ownership\Services\LegacyOwnershipModelPersistence;
use Nvl\Support\Ownership\Services\LegacyOwnershipWriter;
use Nvl\Support\Ownership\Services\OwnershipBatchRepository;
use Nvl\Support\Ownership\Services\OwnershipBusDispatcher;
use Nvl\Support\Ownership\Services\OwnershipContextParticipants;
use Nvl\Support\Ownership\Services\OwnershipQueueAdmission;
use Nvl\Support\Ownership\Services\OwnershipQueuePublication;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\UnavailableOwnershipReadiness;
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
        $this->app->singletonIf(OwnerRegistry::class);
        $this->app->singletonIf(OwnershipResourceRegistry::class);
        $this->app->singletonIf(OwnershipContextParticipants::class);
        $this->app->scopedIf(OwnershipMaintenanceState::class, InactiveOwnershipMaintenance::class);
        $this->app->bindIf(OwnershipModelGuard::class, DisabledOwnershipModelGuard::class);
        $this->app->bindIf(OwnershipModelPersistence::class, LegacyOwnershipModelPersistence::class);
        $this->app->bindIf(OwnershipWriter::class, LegacyOwnershipWriter::class);
        $this->app->bindIf(OwnershipReadiness::class, UnavailableOwnershipReadiness::class);
        $this->app->bindIf(OwnershipQueueContext::class, DisabledOwnershipQueueContext::class);
        $this->app->bindIf(OwnershipBatchOptions::class, DisabledOwnershipBatchOptions::class);
        $this->app->extend(DatabaseBatchRepository::class, static fn ($repository) => is_object($repository) && $repository::class === DatabaseBatchRepository::class ? OwnershipBatchRepository::fromNative($repository) : $repository);
        $this->app->extend(BusDispatcher::class, static fn ($dispatcher) => is_object($dispatcher) && $dispatcher::class === BusDispatcher::class ? OwnershipBusDispatcher::fromNative($dispatcher) : $dispatcher);
        $this->app->scopedIf(OwnershipContext::class, DisabledOwnershipContext::class);
        $this->app->bindIf(OwnershipBoundary::class, LegacyOwnershipBoundary::class);
        $this->app->singletonIf(ConfiguredModelResolver::class);
        $this->app->singletonIf(ConnectionCommitCallbacks::class, static fn (Application $app): ConnectionCommitCallbacks => new ConnectionCommitCallbacks(static fn (): DatabaseTransactionsManager => $app->make('db.transactions')));
        $this->app->singletonIf(DomainEventDispatcher::class, static fn (Application $app): DomainEventDispatcher => new DomainEventDispatcher(
            static fn (): Dispatcher => $app->make(Dispatcher::class),
            $app->make(ConnectionCommitCallbacks::class),
        ));
        $this->app->singletonIf(EventAliases::class);
        $this->app->singletonIf(RequiredBindings::class);
        $this->app->singletonIf(PackageExceptionPayload::class);
        $this->app->singletonIf(PackageExceptionRenderer::class);
        $this->app->singletonIf(RequiredBindingsDoctor::class);
        $this->app->singletonIf(InstallationRegistry::class);
        $this->app->singletonIf(ConfigPublisher::class);
        $this->app->singletonIf(PackageLogger::class, static fn (Application $app): PackageLogger => new PackageLogger(
            $app->make(Repository::class), static fn (): LogManager => $app->make(LogManager::class),
        ));
        $this->app->singletonIf(PackageLoggingDoctor::class);
        $this->app->tag([RequiredBindingsDoctor::class, PackageLoggingDoctor::class], DoctorContributor::class);
        $this->app->singleton(GlobalNames::class);
        $this->app->register(TenantServiceProvider::class);
        $this->app->register(DoctorServiceProvider::class);
    }

    /**
     * Publish Support's agent guidance for consumer applications.
     */
    public function boot(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, [OwnershipQueueAdmission::class, 'handle']);
        OwnershipQueuePublication::register();
        $this->app->make(GlobalNames::class)->translations('core', __DIR__.'/../../lang', $this->app->make('translation.loader'));
        $this->publishes([
            __DIR__.'/../../lang' => lang_path('vendor/nvl-core'),
        ], 'nvl-core-translations');
        $this->app->make(OwnerRegistry::class)->all();
        $this->app->booted(function (): void {
            $this->app->make(GlobalNames::class)->bootRoutes($this->app);
        });
        $events->listen(MigrationStarted::class, [SchemaMigrationEvents::class, 'before']);

        if ($this->app->runningInConsole()) {
            $this->commands([SchemaUpgradeCommand::class, SchemaPreflightCommand::class, InstallCommand::class]);
            $this->publishes([
                __DIR__.'/../../config/nvl-core.php' => config_path('nvl-core.php'),
            ], 'nvl-core-config');
            $this->publishes([
                __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
            ], 'support-skills');
        }
    }
}
