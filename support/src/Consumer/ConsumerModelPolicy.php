<?php

declare(strict_types=1);

namespace Nvl\Support\Consumer;

/**
 * Describes the explicit in-memory permissions of a package model handle.
 *
 * @api
 */
final readonly class ConsumerModelPolicy
{
    /**
     * Retain the exact model class and its declared handle permissions.
     *
     * @param  list<string>  $readableFields
     * @param  list<string>  $identityMethods
     * @param  list<string>  $capabilityRelations
     */
    public function __construct(
        public string $class,
        public array $readableFields,
        public array $identityMethods,
        public array $capabilityRelations,
    ) {}
}
