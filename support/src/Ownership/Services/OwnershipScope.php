<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
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

    /**
     * Refuse the plain framework builder, whose scope-free writes would bypass this boundary.
     *
     * Ownership-declaring models receive a guarded builder; this fails closed if that hook is lost.
     *
     * @param  Builder<covariant Model>  $builder
     */
    public function extend(Builder $builder): void
    {
        if ($builder::class === Builder::class) {
            throw new OwnershipConfigurationInvalid('Ownership models require a builder that scopes native writes.');
        }
    }

    /**
     * Apply only this boundary, after grouping caller predicates, for a scope-free native write.
     *
     * A builder whose caller explicitly removed this scope stays native.
     *
     * @param  Builder<covariant Model>  $builder
     */
    public static function bounded(Builder $builder): QueryBuilder
    {
        return (clone $builder)->withoutGlobalScopesExcept([self::class])->applyScopes()->getQuery();
    }
}
