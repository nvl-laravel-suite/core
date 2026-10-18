<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Contracts;

/** Proves active storage only; this capability never admits a principal or grants record access. @internal */
interface OwnershipReadiness
{
    /** Revalidate the live graph, source generation and physical constraints. */
    public function assertActive(string $resource): void;
}
