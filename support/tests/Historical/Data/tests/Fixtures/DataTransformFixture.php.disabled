<?php

declare(strict_types=1);

namespace Nvl\Data\Tests\Fixtures;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Exercises DataTransform against nullable, optional, nested, and list values.
 */
final class DataTransformFixture extends Data
{
    use DataTransform;

    /**
     * Create the representative package transformation fixture.
     *
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly ?string $description,
        public readonly string|Optional $name,
        public readonly array $metadata = [],
        public readonly array $tags = [],
    ) {}
}
