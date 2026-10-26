<?php

declare(strict_types=1);

namespace Nvl\Data\Tests\Fixtures;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Exercises explicit public TypeScript name and namespace metadata.
 */
#[TypeScript(
    name: 'GeneratedPublicContract',
    location: ['Nvl', 'Data', 'Contracts'],
)]
final class GeneratedRenamedTypeFixture extends Data
{
    /**
     * Create a renamed generated contract fixture.
     */
    public function __construct(
        public readonly string $identifier,
    ) {}
}
