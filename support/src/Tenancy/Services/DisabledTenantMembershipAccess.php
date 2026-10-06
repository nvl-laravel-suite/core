<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Support\Tenancy\Contracts\TenantMembershipAccess;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/** Denies membership admission without an enforcing runtime or host adapter. */
final readonly class DisabledTenantMembershipAccess implements TenantMembershipAccess
{
    /** Deny tenant access unless the host supplies a membership adapter. */
    public function assertMember(Authenticatable $actor, TenantId $tenant): void
    {
        throw new TenantBoundaryViolation('Tenant membership access requires the enforcing runtime.');
    }
}
