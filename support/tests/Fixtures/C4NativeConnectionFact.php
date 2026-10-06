<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Nvl\Support\Contracts\DomainEvent;

/** Core callback payload deliberately has no leaf package dependency. */
final readonly class C4NativeConnectionFact implements DomainEvent
{
    public function __construct(public int $days, public bool $dryRun) {}

    public function schemaVersion(): int
    {
        return 1;
    }
}
