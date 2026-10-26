<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Contracts;

use Illuminate\Database\Connection;

/**
 * Prevents legacy access to persisted adopted storage on its actual connection.
 *
 * @api
 */
interface TenantInstallationState
{
    /** Assert this resource is compatible with the deployed ownership runtime. */
    public function assertUsable(string $resource): void;

    /** Assert the supplied actual connection has no applicable adoption marker. */
    public function assertUnadopted(Connection $connection, ?string $resource = null): void;

    /** Clear probes after an authorized schema change. */
    public function invalidate(): void;
}
