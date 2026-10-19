<?php

declare(strict_types=1);

namespace Nvl\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipScope;
use ReflectionClass;
use ReflectionMethod;

/**
 * Validates advertised concrete model extensions without opening storage.
 *
 * @api
 */
final readonly class ConfiguredModelResolver
{
    /**
     * Resolve an exact configured concrete subclass of a supported base.
     *
     * @template TModel of Model
     *
     * @param  string  $configured  Deployment-owned model class
     * @param  class-string<TModel>  $base  Advertised package model
     * @return class-string<TModel>
     */
    public function resolve(string $configured, string $base): string
    {
        if (! is_a($configured, Model::class, true) || ! is_a($configured, $base, true)
            || ! (new ReflectionClass($configured))->isInstantiable()) {
            throw new OwnershipConfigurationInvalid('Configured models must be concrete subclasses of their advertised base.');
        }

        return $configured;
    }

    /**
     * Validate native UUID, cast, scope and persistence capabilities after Eloquent installs its dispatcher.
     *
     * @internal
     */
    public function assertOwnershipCapabilities(Model $model, Model $base): void
    {
        foreach ($base->getCasts() as $attribute => $cast) {
            if (($model->getCasts()[$attribute] ?? null) !== $cast) {
                throw new OwnershipConfigurationInvalid('Configured models must retain required package casts.');
            }
        }
        foreach (['save', 'delete', 'increment', 'decrement', 'incrementQuietly', 'decrementQuietly', 'incrementOrDecrement', 'newQueryForRestoration', 'bootHasOwnership', 'newQuery', 'newModelQuery', 'registerGlobalScopes', 'newEloquentBuilder', 'newInstance', 'newFromBuilder', 'getConnection'] as $method) {
            if ((new ReflectionMethod($model, $method))->getDeclaringClass()->getName() !== (new ReflectionMethod($base, $method))->getDeclaringClass()->getName()) {
                throw new OwnershipConfigurationInvalid('Configured models must retain canonical query and persistence hooks.');
            }
        }
        $scope = $model->getGlobalScope(OwnershipScope::class);
        $baseScope = $base->getGlobalScope(OwnershipScope::class);
        if (! $scope instanceof Scope || ! $baseScope instanceof Scope || $scope::class !== $baseScope::class
            || $model->getKeyName() !== $base->getKeyName() || $model->getKeyType() !== 'string' || $model->getIncrementing()) {
            throw new OwnershipConfigurationInvalid('Configured models must retain the canonical scope and native UUID identity.');
        }
    }
}
