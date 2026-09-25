<?php

declare(strict_types=1);

namespace Nvl\Data\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Describes stable length-aware pagination metadata for public NVL payloads.
 */
#[TypeScript]
final class PaginationMeta extends Data
{
    /**
     * Create pagination metadata.
     */
    public function __construct(
        public readonly int $currentPage,
        public readonly int $lastPage,
        public readonly int $perPage,
        public readonly int $total,
    ) {}
}
