<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * Admits only canonical unowned storage when no ownership runtime is active.
 *
 * @internal
 */
final readonly class UnownedOwnershipBoundary implements OwnershipBoundary
{
    /** Retain declarations and absence checks without loading an enforcing runtime. */
    public function __construct(
        private Repository $configuration,
        private OwnershipResourceRegistry $resources,
        private PersistedOwnershipStorage $storage,
    ) {}

    /**
     * Preserve caller predicates after checking exact unowned storage.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function query(Builder $query, string $resource): Builder
    {
        $this->assertRecord($query->getModel(), $resource);
        $base = $query->getQuery();
        if ($base->getConnection() !== $query->getModel()->getConnection()
            || $base->unions !== null || $base->from !== $query->getModel()->getTable()) {
            throw new OwnershipViolation('The ownership query differs from canonical storage.');
        }

        return $query;
    }

    /** Reject declared or previously adopted ownership before unowned access. */
    public function assertRecord(Model $record, string $resource): void
    {
        $definition = $this->resources->get($resource);
        if ($definition->ownership->strategy !== 'unscoped'
            || $this->configuration->get('nvl-ownership.enabled') === true) {
            throw new OwnershipViolation('Declared ownership requires its enforcing runtime.');
        }
        $canonical = new $definition->model;
        if ($record::class !== $definition->model
            || $record->getTable() !== $canonical->getTable()
            || $record->getConnection() !== $canonical->getConnection()) {
            throw new OwnershipViolation('Unowned access requires its exact canonical model and storage.');
        }
        $this->storage->assertUnadopted($record->getConnection(), $resource, $record->getTable());
    }

    /** @return array<string, int|string|null> */
    public function attributes(string $resource): array
    {
        $definition = $this->resources->get($resource);
        $this->assertRecord(new $definition->model, $resource);

        return [];
    }

    /** Retain the native identity only for admitted unowned storage. */
    public function key(string $resource, string $identity): string
    {
        $this->attributes($resource);

        return $identity;
    }
}
