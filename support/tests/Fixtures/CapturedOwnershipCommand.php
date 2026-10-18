<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Traits\CapturesOwnership;

/** Exercises the public constructor-capture trait in its actual consumer context. */
final class CapturedOwnershipCommand implements OwnershipQueuedJob
{
    use CapturesOwnership;

    /** Capture a native business identity before scheduling. */
    public function __construct(public readonly string $identity)
    {
        $this->captureOwnership();
    }

    /** Demonstrate that a consumer cannot accidentally recapture after deferral. */
    public function recapture(): void
    {
        $this->captureOwnership();
    }

    /** Preserve the ordinary unowned identity when the runtime is absent. */
    protected function ownershipUniqueIdentity(): string
    {
        return $this->identity;
    }
}
