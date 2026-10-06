<?php

declare(strict_types=1);

namespace Nvl\Support\Tenancy\Services;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Tenancy\Contracts\TenantOwnershipConfiguration;
use Nvl\Support\Tenancy\Contracts\TenantParentResolver;
use Nvl\Support\Tenancy\Enums\TenantResourceKind;
use Nvl\Support\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Support\Tenancy\ValueObjects\TenantResourceDefinition;

/** Supplies resource metadata without activating tenant ownership. */
final readonly class DisabledTenantOwnershipConfiguration implements TenantOwnershipConfiguration
{
    /** Retain the application for lazy parent allowlist resolution. */
    public function __construct(private Container $container, private TenantResourceRegistry $resources) {}

    /** Reject accidental tenant activation without the runtime. */
    public function validate(): void
    {
        $this->container->make(PersistedTenantStorage::class)->assertRuntimeDisabled();
    }

    /** Describe fixed platform resources and otherwise inactive tenant metadata. */
    public function mode(TenantResourceDefinition $resource): string
    {
        $this->validate();

        return $resource->kind === TenantResourceKind::Platform ? 'platform' : 'tenant';
    }

    /** @return array<string, class-string<Model>> */
    public function parentTypes(string $resource): array
    {
        $this->validate();
        $resolver = $this->container->make($this->resources->parentResolver($resource));
        if (! $resolver instanceof TenantParentResolver) {
            throw new TenantConfigurationInvalid('Canonical parents require the registered resolver contract.');
        }
        $types = $resolver->types();
        foreach ($types as $alias => $model) {
            if ($alias === '' || ! $this->resources->hasModel($model)) {
                throw new TenantConfigurationInvalid('Canonical parents require registered model identities.');
            }
        }

        return $types;
    }
}
