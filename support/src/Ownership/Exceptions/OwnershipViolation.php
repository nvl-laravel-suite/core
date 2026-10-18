<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Exceptions;

use Nvl\Support\Ownership\Enums\OwnershipResponseCode;
use Throwable;

/**
 * Denies access outside the canonical admitted ownership boundary.
 *
 * @api
 */
final class OwnershipViolation extends OwnershipException
{
    /** Return a neutral failure that does not reveal foreign resource details. */
    public function __construct(string $message = 'The resource is outside the admitted ownership context.', ?Throwable $previous = null)
    {
        parent::__construct($message, responseCode: OwnershipResponseCode::BoundaryViolation, suggestedStatus: 404, previous: $previous);
    }
}
