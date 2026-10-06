<?php

declare(strict_types=1);

namespace Nvl\Support\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\Contracts\TenantInstallationState;
use Nvl\Support\Tenancy\Contracts\TenantMembershipAccess;
use Nvl\Support\Tenancy\Contracts\TenantOwnershipConfiguration;
use Nvl\Support\Tenancy\Contracts\TenantQueueContext;
use Nvl\Support\Tenancy\Contracts\TenantRunner;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
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
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;

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
        $this->app->make(Dispatcher::class)->listen(JobProcessing::class, function (JobProcessing $event): void {
            if ($this->app->bound('nvl.tenancy.runtime')) {
                return;
            }
            $this->app->make(PersistedTenantStorage::class)->assertQueueUsable();
            $payload = $event->job->payload();
            $payload = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
            if (array_key_exists('nvl_tenancy', $payload)) {
                if (! is_array($payload['nvl_tenancy']) || $this->app->make(TenantQueuePayload::class)->decode($payload['nvl_tenancy'])->context->mode !== TenantContextMode::Disabled) {
                    throw new TenantBoundaryViolation('Captured tenant work requires the enforcing runtime.');
                }
            }
        });
    }
}
