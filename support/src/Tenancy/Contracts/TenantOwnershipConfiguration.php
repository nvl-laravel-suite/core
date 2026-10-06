<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Tenancy\ValueObjects\TenantResourceDefinition;

/**
 * Exposes structural ownership metadata without requiring the tenant runtime.
 *
 * @api
 */
interface TenantOwnershipConfiguration
{
    /** Validate configured structural ownership. */
    public function validate(): void;

    /** Return the effective code-owned resource mode. */
    public function mode(TenantResourceDefinition $resource): string;

    /** @return array<string, class-string<Model>> */
    public function parentTypes(string $resource): array;
}
