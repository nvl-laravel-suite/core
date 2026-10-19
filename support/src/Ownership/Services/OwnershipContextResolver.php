<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Container\Container;
use Nvl\Support\Ownership\Contracts\OwnershipContext;

/** Resolves execution-time scope for retained framework services without retaining a request's context. @internal */
final readonly class OwnershipContextResolver
{
    /** Retain only the application, never a previous actor, owner, snapshot or scoped adapter. */
    public function __construct(private Container $container) {}

    /** Resolve the current lifecycle; existing captured context objects keep their original semantics. */
    public function current(): OwnershipContext
    {
        return $this->container->make(OwnershipContext::class);
    }
}
