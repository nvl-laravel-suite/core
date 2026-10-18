<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Contracts;

use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/** Carries explicit producer capture on native jobs, events, mail, and notifications. @api */
interface OwnershipQueuedJob
{
    /** Return immutable capture made before scheduling. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope;
}
