<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Contracts;

use Closure;
use Illuminate\Bus\PendingBatch;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/**
 * Re-admits captured work and preserves native batch behavior.
 *
 * @api
 */
interface OwnershipQueueContext
{
    /** Capture explicitly global work with no actor, owner or central privileges. */
    public function captureUnowned(): OwnershipJobEnvelope;

    /** Capture one boundary for all jobs and persisted native callbacks. */
    public function captureBatch(PendingBatch $batch): PendingBatch;

    /** @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(OwnershipJobEnvelope $envelope, Closure $operation): mixed;
}
