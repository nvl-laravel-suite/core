<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Provides canonical ownership enforcement independently from owner lifecycle. */
interface OwnershipBoundary
{
    /**
     * Restrict a canonical query to its admitted ownership boundary.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function query(Builder $query, string $resource): Builder;

    /** Verify canonical persisted ownership of the exact configured record. */
    public function assertRecord(Model $record, string $resource): void;

    /** @return array{owner_scope_key?: string, owner_reference?: string} */
    public function attributes(string $resource): array;

    /** Derive a resource and ownership-specific operation identity. */
    public function key(string $resource, string $identity): string;
}
