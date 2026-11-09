<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;

/** Exercises native keyed and composite pivot persistence through Core's ownership boundary. */
class PivotOwnershipFixture extends Pivot implements Ownable
{
    use HasOwnership;

    public $timestamps = false;
}
