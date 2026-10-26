<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Services;

use Closure;
use Illuminate\Bus\PendingBatch;
use Illuminate\Container\Container;
use Nvl\Support\Tenancy\Contracts\TenantQueueContext;
use Nvl\Support\Tenancy\ValueObjects\TenantJobEnvelope;

/** Validates queue wire metadata before admitting runtime-free callbacks. */
final readonly class DisabledTenantQueueContext implements TenantQueueContext
{
    /** Retain stateless wire validation and the disabled execution boundary. */
    public function __construct(private DisabledTenantRunner $runner, private TenantQueuePayload $payload, private Container $container) {}

    /** Admit an ordinary native batch only on unadopted storage. */
    public function captureBatch(PendingBatch $batch): PendingBatch
    {
        $this->container->make(PersistedTenantStorage::class)->assertQueueUsable();

        return $batch;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(TenantJobEnvelope $envelope, Closure $operation): mixed
    {
        $this->payload->encode($envelope);

        return $this->runner->withoutTenant($envelope->context, $operation);
    }
}
