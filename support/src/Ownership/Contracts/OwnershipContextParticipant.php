<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Contracts;

use Closure;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;

/**
 * Installs scope-local integration state and provides its restoration.
 *
 * @api
 */
interface OwnershipContextParticipant
{
    /**
     * Install the next context, restoring partial state before throwing on failure.
     *
     * @return Closure(): void
     */
    public function enter(OwnershipSnapshot $next): Closure;
}
