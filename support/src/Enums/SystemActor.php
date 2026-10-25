<?php

declare(strict_types=1);

namespace Nvl\Support\Enums;

/**
 * Identifies background work without granting an implicit authorization bypass.
 *
 * @api
 */
enum SystemActor
{
    case Background;
}
