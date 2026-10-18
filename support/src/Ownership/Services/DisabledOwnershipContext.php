<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;

/** Keeps unowned applications inert when the optional runtime is not installed. */
final readonly class DisabledOwnershipContext implements OwnershipContext
{
    /** Report absence of an enforcing runtime without probing storage. */
    public function snapshot(): OwnershipSnapshot
    {
        return new OwnershipSnapshot(OwnershipContextMode::Disabled);
    }

    /** Never infer an owner from authentication or an omitted runtime. */
    public function requireOwner(): OwnerReference
    {
        throw new OwnershipViolation;
    }
}
