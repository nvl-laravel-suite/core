<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Traits;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Support\Ownership\Contracts\OwnershipModelPersistence;
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
