<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Services;

use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/** Exposes an immutable disabled context when no ownership runtime is loaded. */
final readonly class DisabledTenantContext implements TenantContext
{
    /** Return the disabled snapshot. */
    public function snapshot(): TenantContextSnapshot
    {
        return new TenantContextSnapshot(TenantContextMode::Disabled);
    }

    /** Deny tenant work without an admitted runtime context. */
    public function requireTenant(): TenantId
    {
        throw new TenantContextMissing('The tenant runtime is unavailable.');
    }
}
