<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Traits;

use Illuminate\Container\Container;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/**
 * Captures once in a producer constructor and derives native uniqueness and overlap identities.
 *
 * @api
 */
trait CapturesOwnership
{
    private OwnershipJobEnvelope $capturedOwnership;

    /** Freeze producer admission before Laravel acquires locks or schedules callbacks. */
    protected function captureOwnership(): void
    {
        if (isset($this->capturedOwnership)) {
            throw new OwnershipViolation('A scheduled command cannot replace its captured ownership.');
        }
        $this->capturedOwnership = OwnershipJobEnvelope::capture(Container::getInstance()->make(OwnershipContext::class));
    }

    /** Return explicit constructor capture without adopting the current serialization scope. */
    final public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->capturedOwnership ?? throw new OwnershipViolation('Capture ownership before scheduling the command.');
    }

    /** Supply Laravel's native ShouldBeUnique key before acquiring its lock. */
    final public function uniqueId(): string
    {
        return $this->ownershipLockKey($this->ownershipUniqueIdentity());
    }

    /** Override only the business identity; the captured partition is added centrally. */
    protected function ownershipUniqueIdentity(): string
    {
        return static::class;
    }

    /** Derive a native overlap or unique lock key only after current admission succeeds. */
    final public function ownershipLockKey(string $identity): string
    {
        $envelope = $this->ownershipJobEnvelope();

        return Container::getInstance()->make(OwnershipQueueContext::class)->run($envelope, static fn (): string => $envelope->key($identity));
    }
}
