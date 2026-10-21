<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;

/** Captures package work under the complete installation's current admission. @internal */
final readonly class OwnershipQueueCapture
{
    /** Resolve execution state at dispatch time rather than retaining it in package metadata. */
    public function __construct(private OwnershipContext $context, private OwnershipResourceRegistry $resources) {}

    /** Preserve unowned installations without downgrading unresolved owned work. */
    public function capture(): OwnershipJobEnvelope
    {
        if ($this->context->snapshot()->mode === OwnershipContextMode::Unresolved) {
            foreach ($this->resources->all() as $resource) {
                if ($resource->ownership->strategy !== 'unscoped') {
                    return OwnershipJobEnvelope::capture($this->context);
                }
            }

            return OwnershipJobEnvelope::fromSnapshot(new OwnershipSnapshot(OwnershipContextMode::Disabled));
        }

        return OwnershipJobEnvelope::capture($this->context);
    }
}
