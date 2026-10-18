<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipModelGuard;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Preserves ordinary models while denying declared or persisted ownership without its runtime. @internal */
final readonly class DisabledOwnershipModelGuard implements OwnershipModelGuard
{
    /** Resolve the current compatibility services on every persistence call. */
    public function __construct(private Container $container) {}

    /** Check the current resource or the unregistered model's explicit declaration. */
    public function saving(Model $model): void
    {
        foreach ($this->container->make(OwnershipResourceRegistry::class)->all() as $resource) {
            if ($resource->model === $model::class) {
                $this->container->make(OwnershipBoundary::class)->assertRecord($model, $resource->key);

                return;
            }
        }
        $this->container->make(OwnershipResourceRegistry::class)->assertUnclaimed($model);
        if ($model instanceof Ownable && $model::ownershipDefinition()->strategy !== 'unscoped') {
            throw new OwnershipViolation('Declared ownership requires an explicitly registered enforcing resource.');
        }
        (new PersistedOwnershipStorage)->assertUnadopted($model->getConnection(), physicalTable: $model->getTable());
    }

    /** Preserve the same absent-runtime guard for deletes, including quiet deletes. */
    public function deleting(Model $model): void
    {
        $this->saving($model);
    }
}
