<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Contracts;

use Closure;
use Nvl\Support\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/** Executes work inside an admitted immutable tenant scope. */
interface TenantRunner
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(TenantId $tenant, Closure $operation): mixed;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function platform(PlatformOperation $operation, Closure $callback): mixed;

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function withoutTenant(TenantContextSnapshot $snapshot, Closure $operation): mixed;
}
