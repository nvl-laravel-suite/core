<?php

declare(strict_types=1);

namespace Nvl\Data\Tests\Fixtures;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Exercises explicit public TypeScript metadata on a backed enum.
 */
#[TypeScript(
    name: 'PublicationState',
    location: ['Nvl', 'Data', 'Contracts'],
)]
enum GeneratedRenamedStatusFixture: string
{
    case Draft = 'draft';
    case Published = 'published';
}
