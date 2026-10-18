<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Preserves the actual configured model and native eager loads during guarded restoration. @internal */
final readonly class OwnershipRestorationQuery
{
    /** @template TModel of Model
     * @param  class-string<TModel>  $class
     * @param  TModel  $model
     * @param  int|string|array<array-key, int|string>  $ids
     * @return Builder<TModel>
     */
    public static function for(string $class, Model $model, mixed $ids): Builder
    {
        if ($model::class !== $class) {
            throw new OwnershipViolation('Restoration requires the exact configured native model class.');
        }
        $query = $model->newQueryWithoutScopes()->setModel($model);
        $query->whereKey($ids);
        $query->withGlobalScope(OwnershipScope::class, new OwnershipScope);

        return $query;
    }
}
