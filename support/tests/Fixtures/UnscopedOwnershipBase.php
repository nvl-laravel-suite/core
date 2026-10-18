<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;

/** An advertised package root before a host opts into owned replacement. */
class UnscopedOwnershipBase extends Model implements Ownable
{
    use HasOwnership;

    protected $table = 'ownership_claimed_models';

    protected $guarded = [];

    public $timestamps = false;
}
