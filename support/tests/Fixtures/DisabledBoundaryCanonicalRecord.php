<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Supplies canonical package storage shared by an explicit resource's model lineage. */
class DisabledBoundaryCanonicalRecord extends Model
{
    protected $table = 'disabled_boundary_records';
}
