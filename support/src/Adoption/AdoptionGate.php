<?php

declare(strict_types=1);

namespace Nvl\Support\Adoption;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Guards integrations that must not run before a package's adoption is activated.
 *
 * @api
 */
final readonly class AdoptionGate
{
    public function __construct(private DatabaseManager $database) {}

    /**
     * Whether an activated run exists for the package on its destination connection (default connection when null).
     */
    public function isActive(string $package, ?string $connection = null): bool
    {
        $store = new AdoptionStore($this->connection($connection));

        return $store->installed() && $store->hasActiveRun($package);
    }

    /**
     * Throw unless an activated run exists for the package.
     */
    public function assertActive(string $package, ?string $connection = null): void
    {
        if (! $this->isActive($package, $connection)) {
            throw new AdoptionException("Adoption for package [{$package}] has not been activated; run nvl:adopt {$package} activate after verification.");
        }
    }

    private function connection(?string $name): Connection
    {
        return $this->database->connection($name);
    }
}
