<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Services;

use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Support\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/** Denies lifecycle lookups when the enforcing tenant runtime is unavailable. */
final readonly class DisabledTenantDirectory implements TenantDirectory
{
    /** Require a loaded tenant directory runtime. */
    public function find(TenantId $tenant): TenantDescriptor
    {
        throw new TenantContextMissing('Tenant directory access requires the enforcing runtime.');
    }
}
