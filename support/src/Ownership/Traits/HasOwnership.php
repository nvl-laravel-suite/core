<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Traits;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Support\Ownership\Contracts\OwnershipModelPersistence;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipRestorationQuery;
use Nvl\Support\Ownership\Services\OwnershipScope;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/**
 * Declares ownership and delegates native Eloquent boundaries to neutral services.
 *
 * @api
 */
trait HasOwnership
{
    /** Register framework metadata without loading current admission. @internal */
    public static function bootHasOwnership(): void
    {
        static::addGlobalScope(new OwnershipScope);
    }

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        return Container::getInstance()->make(OwnershipModelPersistence::class)->save($this, fn (): bool => parent::save($options));
    }

    /** Fence native instance counters before observers, including quiet increments/decrements.
     * Laravel leaves this protected seam untyped; validate its arguments before native delegation.
     *
     * @param  array<string,mixed>  $extra
     */
    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): int|bool
    {
        $this->validateOwnershipCounterArguments($column, $amount, $extra, $method);
        if (! $this->exists) {
            return parent::incrementOrDecrement($column, $amount, $extra, $method);
        }
        $affected = false;
        Container::getInstance()->make(OwnershipModelPersistence::class)->save($this, function () use ($column, $amount, $extra, $method, &$affected): bool {
            $affected = parent::incrementOrDecrement($column, $amount, $extra, $method);

            return true;
        });

        return $affected;
    }

    /** Validate Laravel's raw counter invocation before it can reach a native write. */
    private function validateOwnershipCounterArguments(mixed $column, mixed $amount, mixed $extra, mixed $method): void
    {
        if (! is_string($column) || (! is_int($amount) && ! is_float($amount)) || ! is_array($extra)
            || ! in_array($method, ['increment', 'decrement'], true)) {
            throw new OwnershipViolation('Invalid native ownership counter mutation.');
        }
    }

    /** Keep deletion under the same canonical persistence boundary. */
    public function delete(): ?bool
    {
        return Container::getInstance()->make(OwnershipModelPersistence::class)->delete($this, fn (): ?bool => parent::delete());
    }

    /** Guard Laravel's scope-free queued-model restoration before its database read.
     * @param  int|string|array<array-key, int|string>  $ids
     * @return Builder<static>
     */
    public function newQueryForRestoration(mixed $ids): Builder
    {
        return OwnershipRestorationQuery::for(static::class, $this, $ids);
    }

    /** Keep untouched models unscoped until explicitly declared and adopted. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::unscoped();
    }
}
