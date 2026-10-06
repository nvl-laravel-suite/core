<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Provides validated ownership access with or without the tenant runtime. */
interface TenantBoundary
{
    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function query(Builder $query, string $resource): Builder;

    /** Verify the registered concrete record and its ownership. */
    public function assertRecord(Model $record, string $resource): void;

    /** @return array{tenant_id?: string|null, ownership_key?: string} */
    public function attributes(string $resource): array;

    /** Derive the admitted resource identity. */
    public function key(string $resource, string $identity): string;
}
