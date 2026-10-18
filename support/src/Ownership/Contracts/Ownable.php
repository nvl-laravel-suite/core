<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Contracts;

use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Declares how canonical model ownership is resolved without selecting an actor. */
interface Ownable
{
    /** Return immutable model-owned persistence metadata. */
    public static function ownershipDefinition(): OwnershipDefinition;
}
