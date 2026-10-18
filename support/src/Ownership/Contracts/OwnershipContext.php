<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Contracts;

use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;

/**
 * Exposes scoped ownership facts without allowing consumers to enter a context.
 *
 * @api
 */
interface OwnershipContext
{
    /** Return the current immutable execution snapshot. */
    public function snapshot(): OwnershipSnapshot;

    /** Require one explicitly admitted owner. */
    public function requireOwner(): OwnerReference;
}
