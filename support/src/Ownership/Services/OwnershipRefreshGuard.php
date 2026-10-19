<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Guards Laravel's scope-free instance reloads without changing ordinary unadopted model semantics. @internal */
final readonly class OwnershipRefreshGuard
{
    /** Require current canonical ownership before Laravel reloads any held record or its relations. */
    public static function assertReadable(Model $model): void
    {
        if (! $model->exists) {
            return;
        }
        $container = Container::getInstance();
        $resources = $container->make(OwnershipResourceRegistry::class);
        foreach ($resources->all() as $resource) {
            if ($resource->model !== $model::class) {
                continue;
            }
            if ($resource->ownership->strategy !== 'unscoped') {
                $container->make(OwnershipBoundary::class)->assertRecord($model, $resource->key);

                return;
            }
            (new PersistedOwnershipStorage)->assertUnadopted($model->getConnection(), resource: $resource->key, physicalTable: $model->getTable());

            return;
        }
        $resources->assertUnclaimed($model);
        if ($model instanceof Ownable && $model::ownershipDefinition()->strategy !== 'unscoped') {
            throw new OwnershipViolation('Declared ownership requires a registered enforcing resource.');
        }
        (new PersistedOwnershipStorage)->assertUnadopted($model->getConnection(), physicalTable: $model->getTable());
    }
}
