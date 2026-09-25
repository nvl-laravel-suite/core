<?php

declare(strict_types=1);

namespace Nvl\Data\Tests\Fixtures;

use Spatie\LaravelData\Data;

/**
 * Supplies a typed pagination item for Data package tests.
 */
final class PaginationItemData extends Data
{
    /**
     * Create a pagination fixture item.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {}
}
