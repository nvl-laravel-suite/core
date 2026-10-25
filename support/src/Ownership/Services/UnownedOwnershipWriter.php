<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Owns transactional creation of an explicitly unowned package resource. */
final readonly class UnownedOwnershipWriter implements OwnershipWriter
{
    /** Retain canonical resource and storage admission. */
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipBoundary $boundary) {}

    /**
     * Persist and verify one canonical model within its actual database transaction.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $modelClass
     * @param  Closure(array<string, int|string|null>): T  $persist
     * @return T
     */
    public function create(string $modelClass, Closure $persist): Model
    {
        $model = new $modelClass;
        $resource = $this->resources->forModel($model);
        $attributes = $this->boundary->attributes($resource->key);

        return $model->getConnection()->transaction(function () use ($persist, $attributes, $resource): Model {
            $record = $persist($attributes);
            if (! $record->exists) {
                throw new OwnershipViolation('Package persistence must return its canonical persisted model.');
            }
            $this->boundary->assertRecord($record, $resource->key);

            return $record;
        });
    }
}
