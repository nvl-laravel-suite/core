<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Tenancy\Contracts\TenantInstallationState;
use Nvl\Support\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Support\Tenancy\Exceptions\TenantSchemaNotReady;
use WeakMap;

/** Reads adoption markers on actual resource storage without optional runtime dependencies. */
final class PersistedTenantStorage implements TenantInstallationState
{
    /** @var WeakMap<Connection, array<string, true>> */
    private WeakMap $markers;

    /** Retain application configuration and immutable resource metadata. */
    public function __construct(private readonly Repository $configuration, private readonly TenantResourceRegistry $resources)
    {
        $this->markers = new WeakMap;
    }

    /** Reject configured tenant enforcement when its implementation is unavailable. */
    public function assertRuntimeDisabled(): void
    {
        if ($this->configuration->get('tenancy.enabled') === true) {
            throw new TenantConfigurationInvalid('Tenancy is enabled but its enforcing runtime is not loaded.');
        }
    }

    /** Check the registered model's actual connection before admitting legacy access. */
    public function assertUsable(string $resource): void
    {
        $definition = $this->resources->get($resource);
        $this->assertRuntimeDisabled();
        $this->assertUnadopted((new $definition->model)->getConnection(), $resource);
    }

    /** Reject every persisted adoption state, including preparation and partial adoption. */
    public function assertUnadopted(Connection $connection, ?string $resource = null): void
    {
        $this->assertRuntimeDisabled();
        if (! isset($this->markers[$connection])) {
            $table = PackageStorage::resolveTable('tenancy', 'installation_state');
            $markers = [];
            if ($connection->getSchemaBuilder()->hasTable($table)) {
                foreach ($connection->table($table)->pluck('resource') as $key) {
                    if (! is_string($key)) {
                        throw new TenantSchemaNotReady('The installation marker has an invalid resource key.');
                    }
                    $markers[$key] = true;
                }
            }
            $this->markers[$connection] = $markers;
        }
        $markers = $this->markers[$connection];
        if (($resource === null && $markers !== []) || ($resource !== null && isset($markers[$resource]))) {
            throw new TenantSchemaNotReady('Persisted tenant ownership blocks legacy storage access without the tenant runtime.');
        }
    }

    /** Clear generation-local probes after authorized schema changes. */
    public function invalidate(): void
    {
        $this->markers = new WeakMap;
    }

    /** Admit an ordinary queue callback only after checking all registered actual connections. */
    public function assertQueueUsable(): void
    {
        $this->assertRuntimeDisabled();
        foreach ($this->resources->all() as $resource) {
            $this->assertUsable($resource->key);
        }
    }
}
