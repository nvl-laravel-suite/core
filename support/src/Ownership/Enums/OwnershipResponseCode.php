<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Enums;

use Nvl\Support\Contracts\ResponseCode;

/**
 * Defines stable transport-neutral codes for ownership failures.
 *
 * @api
 */
enum OwnershipResponseCode: string implements ResponseCode
{
    case BoundaryViolation = 'ownership_boundary_violation';
    case ConfigurationInvalid = 'ownership_configuration_invalid';
}
