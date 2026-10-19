<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Closure;
use Nvl\Support\Ownership\Contracts\OwnershipSystemRunner;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/** Denies owner system work when the optional enforcing runtime is absent. @internal */
final readonly class UnavailableOwnershipSystemRunner implements OwnershipSystemRunner
{
    /** @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runSystem(OwnerReference $owner, string $operation, Closure $callback): mixed
    {
        throw new OwnershipViolation('Owner system work requires the enforcing ownership runtime.');
    }
}
