<?php

declare(strict_types=1);

namespace Nvl\Support\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Queue\Jobs\JobName;
use Illuminate\Support\ServiceProvider;
use Nvl\Support\Console\QueueQuarantineRetryCommand;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\Contracts\TenantInstallationState;
use Nvl\Support\Tenancy\Contracts\TenantMembershipAccess;
use Nvl\Support\Tenancy\Contracts\TenantOwnershipConfiguration;
use Nvl\Support\Tenancy\Contracts\TenantQueueContext;
use Nvl\Support\Tenancy\Contracts\TenantQueueHandler;
use Nvl\Support\Tenancy\Contracts\TenantRunner;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Support\Tenancy\Services\DisabledTenantBoundary;
use Nvl\Support\Tenancy\Services\DisabledTenantContext;
use Nvl\Support\Tenancy\Services\DisabledTenantDirectory;
use Nvl\Support\Tenancy\Services\DisabledTenantInstallationState;
use Nvl\Support\Tenancy\Services\DisabledTenantMembershipAccess;
use Nvl\Support\Tenancy\Services\DisabledTenantOwnershipConfiguration;
use Nvl\Support\Tenancy\Services\DisabledTenantQueueContext;
use Nvl\Support\Tenancy\Services\DisabledTenantRunner;
use Nvl\Support\Tenancy\Services\PersistedTenantStorage;
use Nvl\Support\Tenancy\Services\TenantContextParticipants;
use Nvl\Support\Tenancy\Services\TenantQueuePayload;
use Nvl\Support\Tenancy\Services\TenantQueueQuarantine;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Throwable;

/** Registers neutral ownership contracts and safe disabled defaults. */
final class TenantServiceProvider extends ServiceProvider
{
    /** Preserve host bindings and keep mutable tenant state outside singletons. */
    public function register(): void
    {
        $this->app->singletonIf(TenantResourceRegistry::class);
        $this->app->scopedIf(PersistedTenantStorage::class);
        $this->app->singletonIf(TenantContextParticipants::class);
        $this->app->scopedIf(TenantContext::class, DisabledTenantContext::class);
        foreach ([
            TenantBoundary::class => DisabledTenantBoundary::class,
            TenantDirectory::class => DisabledTenantDirectory::class,
            TenantMembershipAccess::class => DisabledTenantMembershipAccess::class,
            TenantRunner::class => DisabledTenantRunner::class,
            TenantQueueContext::class => DisabledTenantQueueContext::class,
            TenantInstallationState::class => DisabledTenantInstallationState::class,
            TenantOwnershipConfiguration::class => DisabledTenantOwnershipConfiguration::class,
        ] as $contract => $implementation) {
            $this->app->bindIf($contract, $implementation);
        }
    }

    /** Deny missing-runtime ownership before native jobs deserialize model identifiers. */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([QueueQuarantineRetryCommand::class]);
        }
        $events = $this->app->make(Dispatcher::class);
        $events->listen(JobRetryRequested::class, [TenantQueueQuarantine::class, 'beforeNativeRetry']);
        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            $payload = $event->job->payload();
            $jobHandler = $payload['job'] ?? null;
            $payload = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
            if (! array_key_exists('nvl_tenancy', $payload)) {
                return;
            }
            try {
                if (! is_array($payload['nvl_tenancy'])) {
                    throw new TenantBoundaryViolation('Queued work requires scalar tenant envelope metadata.');
                }
                $envelope = $this->app->make(TenantQueuePayload::class)->decode($payload['nvl_tenancy']);
                if ($this->app->bound('nvl.tenancy.runtime') && $this->app->make('config')->get('nvl-tenancy.enabled') === true) {
                    if ($envelope->context->mode === TenantContextMode::Disabled) {
                        throw new TenantBoundaryViolation('Queued tenant mode is incompatible with this enabled worker.');
                    }
                    if (! is_string($jobHandler) || $jobHandler === '') {
                        throw new TenantConfigurationInvalid('Captured tenant work requires a declared native queue handler.');
                    }
                    [$handlerClass, $handlerMethod] = JobName::parse($jobHandler);
                    if (! is_string($handlerClass) || $handlerClass === '' || $handlerMethod !== 'call') {
                        throw new TenantConfigurationInvalid('Captured tenant work requires a queue handler enforcing execution and terminal failure boundaries.');
                    }
                    $handler = $this->app->make($handlerClass);
                    if (! $handler instanceof TenantQueueHandler) {
                        throw new TenantConfigurationInvalid('Captured tenant work requires a queue handler enforcing execution and terminal failure boundaries.');
                    }
                    $data = [];
                    foreach ($payload as $key => $value) {
                        if (! is_string($key)) {
                            throw new TenantBoundaryViolation('Captured native queue data requires named fields.');
                        }
                        $data[$key] = $value;
                    }
                    $handler->validate($data);

                    return;
                }
                if ($envelope->context->mode !== TenantContextMode::Disabled) {
                    throw new TenantBoundaryViolation('Captured tenant work requires the enforcing runtime.');
                }
                $this->app->make(PersistedTenantStorage::class)->assertQueueUsable();
            } catch (Throwable $exception) {
                $this->app->make(TenantQueueQuarantine::class)->reject($event, $exception);
            }
        });
    }
}
