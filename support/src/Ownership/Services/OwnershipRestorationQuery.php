<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * Preserves the actual configured model and native eager loads during guarded restoration.
 *
 * @internal
 */
final readonly class OwnershipRestorationQuery
{
    /**
     * Select the queueable identities exactly as Laravel does, then apply the ownership boundary.
     *
     * @template TModel of Model
     *
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
        $composite = $model instanceof Pivot ? self::compositeIdentities($ids) : [];
        if ($composite === []) {
            $query->whereKey($ids);
        } else {
            $query->where(static function (Builder $pivots) use ($composite): void {
                foreach ($composite as $pairs) {
                    $pivots->orWhere(static function (Builder $pivot) use ($pairs): void {
                        foreach ($pairs as [$column, $value]) {
                            $pivot->where($column, $value);
                        }
                    });
                }
            });
        }
        $query->withGlobalScope(OwnershipScope::class, new OwnershipScope);

        return $query;
    }

    /**
     * Split a pivot's keyless queueable identities (`foreign:1:related:2`, plus the morph type and class) into column pairs.
     *
     * Like Laravel, the first identity decides: one without a colon is a primary key.
     *
     * @param  int|string|array<array-key, int|string>  $ids
     * @return list<list<array{string, string}>>
     */
    private static function compositeIdentities(mixed $ids): array
    {
        $ids = is_array($ids) ? array_values($ids) : [$ids];
        if (! is_string($ids[0] ?? null) || ! str_contains($ids[0], ':')) {
            return [];
        }
        $identities = [];
        foreach ($ids as $id) {
            $segments = is_string($id) ? explode(':', $id) : [];
            if (! in_array(count($segments), [4, 6], true)) {
                throw new OwnershipViolation('A queued pivot identity is malformed.');
            }
            $pairs = [];
            foreach (array_chunk($segments, 2) as $pair) {
                $pairs[] = [$pair[0], $pair[1]];
            }
            $identities[] = $pairs;
        }

        return $identities;
    }
}
