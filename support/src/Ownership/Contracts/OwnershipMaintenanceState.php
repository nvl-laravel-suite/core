<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Contracts;

/** Exposes only the denial state of a coordinated maintenance callback. @internal */
interface OwnershipMaintenanceState
{
    /** Report an active callback; this state grants no ordinary access. */
    public function active(): bool;
}
