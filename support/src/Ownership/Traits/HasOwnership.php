<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Traits;

use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/**
 * Supplies an inert ownership declaration for supported model extensions.
 *
 * @api
 */
trait HasOwnership
{
    /** Keep untouched models unscoped until explicitly declared and adopted. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::unscoped();
    }
}
