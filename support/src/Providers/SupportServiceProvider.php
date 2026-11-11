<?php

declare(strict_types=1);

namespace Nvl\Support\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Support\ServiceProvider;
use Nvl\Support\Bindings\RequiredBindings;
use Nvl\Support\Console\Commands\QueueQuarantineRetryCommand;
use Nvl\Support\Events\ConnectionCommitCallbacks;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Logging\PackageLogger;
use Nvl\Support\Models\ConfiguredModelResolver;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipBatchOptions;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipMaintenanceState;
use Nvl\Support\Ownership\Contracts\OwnershipModelGuard;
use Nvl\Support\Ownership\Contracts\OwnershipModelPersistence;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Contracts\OwnershipSystemRunner;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Services\DisabledOwnershipBatchOptions;
use Nvl\Support\Ownership\Services\DisabledOwnershipContext;
use Nvl\Support\Ownership\Services\DisabledOwnershipModelGuard;
use Nvl\Support\Ownership\Services\DisabledOwnershipQueueContext;
use Nvl\Support\Ownership\Services\InactiveOwnershipMaintenance;
use Nvl\Support\Ownership\Services\OwnershipContextParticipants;
use Nvl\Support\Ownership\Services\OwnershipQueuePublication;
use Nvl\Support\Ownership\Services\OwnershipQueueQuarantine;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipStorageProbes;
use Nvl\Support\Ownership\Services\UnavailableOwnershipQueueAdmission;
use Nvl\Support\Ownership\Services\UnavailableOwnershipReadiness;
use Nvl\Support\Ownership\Services\UnavailableOwnershipSystemRunner;
use Nvl\Support\Ownership\Services\UnownedOwnershipBoundary;
use Nvl\Support\Ownership\Services\UnownedOwnershipModelPersistence;
use Nvl\Support\Ownership\Services\UnownedOwnershipWriter;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;

/** Registers the native shared runtime without activating domain or legacy providers. */
final class SupportServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    /** Bind shared contracts and safe defaults without touching storage or host queues. */
    public function register(): void
    {
        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-core.php', 'core');
        $this->app->singletonIf(OwnerRegistry::class);
        $this->app->singletonIf(OwnershipResourceRegistry::class);
        $this->app->singletonIf(OwnershipContextParticipants::class);
        $this->app->scopedIf(OwnershipMaintenanceState::class, InactiveOwnershipMaintenance::class);
        $this->app->scopedIf(OwnershipStorageProbes::class);
        $this->app->bindIf(OwnershipModelGuard::class, DisabledOwnershipModelGuard::class);
        $this->app->bindIf(OwnershipModelPersistence::class, UnownedOwnershipModelPersistence::class);
        $this->app->bindIf(OwnershipWriter::class, UnownedOwnershipWriter::class);
        $this->app->bindIf(OwnershipReadiness::class, UnavailableOwnershipReadiness::class);
        $this->app->bindIf(OwnershipQueueContext::class, DisabledOwnershipQueueContext::class);
        $this->app->bindIf(OwnershipSystemRunner::class, UnavailableOwnershipSystemRunner::class);
        $this->app->bindIf(OwnershipBatchOptions::class, DisabledOwnershipBatchOptions::class);
        $this->app->scopedIf(OwnershipContext::class, DisabledOwnershipContext::class);
        $this->app->bindIf(OwnershipBoundary::class, UnownedOwnershipBoundary::class);
        $this->app->singletonIf(ConfiguredModelResolver::class);
        $this->app->singletonIf(ConnectionCommitCallbacks::class, static fn (Application $app): ConnectionCommitCallbacks => new ConnectionCommitCallbacks(static fn (): DatabaseTransactionsManager => $app->make('db.transactions')));
        $this->app->singletonIf(DomainEventDispatcher::class, static fn (Application $app): DomainEventDispatcher => new DomainEventDispatcher(
            static fn (): Dispatcher => $app->make(Dispatcher::class),
            $app->make(ConnectionCommitCallbacks::class),
        ));
        $this->app->singletonIf(RequiredBindings::class);
        $this->app->singletonIf(PackageLogger::class, static fn (Application $app): PackageLogger => new PackageLogger(
            $app->make(Repository::class), static fn (): LogManager => $app->make(LogManager::class),
        ));
        $this->app->singletonIf(GlobalNames::class);
    }

    /** Publish canonical resources, the raw quarantine retry command and the opted-in job absence guard. */
    public function boot(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, [UnavailableOwnershipQueueAdmission::class, 'handle']);
        $events->listen(JobRetryRequested::class, [OwnershipQueueQuarantine::class, 'beforeNativeRetry']);
        OwnershipQueuePublication::register();
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'nvl-core');
        $this->publishes([__DIR__.'/../../lang' => lang_path('vendor/nvl-core')], 'nvl-core-translations');
        $this->publishes([__DIR__.'/../../config/nvl-core.php' => config_path('nvl-core.php')], 'nvl-core-config');
        if ($this->app->runningInConsole()) {
            $this->commands([QueueQuarantineRetryCommand::class]);
            $this->publishes([__DIR__.'/../../resources/boost/skills' => base_path('.agents/skills')], 'nvl-core-skills');
        }
    }
}
