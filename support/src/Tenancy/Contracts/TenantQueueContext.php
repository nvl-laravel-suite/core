<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Contracts;

use Closure;
use Illuminate\Bus\PendingBatch;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;

/**
 * Admits captured queued ownership before executing application callbacks.
 *
 * @api
 */
interface TenantQueueContext
{
    /** Capture the current ownership for a native batch. */
    public function captureBatch(PendingBatch $batch): PendingBatch;

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(TenantJobEnvelope $envelope, Closure $operation): mixed;
}
