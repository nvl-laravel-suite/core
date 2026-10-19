<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/**
 * Compiles immutable native ownership facts without consulting current admission.
 *
 * @internal
 */
final readonly class OwnershipIdentityFields
{
    /**
     * Compile the immutable native ownership and parent identity fields.
     *
     * @return list<string> */
    public function forResource(OwnershipResourceDefinition $resource): array
    {
        $model = new $resource->model;
        $fields = [$model->getKeyName(), 'owner_scope_key', 'owner_reference'];
        if ($resource->ownership->foreignKey !== null) {
            $fields[] = $resource->ownership->foreignKey;
        }
        if ($resource->ownership->strategy === 'inherited') {
            $name = $resource->ownership->relation ?? throw new OwnershipConfigurationInvalid('Inherited ownership requires a native parent relation.');
            $relation = Relation::noConstraints(fn () => $model->{$name}());
            if (! $relation instanceof BelongsTo) {
                throw new OwnershipConfigurationInvalid('Inherited ownership requires a native belongs-to relation.');
            }
            $fields[] = $relation->getForeignKeyName();
            if ($relation instanceof MorphTo) {
                $fields[] = $relation->getMorphType();
            }
        }

        return array_values(array_unique([...$fields, ...$resource->immutableFields]));
    }
}
