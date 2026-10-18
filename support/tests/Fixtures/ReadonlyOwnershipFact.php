<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Container\Container;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/** Immutable domain fact initializes scalar producer admission directly in its constructor. */
final readonly class ReadonlyOwnershipFact implements OwnershipQueuedJob
{
    private OwnershipJobEnvelope $envelope;

    /** Capture without retaining an owner model or a scoped context service. */
    public function __construct(public string $label)
    {
        $this->envelope = OwnershipJobEnvelope::capture(Container::getInstance()->make(OwnershipContext::class));
    }

    /** Return the constructor-initialized immutable producer metadata. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->envelope;
    }
}
