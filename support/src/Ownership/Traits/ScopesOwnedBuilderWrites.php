<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Traits;

use Nvl\Support\Ownership\Services\OwnershipScope;

/**
 * Keeps Laravel's scope-free builder writes inside the canonical ownership boundary.
 *
 * Eloquent runs these terminals on the base query without applying global scopes.
 * Use this trait on every custom Eloquent builder of a model that declares ownership;
 * models without a custom builder receive it automatically. Truncation stays native.
 *
 * @api
 */
trait ScopesOwnedBuilderWrites
{
    /**
     * Permanently delete only the rows admitted by the current boundary.
     *
     * @return mixed
     */
    public function forceDelete()
    {
        return OwnershipScope::bounded($this)->delete();
    }

    /**
     * Update or insert without matching a row outside the current boundary.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|callable  $values
     */
    public function updateOrInsert(array $attributes, array|callable $values = []): bool
    {
        return OwnershipScope::bounded($this)->updateOrInsert($attributes, $values);
    }

    /**
     * Run a joined update only against rows admitted by the current boundary.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateFrom(array $values): int
    {
        return OwnershipScope::bounded($this)->updateFrom($values);
    }
}
