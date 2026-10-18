<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\ValueObjects;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use ReflectionClass;

/**
 * Retains package-owned canonical resource capabilities and effective model identity.
 *
 * @api
 */
final readonly class OwnershipResourceDefinition
{
    /**
     * Declare a resource and its immutable ownership graph.
     *
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $key,
        public string $family,
        public string $model,
        public OwnershipDefinition $ownership,
        public ?string $parentResource = null,
        public bool $allowsCentral = false,
        public bool $requiresPartition = true,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{0,190}$/D', $key) !== 1
            || preg_match('/^[a-z][a-z0-9-]{0,99}$/D', $family) !== 1
            || ! (new ReflectionClass($model))->isSubclassOf(Model::class) || ! (new ReflectionClass($model))->isInstantiable()
            || ($parentResource !== null && $ownership->strategy !== 'inherited')) {
            throw new OwnershipConfigurationInvalid('Ownership resources require canonical model, family, key and parent metadata.');
        }
    }
}
