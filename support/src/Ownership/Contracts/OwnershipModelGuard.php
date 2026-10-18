<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Contracts;

use Illuminate\Database\Eloquent\Model;

/** Neutral persistence guard resolved at the Eloquent framework boundary. @internal */
interface OwnershipModelGuard
{
    /** Validate creation or a locked canonical update. */
    public function saving(Model $model): void;

    /** Validate a locked canonical deletion. */
    public function deleting(Model $model): void;
}
