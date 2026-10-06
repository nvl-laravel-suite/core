<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Exceptions;

use Illuminate\Http\Response;
use Nvl\Support\Tenancy\Enums\TenancyResponseCode;

/**
 * Reports a tenant identifier absent from the configured directory.
 *
 * @api
 */
final class TenantNotFound extends TenancyException
{
    /**
     * Create an unknown-tenant failure.
     */
    public function __construct(string $message = 'Tenant was not found.')
    {
        parent::__construct(
            message: $message,
            responseCode: TenancyResponseCode::TenantNotFound,
            suggestedStatus: Response::HTTP_NOT_FOUND,
        );
    }
}
