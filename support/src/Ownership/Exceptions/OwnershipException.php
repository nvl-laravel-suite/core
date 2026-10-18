<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Exceptions;

use Nvl\Support\Exceptions\BusinessException;

/**
 * Carries ownership failures through the existing package presentation contract.
 *
 * @api
 */
class OwnershipException extends BusinessException
{
    /** Return ownership as the presentation owner without requiring its runtime. */
    public function package(): string
    {
        return 'ownership';
    }
}
