<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\OwnershipModelGuard;
use Nvl\Support\Ownership\Contracts\OwnershipModelPersistence;

/** Keeps native unowned persistence behavior while retaining absence guards. @internal */
final readonly class LegacyOwnershipModelPersistence implements OwnershipModelPersistence
{
    /** Resolve current guards without retaining scoped state in model boot metadata. */
    public function __construct(private Container $container) {}

    /** @param Closure(): bool $persist */
    public function save(Model $model, Closure $persist): bool
    {
        $this->container->make(OwnershipModelGuard::class)->saving($model);

        return $persist();
    }

    /** @param Closure(): (bool|null) $persist */
    public function delete(Model $model, Closure $persist): ?bool
    {
        $this->container->make(OwnershipModelGuard::class)->deleting($model);

        return $persist();
    }
}
