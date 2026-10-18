<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Separate model family cannot take another replacement's registration. */
class OwnershipOtherIdentityBase extends Model {}
