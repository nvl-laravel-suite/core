<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;

/** Exercises polymorphic pivot deletion without changing Laravel's native affected-row result. */
class MorphPivotOwnershipFixture extends MorphPivot implements Ownable
{
    use HasOwnership;

    public $timestamps = false;
}
