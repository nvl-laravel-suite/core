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
    /** @var list<string> */
    public array $parentResources;

    /**
     * Declare a resource and its immutable ownership graph.
     *
     * @param  class-string<Model>  $model
     * @param  array<array-key, string>  $parentResources
     */
    public function __construct(
        public string $key,
        public string $family,
        public string $model,
        public OwnershipDefinition $ownership,
        public ?string $parentResource = null,
        public bool $allowsCentral = false,
        public bool $requiresPartition = true,
        array $parentResources = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{0,190}$/D', $key) !== 1
            || preg_match('/^[a-z][a-z0-9-]{0,99}$/D', $family) !== 1
            || ! (new ReflectionClass($model))->isSubclassOf(Model::class) || ! (new ReflectionClass($model))->isInstantiable()
            || (($parentResource !== null || $parentResources !== []) && $ownership->strategy !== 'inherited')
            || ($parentResource !== null && $parentResources !== []) || ! array_is_list($parentResources)) {
            throw new OwnershipConfigurationInvalid('Ownership resources require canonical model, family, key and parent metadata.');
        }
        foreach ($parentResources as $parent) {
            if (preg_match('/^[a-z][a-z0-9._-]{0,190}$/D', $parent) !== 1) {
                throw new OwnershipConfigurationInvalid('Polymorphic inheritance requires explicit registered parent resources.');
            }
        }
        if (count(array_unique($parentResources)) !== count($parentResources)) {
            throw new OwnershipConfigurationInvalid('Polymorphic parent resources cannot be duplicated.');
        }
        $this->parentResources = $parentResources;
    }

    /** @return list<string> */
    public function parents(): array
    {
        return $this->parentResource === null ? $this->parentResources : [$this->parentResource];
    }
}
