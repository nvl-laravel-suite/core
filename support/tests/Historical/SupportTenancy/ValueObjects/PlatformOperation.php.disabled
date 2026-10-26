<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\ValueObjects;

/**
 * Identifies an explicit actor and purpose for privileged platform work.
 *
 * @api
 */
final readonly class PlatformOperation
{
    /**
     * Create a privileged platform operation reference.
     */
    public function __construct(
        public string $purpose,
        public string $actorType,
        public string $actorId,
    ) {}
}
