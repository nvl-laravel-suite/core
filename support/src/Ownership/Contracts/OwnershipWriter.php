<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Model;

/** Lets standalone packages create canonical models without depending on an optional runtime. @internal */
interface OwnershipWriter
{
    /** @template T of Model
     * @param  class-string<T>  $modelClass
     * @param  Closure(array<string, int|string|null>): T  $persist
     * @return T
     */
    public function create(string $modelClass, Closure $persist): Model;
}
