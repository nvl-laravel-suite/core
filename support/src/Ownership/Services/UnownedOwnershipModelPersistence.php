<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\OwnershipModelGuard;
use Nvl\Support\Ownership\Contracts\OwnershipModelPersistence;

/** Preserves ordinary persistence after the absent-runtime model guard has admitted it. */
final readonly class UnownedOwnershipModelPersistence implements OwnershipModelPersistence
{
    /** Retain the current model guard without legacy runtime dependencies. */
    public function __construct(private OwnershipModelGuard $guard) {}

    /**
     * Save only an admitted unowned model.
     *
     * @param  Closure(): bool  $persist
     */
    public function save(Model $model, Closure $persist): bool
    {
        $this->guard->saving($model);

        return $persist();
    }

    /**
     * Delete only an admitted unowned model.
     *
     * @param  Closure(): (bool|null)  $persist
     */
    public function delete(Model $model, Closure $persist): ?bool
    {
        $this->guard->deleting($model);

        return $persist();
    }
}
