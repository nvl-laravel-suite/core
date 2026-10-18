<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;

/** Exercises inert ownership metadata in both runtime and static-analysis contracts. */
final class OwnershipCapableModel extends Model implements Ownable
{
    use HasOwnership;
}
