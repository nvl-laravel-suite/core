<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Contracts;

use Nvl\Support\Tenancy\Exceptions\TenantNotFound;
use Nvl\Support\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Resolves immutable tenant lifecycle descriptors by canonical identifier.
 */
interface TenantDirectory
{
    /**
     * Find one tenant directory entry.
     *
     * @throws TenantNotFound When the identifier is unknown
     */
    public function find(TenantId $tenant): TenantDescriptor;
}
