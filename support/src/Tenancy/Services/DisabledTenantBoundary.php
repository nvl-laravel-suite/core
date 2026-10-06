<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Services;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

/** Preserves validated legacy access while refusing adopted or mismatched storage. */
final readonly class DisabledTenantBoundary implements TenantBoundary
{
    /** Retain immutable metadata and a fresh actual-connection safety probe. */
    public function __construct(private TenantResourceRegistry $resources, private Container $container) {}

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function query(Builder $query, string $resource): Builder
    {
        $this->assertRecord($query->getModel(), $resource);
        $base = $query->getQuery();
        $table = $query->getModel()->getTable();
        $from = $base->from;
        $canonical = $from === $table
            || (is_string($from) && preg_match('/^'.preg_quote($table, '/').'\s+as\s+laravel_reserved_\d+$/i', $from) === 1);
        if ($base->getConnection() !== $query->getModel()->getConnection()
            || $base->unions !== null || ! $canonical) {
            throw new TenantBoundaryViolation('The query differs from its registered canonical storage.');
        }

        return $query;
    }

    /** Validate exact concrete identity, table and connection before legacy access. */
    public function assertRecord(Model $record, string $resource): void
    {
        $definition = $this->resources->get($resource);
        $canonical = new $definition->model;
        if ($record::class !== $definition->model || $record->getTable() !== $canonical->getTable()
            || $record->getConnection() !== $canonical->getConnection()) {
            throw new TenantBoundaryViolation('The record differs from its registered canonical storage.');
        }
        $this->container->make(PersistedTenantStorage::class)->assertUsable($resource);
    }

    /** @return array{tenant_id?: string|null, ownership_key?: string} */
    public function attributes(string $resource): array
    {
        $this->container->make(PersistedTenantStorage::class)->assertUsable($resource);

        return [];
    }

    /** Preserve an admitted legacy identity. */
    public function key(string $resource, string $identity): string
    {
        $this->container->make(PersistedTenantStorage::class)->assertUsable($resource);

        return $identity;
    }
}
