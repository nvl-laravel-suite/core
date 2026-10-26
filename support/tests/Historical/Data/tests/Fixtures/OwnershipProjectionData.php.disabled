<?php

declare(strict_types=1);

namespace Nvl\Data\Tests\Fixtures;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;

/**
 * Represents declared mutation input used to verify persistence projection ownership.
 */
final class OwnershipProjectionData extends Data
{
    use DataTransform;

    /**
     * Create the ownership projection fixture.
     */
    public function __construct(
        public readonly string $name,
    ) {}
}
