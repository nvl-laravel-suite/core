<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Enums;

/**
 * Declares the code-owned source of a resource's ownership.
 *
 * @api
 */
enum TenantResourceKind: string
{
    case Root = 'root';
    case Inherited = 'inherited';
    case Platform = 'platform';
}
