<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** The exact configured subclass exclusively owns this package storage. */
final class OwnedOwnershipReplacement extends UnscopedOwnershipBase
{
    /** Declare a concrete host primary owner. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(PackageOwner::class, 'company_id');
    }
}
