<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Compatibility;

use Nvl\Support\Exceptions\BusinessException;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

/** Preserves existing exception handlers only while the legacy authority is enforcing access. @internal */
final readonly class OwnershipFailures
{
    public function __construct(private LegacyQueueAuthority $legacy) {}

    /** Keep the selected authority's neutral presentation contract without changing either hierarchy. */
    public function violation(string $message): BusinessException
    {
        return $this->legacy->active() ? new TenantBoundaryViolation($message) : new OwnershipViolation($message);
    }
}
