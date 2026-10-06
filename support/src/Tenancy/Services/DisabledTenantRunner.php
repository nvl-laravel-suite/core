<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Services;

use Closure;
use Illuminate\Container\Container;
use Nvl\Support\Tenancy\Contracts\TenantRunner;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Support\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/** Executes admitted ordinary work while denying tenant and platform privilege. */
final readonly class DisabledTenantRunner implements TenantRunner
{
    /** Retain the storage safety boundary. */
    public function __construct(private Container $container) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(TenantId $tenant, Closure $operation): mixed
    {
        throw new TenantContextMissing('Tenant execution requires the enforcing runtime.');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function platform(PlatformOperation $operation, Closure $callback): mixed
    {
        throw new TenantContextMissing('Platform execution requires the enforcing runtime.');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function withoutTenant(TenantContextSnapshot $snapshot, Closure $operation): mixed
    {
        if ($snapshot->mode !== TenantContextMode::Disabled) {
            throw new TenantBoundaryViolation('Captured tenant context requires the enforcing runtime.');
        }
        $this->container->make(PersistedTenantStorage::class)->assertQueueUsable();

        return $operation();
    }
}
