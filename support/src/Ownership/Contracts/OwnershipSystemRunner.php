<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Contracts;

use Closure;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Enters a named, freshly authorized owner operation without coupling consumers to the runtime.
 *
 * @api
 */
interface OwnershipSystemRunner
{
    /**
     * Execute one allowlisted operation after fresh owner and system policy checks.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runSystem(OwnerReference $owner, string $operation, Closure $callback): mixed;
}
