<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/**
 * Stores immutable definitions without retaining models, builders, actors, or scope.
 *
 * @api
 */
final class OwnershipResourceRegistry
{
    /** @var array<string, OwnershipResourceDefinition> */
    private array $resources = [];

    private bool $frozen = false;

    /** Register an exact effective model and reject ambiguous or cyclic declarations. */
    public function register(OwnershipResourceDefinition $resource): void
    {
        foreach ($this->resources as $existing) {
            if ($existing->key === $resource->key || $existing->model === $resource->model) {
                if ($existing == $resource) {
                    return;
                }
                throw new OwnershipConfigurationInvalid('Ownership resource key or effective model conflicts.');
            }
        }
        if ($this->frozen) {
            throw new OwnershipConfigurationInvalid('Ownership resource metadata is frozen.');
        }
        $next = [...$this->resources, $resource->key => $resource];
        foreach ($next as $definition) {
            $visited = [];
            while ($definition !== null) {
                if (isset($visited[$definition->key])) {
                    throw new OwnershipConfigurationInvalid('Ownership resource graph contains a cycle.');
                }
                $visited[$definition->key] = true;
                $definition = $definition->parentResource === null ? null : ($next[$definition->parentResource] ?? null);
            }
        }
        $this->resources = $next;
    }

    /** Reject incomplete parent graphs before admitting resource operations. */
    public function freeze(): void
    {
        foreach ($this->resources as $resource) {
            if ($resource->parentResource !== null && ! isset($this->resources[$resource->parentResource])) {
                throw new OwnershipConfigurationInvalid('Ownership parent resource is not registered.');
            }
        }
        $this->frozen = true;
    }

    /** Resolve one explicit resource without guessing its class or ownership mode. */
    public function get(string $key): OwnershipResourceDefinition
    {
        return $this->resources[$key] ?? throw new OwnershipConfigurationInvalid('Ownership resource is not registered.');
    }

    /** Resolve only the exact configured concrete class. */
    public function forModel(Model $model): OwnershipResourceDefinition
    {
        foreach ($this->resources as $resource) {
            if ($model::class === $resource->model) {
                return $resource;
            }
        }

        throw new OwnershipConfigurationInvalid('The concrete model has no registered ownership policy.');
    }

    /** @return array<string, OwnershipResourceDefinition> */
    public function all(): array
    {
        return $this->resources;
    }
}
