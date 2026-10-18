<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Nvl\Support\Ownership\Contracts\OwnershipMaintenanceState;

/** Keeps optional runtime maintenance inert for ordinary installations. @internal */
final readonly class InactiveOwnershipMaintenance implements OwnershipMaintenanceState
{
    /** No runtime means no coordinated maintenance callback can be active. */
    public function active(): bool
    {
        return false;
    }
}
