<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;

/** Prevents package-specific readers from treating an absent runtime as active storage. @internal */
final readonly class UnavailableOwnershipReadiness implements OwnershipReadiness
{
    /** Runtime removal cannot authorize a privileged package projection. */
    public function assertActive(string $resource): void
    {
        throw new OwnershipSchemaNotReady('Ownership storage requires its active enforcing runtime.');
    }
}
