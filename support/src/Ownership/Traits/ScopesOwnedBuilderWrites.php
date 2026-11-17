<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Traits;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipScope;

/**
 * Keeps Laravel's builder writes inside the canonical ownership boundary.
 *
 * Every bulk write is admitted first, so a public site writes only its writable resources.
 * Eloquent runs some terminals on the base query without applying global scopes; those are
 * re-scoped here. Inserted rows must carry exactly the admitted ownership fields, and an upsert
 * must resolve its conflicts inside the owner partition (`owner_scope_key` in `$uniqueBy`).
 * Use this trait on every custom Eloquent builder of a model that declares ownership; models
 * without a custom builder receive it automatically. A builder whose caller removed the
 * ownership scope, and truncation, stay native.
 *
 * @api
 */
trait ScopesOwnedBuilderWrites
{
    /**
     * Update only rows admitted by the current boundary.
     */
    public function update(array $values): int
    {
        $this->admitOwnedWrite();

        return parent::update($values);
    }

    /**
     * Delete only rows admitted by the current boundary.
     *
     * @return mixed
     */
    public function delete()
    {
        $this->admitOwnedWrite();

        return parent::delete();
    }

    /**
     * Permanently delete only the rows admitted by the current boundary.
     *
     * @return mixed
     */
    public function forceDelete()
    {
        $this->admitOwnedWrite();

        return OwnershipScope::bounded($this)->delete();
    }

    /**
     * Update the timestamp of admitted rows only.
     *
     * @param  array<array-key, string>|string|null  $column
     */
    public function touch($column = null): int|false
    {
        $this->admitOwnedWrite();

        return parent::touch($column);
    }

    /**
     * Increment a column of admitted rows only.
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        $this->admitOwnedWrite();

        return parent::increment($column, $amount, $extra);
    }

    /**
     * Decrement a column of admitted rows only.
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $this->admitOwnedWrite();

        return parent::decrement($column, $amount, $extra);
    }

    /**
     * Increment columns of admitted rows only.
     *
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        $this->admitOwnedWrite();

        return parent::incrementEach($columns, $extra);
    }

    /**
     * Decrement columns of admitted rows only.
     *
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        $this->admitOwnedWrite();

        return parent::decrementEach($columns, $extra);
    }

    /**
     * Insert rows that carry exactly the admitted ownership fields.
     *
     * @param  array<array-key, mixed>  $values
     */
    public function insert(array $values): bool
    {
        OwnershipScope::assertOwnedRows($values, $this->admitOwnedWrite());

        return $this->toBase()->insert($values);
    }

    /**
     * Insert rows that carry exactly the admitted ownership fields, ignoring conflicts.
     *
     * @param  array<array-key, mixed>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        OwnershipScope::assertOwnedRows($values, $this->admitOwnedWrite());

        return $this->toBase()->insertOrIgnore($values);
    }

    /**
     * Insert one row that carries exactly the admitted ownership fields and return its key.
     *
     * @param  array<string, mixed>  $values
     */
    public function insertGetId(array $values, ?string $sequence = null): int|string
    {
        OwnershipScope::assertOwnedRows($values, $this->admitOwnedWrite());

        return $this->toBase()->insertGetId($values, $sequence);
    }

    /**
     * Insert rows that carry exactly the admitted ownership fields, ignoring conflicts, and return them.
     *
     * @param  array<array-key, mixed>  $values
     * @param  non-empty-array<non-empty-string>  $returning
     * @param  non-empty-array<non-empty-string>|non-empty-string|null  $uniqueBy
     * @return mixed
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null)
    {
        OwnershipScope::assertOwnedRows($values, $this->admitOwnedWrite());

        return $this->toBase()->insertOrIgnoreReturning($values, $returning, $uniqueBy);
    }

    /**
     * Refuse an owned insert from a source query, whose rows cannot be verified before writing.
     *
     * @param  array<array-key, string>  $columns
     * @param  Closure|Builder<*>|QueryBuilder|string  $query
     */
    public function insertUsing(array $columns, $query): int
    {
        $this->refuseUnverifiableInsert();

        return $this->toBase()->insertUsing($columns, $query);
    }

    /**
     * Refuse an owned insert from a source query, whose rows cannot be verified before writing.
     *
     * @param  array<array-key, string>  $columns
     * @param  Closure|Builder<*>|QueryBuilder|string  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        $this->refuseUnverifiableInsert();

        return $this->toBase()->insertOrIgnoreUsing($columns, $query);
    }

    /**
     * Insert or update only inside the admitted owner partition.
     *
     * An owned upsert must name `owner_scope_key` in `$uniqueBy`, so a conflicting row of another
     * owner can never be updated; any other conflict fails as a unique violation.
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, string>|string  $uniqueBy
     * @param  array<array-key, mixed>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        $fields = $this->admitOwnedWrite();
        if ($fields !== null && $fields !== [] && ! in_array('owner_scope_key', (array) $uniqueBy, true)) {
            throw new OwnershipViolation('An owned upsert must resolve conflicts by owner_scope_key.');
        }
        OwnershipScope::assertOwnedRows($values, $fields);

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * Update or insert without matching a row outside the current boundary.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|Closure(bool): array<string, mixed>  $values
     */
    public function updateOrInsert(array $attributes, array|callable $values = []): bool
    {
        $fields = $this->admitOwnedWrite();

        return OwnershipScope::bounded($this)->updateOrInsert($attributes, static function (bool $exists) use ($attributes, $values, $fields): array {
            $values = $values instanceof Closure ? $values($exists) : $values;
            if (! $exists) {
                OwnershipScope::assertOwnedRows(array_merge($attributes, $values), $fields);
            }

            return $values;
        });
    }

    /**
     * Run a joined update only against rows admitted by the current boundary.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateFrom(array $values): int
    {
        $this->admitOwnedWrite();

        return OwnershipScope::bounded($this)->updateFrom($values);
    }

    /**
     * Admit a write through a builder that still carries the ownership scope.
     *
     * @return array<string, int|string|null>|null
     */
    private function admitOwnedWrite(): ?array
    {
        return isset($this->scopes[OwnershipScope::class]) ? OwnershipScope::admitWrite($this->getModel()) : null;
    }

    private function refuseUnverifiableInsert(): void
    {
        $fields = $this->admitOwnedWrite();
        if ($fields !== null && $fields !== []) {
            throw new OwnershipViolation('Owned rows cannot be inserted from a source query; insert verified rows instead.');
        }
    }
}
