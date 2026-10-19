<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Closure;
use Illuminate\Bus\PendingBatch;
use Illuminate\Container\Container;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;
use Nvl\Support\Tenancy\Services\PersistedTenantStorage;

/** Preserves native unowned scheduling while denying captured authority without its runtime. @internal */
final readonly class DisabledOwnershipQueueContext implements OwnershipQueueContext
{
    /** Resolve fresh compatibility storage from the current application. */
    public function __construct(private Container $container) {}

    /** Capture ordinary unowned work only while no enforcing ownership declaration exists. */
    public function captureUnowned(): OwnershipJobEnvelope
    {
        $envelope = OwnershipJobEnvelope::fromSnapshot(new OwnershipSnapshot(OwnershipContextMode::Disabled));
        $this->run($envelope, static fn (): null => null);

        return $envelope;
    }

    /** Keep ordinary native batches available on unadopted storage. */
    public function captureBatch(PendingBatch $batch): PendingBatch
    {
        $this->container->make(PersistedTenantStorage::class)->assertQueueUsable();

        return $batch;
    }

    /** @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(OwnershipJobEnvelope $envelope, Closure $operation): mixed
    {
        if (OwnershipJobEnvelope::fromArray($envelope->toArray())->context()->mode !== OwnershipContextMode::Disabled) {
            throw new OwnershipViolation('Captured ownership requires its enforcing runtime.');
        }
        foreach ($this->container->make(OwnershipResourceRegistry::class)->all() as $resource) {
            if ($resource->ownership->strategy !== 'unscoped') {
                throw new OwnershipViolation('Declared ownership requires its enforcing runtime.');
            }
        }
        $this->container->make(PersistedTenantStorage::class)->assertQueueUsable();

        return $operation();
    }
}
