<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Exceptions;

use Nvl\Support\Ownership\Enums\OwnershipResponseCode;

/**
 * Reports invalid immutable ownership or model replacement configuration.
 *
 * @api
 */
final class OwnershipConfigurationInvalid extends OwnershipException
{
    /** Report invalid deployment metadata separately from an ownership denial. */
    public function __construct(string $message = 'Ownership configuration is invalid.')
    {
        parent::__construct($message, responseCode: OwnershipResponseCode::ConfigurationInvalid, suggestedStatus: 500);
    }
}
