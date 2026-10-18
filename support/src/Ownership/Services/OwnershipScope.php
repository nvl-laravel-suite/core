<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * Applies the current canonical boundary after all caller predicates are composed.
 *
 * @internal
 *
 * @implements Scope<Model>
 */
final readonly class OwnershipScope implements Scope
{
    /** @param Builder<covariant Model> $builder */
    public function apply(Builder $builder, Model $model): void
    {
        $container = Container::getInstance();
        foreach ($container->make(OwnershipResourceRegistry::class)->all() as $resource) {
            if ($resource->model === $model::class) {
                $guarded = $model->newModelQuery();
                $guarded->setQuery($builder->getQuery());
                $container->make(OwnershipBoundary::class)->query($guarded, $resource->key);

                return;
            }
        }
        $container->make(OwnershipResourceRegistry::class)->assertUnclaimed($model);
        if ($model instanceof Ownable && $model::ownershipDefinition()->strategy !== 'unscoped') {
            throw new OwnershipViolation('Declared ownership requires a registered canonical resource.');
        }
        (new PersistedOwnershipStorage)->assertUnadopted($model->getConnection(), physicalTable: $model->getTable());
    }
}
