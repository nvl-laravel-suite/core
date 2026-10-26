<?php

declare(strict_types=1);

namespace Nvl\Data\Tests\Fixtures;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\Hidden;

/**
 * Ensures hidden Data contracts stay out of declarations and manifests.
 */
#[Hidden]
final class HiddenDataFixture extends Data
{
    /**
     * Create a hidden generated contract fixture.
     */
    public function __construct(
        public readonly string $secret,
    ) {}
}
