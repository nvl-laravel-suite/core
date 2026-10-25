<?php

declare(strict_types=1);

namespace Nvl\Support\Enums;

/**
 * Identifies an anonymous caller only on an explicitly authorized public operation.
 *
 * @api
 */
enum PublicActor
{
    case Anonymous;
}
