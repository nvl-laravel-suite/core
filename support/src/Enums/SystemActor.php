<?php

declare(strict_types=1);

namespace Nvl\Support\Enums;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Gate;

/**
 * Identifies background work without granting an implicit authorization bypass.
 *
 * @api
 */
enum SystemActor implements Authorizable
{
    case Background;

    /**
     * Evaluate host abilities for this explicit background actor without authenticating a user.
     *
     * @param  \UnitEnum|iterable<array-key, \UnitEnum|string>|string  $abilities
     */
    public function can(mixed $abilities, mixed $arguments = []): bool
    {
        return Gate::forUser($this)->check($abilities, $arguments);
    }
}
