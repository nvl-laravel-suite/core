<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Services;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Nvl\Support\Tenancy\Contracts\TenantInstallationState;

/** Resolves the current generation's persisted adoption probe for retained services. */
final readonly class DisabledTenantInstallationState implements TenantInstallationState
{
    /** Retain the application rather than a previous worker scope. */
    public function __construct(private Container $container) {}

    /** Admit the resource through the current scope's marker probe. */
    public function assertUsable(string $resource): void
    {
        $this->container->make(PersistedTenantStorage::class)->assertUsable($resource);
    }

    /** Check actual storage for any applicable adoption marker. */
    public function assertUnadopted(Connection $connection, ?string $resource = null): void
    {
        $this->container->make(PersistedTenantStorage::class)->assertUnadopted($connection, $resource);
    }

    /** Clear the current scope's probes after authorized schema changes. */
    public function invalidate(): void
    {
        $this->container->make(PersistedTenantStorage::class)->invalidate();
    }
}
