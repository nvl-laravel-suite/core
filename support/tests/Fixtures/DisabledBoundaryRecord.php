<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Supplies one concrete resource for the runtime-free boundary. */
final class DisabledBoundaryRecord extends Model
{
    protected $table = 'disabled_boundary_records';
}
