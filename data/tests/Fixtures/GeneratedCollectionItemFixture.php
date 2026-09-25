<?php

declare(strict_types=1);

namespace Nvl\Data\Tests\Fixtures;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Provides a generated item type for DataCollectionOf extraction tests.
 */
#[TypeScript]
final class GeneratedCollectionItemFixture extends Data
{
    /**
     * Create a generated collection item.
     */
    public function __construct(
        public readonly string $name,
    ) {}
}
