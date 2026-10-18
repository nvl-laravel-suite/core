<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Exceptions;

use Nvl\Support\Ownership\Enums\OwnershipResponseCode;

/**
 * Denies an incomplete or changed durable installation.
 *
 * @api
 */
final class OwnershipSchemaNotReady extends OwnershipException
{
    /** Return a stable installation failure without private storage details. */
    public function __construct(string $message = 'The ownership installation is not ready.')
    {
        parent::__construct($message, responseCode: OwnershipResponseCode::SchemaNotReady, suggestedStatus: 503);
    }
}
