<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Compatibility;

use Illuminate\Contracts\Config\Repository;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;

/** Projects legacy metadata only for the one legacy authority, without enrolling it in a new graph. @internal */
final readonly class LegacyResourceDefinitions
{
    public function __construct(private TenantResourceRegistry $resources, private Repository $configuration) {}

    /** Resolve a known legacy key without constructing untrusted model classes. */
    public function get(string $key): ?OwnershipResourceDefinition
    {
        if ($this->configuration->get('nvl-ownership.enabled') === true) {
            return null;
        }
        $legacy = $this->resources->all()[$key] ?? null;

        return $legacy === null ? null : new OwnershipResourceDefinition($legacy->key, $legacy->family, $legacy->model, OwnershipDefinition::unscoped());
    }

    /** Resolve an exact registered legacy model without weakening the new configured graph. */
    public function forModel(string $class): ?OwnershipResourceDefinition
    {
        foreach ($this->resources->all() as $resource) {
            if ($resource->model === $class) {
                return $this->get($resource->key);
            }
        }

        return null;
    }
}
