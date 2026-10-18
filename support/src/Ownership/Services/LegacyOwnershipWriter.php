<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Retains native package transactions and legacy admission without an optional runtime. @internal */
final readonly class LegacyOwnershipWriter implements OwnershipWriter
{
    /** Resolve fresh compatibility metadata at the package persistence boundary. */
    public function __construct(private Container $container) {}

    /** @template T of Model
     * @param  class-string<T>  $modelClass
     * @param  Closure(array<string, int|string|null>): T  $persist
     * @return T
     */
    public function create(string $modelClass, Closure $persist): Model
    {
        $model = new $modelClass;
        $resource = $this->container->make(OwnershipResourceRegistry::class)->forModel($model);
        $boundary = $this->container->make(OwnershipBoundary::class);
        $attributes = $boundary->attributes($resource->key);

        return $model->getConnection()->transaction(function () use ($persist, $attributes, $boundary, $resource): Model {
            $record = $persist($attributes);
            if (! $record->exists) {
                throw new OwnershipViolation('Package persistence must return its canonical persisted model.');
            }
            $boundary->assertRecord($record, $resource->key);

            return $record;
        });
    }
}
