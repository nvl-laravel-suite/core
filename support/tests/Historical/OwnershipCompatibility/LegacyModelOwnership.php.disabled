<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Compatibility;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Tenancy\Enums\TenantResourceKind;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;

/** Retains 6.x tenant stamps and exception contracts behind the native model boundary. @internal */
final readonly class LegacyModelOwnership
{
    /** Resolve current compatibility authority at the model event boundary. */
    public function __construct(private Repository $configuration, private TenantResourceRegistry $resources, private OwnershipBoundary $boundary) {}

    /** Assign a legacy stamp only through its canonical root or freshly admitted parent. */
    public function creating(Model $model, string $resource, string $label): void
    {
        if ($this->configuration->get('nvl-tenancy.enabled') !== true) {
            return;
        }
        $definition = $this->resources->get($resource);
        if ($definition->kind === TenantResourceKind::Root) {
            $model->forceFill($this->boundary->attributes($resource));

            return;
        }
        if ($definition->kind !== TenantResourceKind::Inherited || $definition->parentRelation === null) {
            return;
        }
        $relation = $model->{$definition->parentRelation}();
        if (! $relation instanceof Relation) {
            throw new TenantBoundaryViolation($label.' child ownership requires a canonical relation.');
        }
        $parent = $relation->getResults();
        if (! $parent instanceof Model) {
            throw new TenantBoundaryViolation($label.' child ownership requires a canonical parent.');
        }
        $this->boundary->assertRecord($parent, $this->resources->forModel($parent)->key);
        $tenantId = $parent->getRawOriginal('tenant_id') ?? $parent->getAttribute('tenant_id');
        if (! is_string($tenantId)) {
            throw new TenantBoundaryViolation($label.' child ownership requires a tenant parent.');
        }
        $model->forceFill(['tenant_id' => $tenantId]);
    }

    /** Preserve the legacy tenant stamp and re-admit the exact persisted record. */
    public function updating(Model $model, string $resource, string $label): void
    {
        if ($model->isDirty('tenant_id')) {
            throw new TenantBoundaryViolation('Persisted '.$label.' ownership is immutable.');
        }
        $this->boundary->assertRecord($model, $resource);
    }

    /** Reject mutations to retained package-owned legacy identity fields.
     * @param  list<string>  $attributes
     */
    public static function assertImmutable(Model $model, array $attributes, string $label): void
    {
        foreach ($attributes as $attribute) {
            if (array_key_exists($attribute, $model->getAttributes()) && $model->isDirty($attribute)) {
                throw new TenantBoundaryViolation('Persisted '.$label.' ownership is immutable.');
            }
        }
    }
}
