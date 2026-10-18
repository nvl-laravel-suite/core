<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Compatibility;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Services\PersistedTenantStorage;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;

/** Preserves legacy enforcement while denying declared ownership without its runtime. */
final readonly class LegacyOwnershipBoundary implements OwnershipBoundary
{
    /** Retain neutral declarations and the existing enforcing compatibility seam. */
    public function __construct(
        private Repository $configuration,
        private OwnershipResourceRegistry $resources,
        private TenantResourceRegistry $legacyResources,
        private TenantBoundary $legacy,
        private Container $container,
    ) {}

    /**
     * Preserve existing predicates and the legacy boundary when one is registered.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function query(Builder $query, string $resource): Builder
    {
        $this->assertRecord($query->getModel(), $resource);
        if (isset($this->legacyResources->all()[$resource])) {
            return $this->legacy->query($query, $resource);
        }
        $base = $query->getQuery();
        $table = $query->getModel()->getTable();
        if ($base->getConnection() !== $query->getModel()->getConnection()
            || $base->unions !== null || $base->from !== $table) {
            throw new OwnershipViolation('The ownership query differs from canonical storage.');
        }

        return $query;
    }

    /** Validate exact storage before any unowned compatibility access. */
    public function assertRecord(Model $record, string $resource): void
    {
        $definition = $this->resources->get($resource);
        if ($this->configuration->get('nvl-ownership.enabled') === true
            || in_array($definition->ownership->strategy, ['column', 'pivot'], true)) {
            throw new OwnershipViolation('Declared ownership requires the enforcing runtime.');
        }
        $canonical = new $definition->model;
        if ($record::class !== $definition->model || $record->getTable() !== $canonical->getTable()
            || $record->getConnection() !== $canonical->getConnection()) {
            throw new OwnershipViolation;
        }
        if (isset($this->legacyResources->all()[$resource])) {
            $this->legacy->assertRecord($record, $resource);

            return;
        }
        if ($definition->ownership->strategy !== 'unscoped'
            || $this->configuration->get('nvl-tenancy.enabled') === true) {
            throw new OwnershipViolation;
        }
        $this->container->make(PersistedTenantStorage::class)->assertUnadopted($record->getConnection(), $resource);
    }

    /** @return array<string, int|string|null> */
    public function attributes(string $resource): array
    {
        $definition = $this->resources->get($resource);
        $this->assertRecord(new $definition->model, $resource);

        return isset($this->legacyResources->all()[$resource]) ? $this->legacy->attributes($resource) : [];
    }

    /** Retain only identities admitted by the old or unowned boundary. */
    public function key(string $resource, string $identity): string
    {
        $definition = $this->resources->get($resource);
        $this->assertRecord(new $definition->model, $resource);

        return isset($this->legacyResources->all()[$resource]) ? $this->legacy->key($resource, $identity) : $identity;
    }
}
