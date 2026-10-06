<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Authorizes an authenticated actor for one selected tenant.
 */
interface TenantMembershipAccess
{
    /**
     * Assert that an actor belongs to the selected tenant.
     */
    public function assertMember(Authenticatable $actor, TenantId $tenant): void;
}
