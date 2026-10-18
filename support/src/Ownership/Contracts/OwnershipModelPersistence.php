<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Model;

/** Wraps native persistence without embedding authorization or workflow in a model trait. @internal */
interface OwnershipModelPersistence
{
    /** @param Closure(): bool $persist */
    public function save(Model $model, Closure $persist): bool;

    /** @param Closure(): (bool|null) $persist */
    public function delete(Model $model, Closure $persist): ?bool;
}
