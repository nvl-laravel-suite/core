<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Unrelated host model cannot replace another package's model. */
final class OwnershipUnrelatedModel extends Model {}
