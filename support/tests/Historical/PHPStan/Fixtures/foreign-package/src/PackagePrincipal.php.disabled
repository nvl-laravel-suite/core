<?php

declare(strict_types=1);

namespace ConsumerForeignFixtures;

use LogicException;
use Nvl\Auth\Models\User;

/** Represents an installed package subclass whose native principal base permits host extensions. */
class PackagePrincipal extends User
{
    /** Prove that analysis never executes installed fixture constructors. */
    public function __construct()
    {
        throw new LogicException('Consumer analysis must not execute foreign package constructors.');
    }
}
